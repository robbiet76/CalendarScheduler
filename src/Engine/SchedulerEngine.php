<?php
declare(strict_types=1);

/**
 * Calendar Scheduler — Source Component
 *
 * File: Engine/SchedulerEngine.php
 * Purpose: Defines the SchedulerEngine component used by the Calendar Scheduler Engine layer.
 */

namespace CalendarScheduler\Engine;

use CalendarScheduler\Intent\IntentNormalizer;
use CalendarScheduler\Intent\NormalizationContext;
use CalendarScheduler\Adapter\Calendar\CalendarSnapshot;
use CalendarScheduler\Planner\Dto\PlannerIntent;
use CalendarScheduler\Resolution\ResolutionEngine;
use CalendarScheduler\Planner\ManifestPlanner;
use CalendarScheduler\Diff\Diff;
use CalendarScheduler\Diff\Reconciler;
use CalendarScheduler\Adapter\Calendar\Google\GoogleApiClient;
use CalendarScheduler\Adapter\Calendar\Google\GoogleConfig;
use CalendarScheduler\Adapter\Calendar\Google\GoogleCalendarTranslator;
use CalendarScheduler\Platform\FppEventTimestampStore;

/**
 * SchedulerEngine
 *
 * Executes a full scheduler planning run and returns a SchedulerRunResult.
 *
 * Responsibilities:
 * - Orchestrate ingestion, normalization, planning, diff, reconciliation
 * - Produce a single immutable SchedulerRunResult
 *
 * Non-responsibilities:
 * - No I/O
 * - No CLI flags
 * - No formatting
 * - No side effects (apply/write happen elsewhere)
 */
final class SchedulerEngine
{
    /** @var array{calendar:array<string,int>,fpp:array<string,int>} */
    private array $lastTombstonesBySource = ['calendar' => [], 'fpp' => []];

    private IntentNormalizer $normalizer;
    private ManifestPlanner $manifestPlanner;
    private Diff $diff;
    private Reconciler $reconciler;

    public function __construct(
        ?IntentNormalizer $normalizer = null,
        ?ManifestPlanner $manifestPlanner = null,
        ?Diff $diff = null,
        ?Reconciler $reconciler = null
    ) {
        $this->normalizer = $normalizer ?? new IntentNormalizer();
        $this->manifestPlanner = $manifestPlanner ?? new ManifestPlanner();
        $this->diff = $diff ?? new Diff();
        $this->reconciler = $reconciler ?? new Reconciler();
    }

    /**
     * CLI convenience wrapper.
     *
     * Keeps the bin runner thin while delegating all orchestration here.
     *
     * @param array<int,string> $argv
     * @param array<string,mixed> $opts
     */
    public function runFromCli(array $argv, array $opts): SchedulerRunResult
    {
        $runEpoch = time();

        // -----------------------------------------------------------------
        // Resolve paths
        // -----------------------------------------------------------------

        $schedulePath = $opts['schedule']
            ?? '/home/fpp/media/config/schedule.json';

        $manifestPath = $opts['manifest']
            ?? '/home/fpp/media/config/calendar-scheduler/manifest.json';
        $tombstonesPath = '/home/fpp/media/config/calendar-scheduler/runtime/tombstones.json';
        $tombstonesBySource = $this->loadTombstones($tombstonesPath);

        // -----------------------------------------------------------------
        // Build NormalizationContext (inject holidays from fpp-env.json)
        // -----------------------------------------------------------------

        $fppEnvPath = '/home/fpp/media/config/calendar-scheduler/runtime/fpp-env.json';
        $holidays = [];
        $contextTimezone = new \DateTimeZone('UTC');

        if (is_file($fppEnvPath)) {
            $fppEnvRaw = json_decode(
                file_get_contents($fppEnvPath),
                true
            );

            if (is_array($fppEnvRaw)
                && isset($fppEnvRaw['rawLocale']['holidays'])
                && is_array($fppEnvRaw['rawLocale']['holidays'])
            ) {
                $holidays = $fppEnvRaw['rawLocale']['holidays'];
            }

            $tzName = $fppEnvRaw['timezone'] ?? null;
            if (is_string($tzName) && trim($tzName) !== '') {
                try {
                    $contextTimezone = new \DateTimeZone(trim($tzName));
                } catch (\Throwable) {
                    // Keep UTC fallback when fpp-env timezone is invalid.
                }
            }
        }

        $context = new NormalizationContext(
            $contextTimezone,
            new \CalendarScheduler\Platform\FPPSemantics(),
            new \CalendarScheduler\Platform\HolidayResolver($holidays)
        );

        // -----------------------------------------------------------------
        // Calendar snapshot ingest (from file)
        // -----------------------------------------------------------------

        $calendarSnapshotPath = $opts['calendar-snapshot']
            ?? '/home/fpp/media/config/calendar-scheduler/calendar/calendar-snapshot.json';

        // -----------------------------------------------------------------
        // Calendar snapshot refresh (authoritative source)
        //
        // NOTE: This intentionally performs I/O. Stale calendar snapshot data
        // is not acceptable for apply runs.
        // -----------------------------------------------------------------

        $refreshCalendar = array_key_exists('refresh-calendar', $opts);
        $applyRequested  = array_key_exists('apply', $opts);

        if ($refreshCalendar || $applyRequested) {
            $this->refreshCalendarSnapshotFromGoogle($calendarSnapshotPath);
        }

        $calendarSnapshotRaw = [];

        if (is_file($calendarSnapshotPath)) {
            $calendarSnapshotRaw = json_decode(
                file_get_contents($calendarSnapshotPath),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        }

        $rawEvents = [];
        $calendarId = 'default';

        if (is_array($calendarSnapshotRaw) && array_key_exists('events', $calendarSnapshotRaw)) {
            $rawEvents = is_array($calendarSnapshotRaw['events'] ?? null)
                ? $calendarSnapshotRaw['events']
                : [];
            $calendarId = (string)(
                $calendarSnapshotRaw['calendar_id']
                ?? $calendarSnapshotRaw['calendarId']
                ?? 'default'
            );
        } elseif (is_array($calendarSnapshotRaw)) {
            $rawEvents = $calendarSnapshotRaw;
        }

        // CalendarSnapshot expects raw provider rows.
        $calendarEvents = $rawEvents;

        // Snapshot is treated as volatile per-run observational state
        // Always advance epoch to ensure no stale reconciliation decisions
        $calendarSnapshotEpoch = $runEpoch;

        $calendarUpdatedAtById = [];

        // -----------------------------------------------------------------
        // Ingest FPP schedule.json
        // -----------------------------------------------------------------

        $fppAdapter = new \CalendarScheduler\Adapter\FppScheduleAdapter();
        $fppEvents = $fppAdapter->loadManifestEvents($context, $schedulePath);

        $fppSnapshotEpoch = $runEpoch;

        $fppEventTimestampPath = '/home/fpp/media/config/calendar-scheduler/fpp/event-timestamps.json';
        $timestampStore = new FppEventTimestampStore();
        $fppUpdatedAtById = $timestampStore->loadUpdatedAtByIdentity($fppEventTimestampPath);
        $fppUpdatedAtByStateHash = $timestampStore->loadUpdatedAtByStateHash($fppEventTimestampPath);

        // -----------------------------------------------------------------
        // Load current manifest
        // -----------------------------------------------------------------

        $currentManifest = [];
        if (file_exists($manifestPath)) {
            $currentManifest = json_decode(
                file_get_contents($manifestPath),
                true
            ) ?? [];
        }

        // -----------------------------------------------------------------
        // Delegate to core engine
        // -----------------------------------------------------------------

        $runResult = $this->run(
            $currentManifest,
            $calendarEvents,
            $fppEvents,
            $calendarUpdatedAtById,
            $fppUpdatedAtById,
            $fppUpdatedAtByStateHash,
            $tombstonesBySource,
            $context,
            $calendarSnapshotEpoch,
            $fppSnapshotEpoch
        );

        $this->saveTombstones($tombstonesPath, $this->lastTombstonesBySource);

        return $runResult;
    }

    /**
     * Execute a scheduler run.
     *
     * @param array<string,mixed> $currentManifest
     * @param array<int,array<string,mixed>> $calendarEvents
     * @param array<int,array<string,mixed>> $fppEvents
     * @param array<string,int> $calendarUpdatedAtById
     * @param array<string,int> $fppUpdatedAtById
     * @param array<string,int> $fppUpdatedAtByStateHash
     * @param array{calendar:array<string,int>,fpp:array<string,int>} $tombstonesBySource
     */
    public function run(
        array $currentManifest,
        array $calendarEvents,
        array $fppEvents,
        array $calendarUpdatedAtById,
        array $fppUpdatedAtById,
        array $fppUpdatedAtByStateHash,
        array $tombstonesBySource,
        NormalizationContext $context,
        int $calendarSnapshotEpoch,
        int $fppSnapshotEpoch
    ): SchedulerRunResult {
        $computedCalendarUpdatedAtById = $calendarUpdatedAtById;
        $computedFppUpdatedAtById = $fppUpdatedAtById;

        // ------------------------------------------------------------
        // Calendar events → Snapshot → Resolution → PlannerIntents → Intents
        // ------------------------------------------------------------

        // CalendarSnapshot groups already-translated provider rows.
        $snapshot = new CalendarSnapshot();
        $snapshot->snapshot($calendarEvents);

        $resolver = new ResolutionEngine();
        $resolvedSchedule = $resolver->resolve($snapshot);

        $plannerIntents = $resolvedSchedule->toPlannerIntents();

        $calendarUpdatedAtByUid = [];
        foreach ($calendarEvents as $event) {
            if (!is_array($event)) {
                continue;
            }

            $uid = $event['uid'] ?? $event['sourceEventUid'] ?? null;
            if (!is_string($uid) || $uid === '') {
                continue;
            }

            $provenance = is_array($event['provenance'] ?? null) ? $event['provenance'] : [];
            $ts = $event['updatedAtEpoch']
                ?? ($provenance['updatedAtEpoch'] ?? null)
                ?? $event['sourceUpdatedAt']
                ?? 0;

            $eventTs = is_int($ts) ? $ts : (int)$ts;
            if ($eventTs <= 0) {
                continue;
            }

            if (!isset($calendarUpdatedAtByUid[$uid]) || $eventTs > $calendarUpdatedAtByUid[$uid]) {
                $calendarUpdatedAtByUid[$uid] = $eventTs;
            }

            // Overrides are grouped under the parent recurring UID for manifest
            // identity generation. Ensure parent authority timestamp reflects the
            // most recent change across both master and override rows.
            $parentUid = $event['parentUid'] ?? null;
            if (is_string($parentUid) && $parentUid !== '') {
                if (!isset($calendarUpdatedAtByUid[$parentUid]) || $eventTs > $calendarUpdatedAtByUid[$parentUid]) {
                    $calendarUpdatedAtByUid[$parentUid] = $eventTs;
                }
            }
        }

        // Resolution already produces PlannerIntent objects with correct timing.
        // At this stage (resolution-smoke-pass baseline), no further normalization
        // of planner intents is required. We simply index them by identityHash
        // for manifest building.
        $calendarIntents = [];

        // ------------------------------------------------------------
        // Group PlannerIntents by parentUid (1 manifest event per calendar event)
        // ------------------------------------------------------------
        $groupedByParent = [];

        foreach ($plannerIntents as $plannerIntent) {
            $parentUid = $plannerIntent->parentUid ?? null;

            // Fallback: derive from payload UID fields if resolution did not set parentUid
            if ($parentUid === null) {
                $payload = is_array($plannerIntent->payload ?? null)
                    ? $plannerIntent->payload
                    : [];

                $parentUid =
                    $payload['uid']
                    ?? $payload['sourceEventUid']
                    ?? $payload['id']
                    ?? null;
            }

            // Final fallback: stable synthetic UID per planner intent
            if ($parentUid === null) {
                $parentUid = 'synthetic_' . spl_object_id($plannerIntent);
            }

            if (!isset($groupedByParent[$parentUid])) {
                $groupedByParent[$parentUid] = [];
            }

            $groupedByParent[$parentUid][] = $plannerIntent;
        }

        foreach ($groupedByParent as $parentUid => $intentsForParent) {
            usort(
                $intentsForParent,
                /**
                 * Stable anchor selection: earliest scope wins, then shortest scope,
                 * then base before override. This keeps recurring identity anchored
                 * to the base segment instead of an edited override occurrence.
                 */
                static function (PlannerIntent $a, PlannerIntent $b): int {
                    $aStart = $a->scope->getStart()->getTimestamp();
                    $bStart = $b->scope->getStart()->getTimestamp();
                    if ($aStart !== $bStart) {
                        return $aStart <=> $bStart;
                    }

                    $aEnd = $a->scope->getEnd()->getTimestamp();
                    $bEnd = $b->scope->getEnd()->getTimestamp();
                    if ($aEnd !== $bEnd) {
                        return $aEnd <=> $bEnd;
                    }

                    if ($a->role !== $b->role) {
                        return ($a->role === 'base') ? -1 : 1;
                    }

                    return strcmp($a->sourceEventUid, $b->sourceEventUid);
                }
            );

            // Use first intent as identity anchor (all share same parent)
            $anchor = $intentsForParent[0];

            $payload = is_array($anchor->payload ?? null) ? $anchor->payload : [];
            $derived = $this->deriveTypeAndTargetFromPayload($payload);
            $eventType = $derived['type'];
            $eventTarget = $derived['target'];

            $subEvents = [];

            foreach ($intentsForParent as $plannerIntent) {
                $scopeStart = $plannerIntent->scope->getStart();
                $scopeEndExclusive = $plannerIntent->scope->getEnd();
                $scopeEndInclusive = $scopeEndExclusive->modify('-1 day');

                if ($scopeEndInclusive < $scopeStart) {
                    $scopeEndInclusive = $scopeStart;
                }

                $payload = is_array($plannerIntent->payload ?? null)
                    ? $plannerIntent->payload
                    : [];

                // Scheduler settings come from reconciled metadata only.
                $settings = $this->extractSchedulerSettingsFromPayload($payload);

                // Behavior extraction from settings
                $enabled = true;
                if (isset($settings['enabled'])) {
                    $enabled = $this->settingToBool($settings['enabled'], true);
                }

                $repeat = $settings['repeat'] ?? null;
                $stopType = $settings['stoptype'] ?? null;

                // Symbolic time extraction from reconciled metadata settings.
                $startSymbolic = isset($settings['start']) && is_string($settings['start'])
                    ? trim($settings['start'])
                    : null;
                $endSymbolic = isset($settings['end']) && is_string($settings['end'])
                    ? trim($settings['end'])
                    : null;

                $subEvents[] = [
                    'type'   => $eventType,
                    'target' => $eventTarget,
                    'timing' => [
                        'all_day'    => $plannerIntent->allDay,
                        'start_date' => [
                            'hard'     => $scopeStart->format('Y-m-d'),
                            'symbolic' => null,
                        ],
                        'end_date'   => [
                            'hard'     => $scopeEndInclusive->format('Y-m-d'),
                            'symbolic' => null,
                        ],
                        // Symbolic time invalidates hard time.
                        // Hard time is resolution-only and must not leak into manifest identity.
                        'start_time' => ($startSymbolic !== null && $startSymbolic !== '')
                            ? [
                                'hard'     => null,
                                'symbolic' => $startSymbolic,
                                'offset'   => isset($settings['start_offset'])
                                    ? (int)$settings['start_offset']
                                    : 0,
                            ]
                            : [
                                'hard'     => $plannerIntent->start->format('H:i:s'),
                                'symbolic' => null,
                                'offset'   => 0,
                            ],
                        'end_time' => ($endSymbolic !== null && $endSymbolic !== '')
                            ? [
                                'hard'     => null,
                                'symbolic' => $endSymbolic,
                                'offset'   => isset($settings['end_offset'])
                                    ? (int)$settings['end_offset']
                                    : 0,
                            ]
                            : [
                                'hard'     => $plannerIntent->end->format('H:i:s'),
                                'symbolic' => null,
                                'offset'   => 0,
                            ],
                        'days' => (
                            is_array($plannerIntent->weeklyDays ?? null)
                            && $plannerIntent->weeklyDays !== []
                        )
                            ? [
                                'type'  => 'weekly',
                                'value' => array_values($plannerIntent->weeklyDays),
                            ]
                            : null,
                        'timezone' => (
                            is_string($plannerIntent->timezone ?? null)
                            && trim((string)$plannerIntent->timezone) !== ''
                        )
                            ? trim((string)$plannerIntent->timezone)
                            : $context->timezone->getName(),
                    ],
                    'payload' => array_merge(
                        $payload,
                        [
                            'enabled'  => $enabled,
                            'repeat'   => $repeat ?? 'none',
                            'stopType' => $stopType ?? 'graceful',
                        ]
                    ),
                ];
            }

            // ------------------------------------------------------------
            // Build a single manifest event and normalize once
            // ------------------------------------------------------------
            $manifestEvent = [
                // Canonical manifest identity (flat structure expected by IntentNormalizer)
                'type'   => $eventType,
                'target' => $eventTarget,
                'timing' => $subEvents[0]['timing'],

                // Top-level payload (anchor payload required by normalization)
                'payload' => $payload,

                // Full state (expanded occurrences)
                'subEvents' => $subEvents,

                // Ownership / correlation metadata
                'ownership' => ['managed' => true],
                'correlation' => [
                    'sourceEventUid' => $parentUid,
                ],

                'source' => 'calendar',
            ];

            $normalizedIntent = $this->normalizer->fromManifestEvent(
                $manifestEvent,
                $context
            );

            $calendarIntents[$normalizedIntent->identityHash] = $normalizedIntent;
            $computedCalendarUpdatedAtById[$normalizedIntent->identityHash] =
                $calendarUpdatedAtByUid[$parentUid]
                ?? $calendarSnapshotEpoch;
        }

        // ------------------------------------------------------------
        // Normalize FPP events → Intents
        // ------------------------------------------------------------
        $fppIntents = [];
        foreach ($fppEvents as $event) {
            $intent = $this->normalizer->fromManifestEvent($event, $context);
            $hash = $intent->identityHash;

            $fppIntents[$hash] = $intent;

            if (!isset($computedFppUpdatedAtById[$hash]) || $computedFppUpdatedAtById[$hash] <= 0) {
                $eventStateHash = $intent->eventStateHash;
                if (
                    is_string($eventStateHash)
                    && $eventStateHash !== ''
                    && isset($fppUpdatedAtByStateHash[$eventStateHash])
                    && $fppUpdatedAtByStateHash[$eventStateHash] > 0
                ) {
                    $computedFppUpdatedAtById[$hash] = $fppUpdatedAtByStateHash[$eventStateHash];
                    continue;
                }
            }

            if (!isset($computedFppUpdatedAtById[$hash]) || $computedFppUpdatedAtById[$hash] <= 0) {
                $ts = $event['updatedAtEpoch'] ?? $event['sourceUpdatedAt'] ?? 0;
                $computedFppUpdatedAtById[$hash] = is_int($ts) ? $ts : (int)$ts;
            }
        }

        // ------------------------------------------------------------
        // Build manifests
        // ------------------------------------------------------------
        $calendarManifest = $this->manifestPlanner
            ->buildManifestFromIntents($calendarIntents);

        $fppManifest = $this->manifestPlanner
            ->buildManifestFromIntents($fppIntents);

        $effectiveTombstonesBySource = $this->deriveEffectiveTombstones(
            $currentManifest,
            $calendarManifest,
            $fppManifest,
            $tombstonesBySource,
            $calendarSnapshotEpoch
        );
        $this->lastTombstonesBySource = $effectiveTombstonesBySource;

        // ------------------------------------------------------------
        // Diff (calendar desired vs current)
        // ------------------------------------------------------------
        $diffResult = $this->diff->diff(
            $calendarManifest,
            $currentManifest
        );

        // ------------------------------------------------------------
        // Reconcile (calendar vs fpp vs current)
        // ------------------------------------------------------------
        $reconciliationResult = $this->reconciler->reconcile(
            $calendarManifest,
            $fppManifest,
            $currentManifest,
            $computedCalendarUpdatedAtById,
            $computedFppUpdatedAtById,
            $effectiveTombstonesBySource,
            $calendarSnapshotEpoch,
            $fppSnapshotEpoch
        );

        // ------------------------------------------------------------
        // Produce canonical run result
        // ------------------------------------------------------------
        return new SchedulerRunResult(
            $currentManifest,
            $calendarManifest,
            $fppManifest,
            $diffResult,
            $reconciliationResult,
            $calendarSnapshotEpoch,
            $fppSnapshotEpoch
        );
    }

    /**
     * @param array<string,mixed> $currentManifest
     * @param array<string,mixed> $calendarManifest
     * @param array<string,mixed> $fppManifest
     * @param array{calendar:array<string,int>,fpp:array<string,int>} $tombstonesBySource
     * @param int $calendarEpoch
     * @return array{calendar:array<string,int>,fpp:array<string,int>}
     */
    private function deriveEffectiveTombstones(
        array $currentManifest,
        array $calendarManifest,
        array $fppManifest,
        array $tombstonesBySource,
        int $calendarEpoch
    ): array {
        $calendarIds = $this->manifestIdentitySet($calendarManifest);
        $fppIds = $this->manifestIdentitySet($fppManifest);
        $currentEvents = is_array($currentManifest['events'] ?? null) ? $currentManifest['events'] : [];

        // Calendar tombstones: identities that existed in current manifest and are now
        // absent from the refreshed calendar manifest should be treated as deleted by
        // calendar, not recreated from FPP on the next reconcile pass.
        foreach ($currentEvents as $id => $event) {
            if (!is_array($event)) {
                continue;
            }
            $identityId = null;
            if (is_string($id) && $id !== '') {
                $identityId = $id;
            } else {
                $eventId = $event['identityHash'] ?? $event['id'] ?? null;
                if (is_string($eventId) && $eventId !== '') {
                    $identityId = $eventId;
                }
            }
            if (!is_string($identityId) || $identityId === '') {
                continue;
            }
            if (isset($calendarIds[$identityId])) {
                continue;
            }
            if (!isset($fppIds[$identityId])) {
                continue;
            }

            if (!isset($tombstonesBySource['calendar'][$identityId])) {
                $tombstonesBySource['calendar'][$identityId] = $calendarEpoch;
            }
        }

        // Verified convergence: remove tombstones once both sources are absent.
        $allIds = array_unique(array_merge(
            array_keys($tombstonesBySource['calendar']),
            array_keys($tombstonesBySource['fpp'])
        ));
        foreach ($allIds as $id) {
            if (!isset($calendarIds[$id]) && !isset($fppIds[$id])) {
                unset($tombstonesBySource['calendar'][$id], $tombstonesBySource['fpp'][$id]);
            }
        }

        ksort($tombstonesBySource['calendar'], SORT_STRING);
        ksort($tombstonesBySource['fpp'], SORT_STRING);

        return $tombstonesBySource;
    }

    /**
     * @param array<string,mixed> $manifest
     * @return array<string,true>
     */
    private function manifestIdentitySet(array $manifest): array
    {
        $out = [];
        $events = $manifest['events'] ?? [];
        if (!is_array($events)) {
            return $out;
        }

        foreach ($events as $eventKey => $event) {
            if (is_string($eventKey) && $eventKey !== '') {
                $out[$eventKey] = true;
                continue;
            }
            if (!is_array($event)) {
                continue;
            }
            $id = $event['identityHash'] ?? $event['id'] ?? null;
            if (is_string($id) && $id !== '') {
                $out[$id] = true;
            }
        }

        return $out;
    }

    /**
     * @param array{calendar:array<string,int>,fpp:array<string,int>} $tombstonesBySource
     */
    private function saveTombstones(string $path, array $tombstonesBySource): void
    {
        $doc = [
            'version' => 1,
            'generatedAtEpoch' => time(),
            'sources' => $tombstonesBySource,
        ];
        $json = json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Unable to create tombstone directory: {$dir}");
        }
        $tmp = $path . '.tmp';
        if (@file_put_contents($tmp, $json . PHP_EOL) === false) {
            throw new \RuntimeException("Unable to write tombstones temp file: {$tmp}");
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException("Unable to replace tombstones file: {$path}");
        }
    }

    /**
     * @return array{calendar:array<string,int>,fpp:array<string,int>}
     */
    private function loadTombstones(string $path): array
    {
        $empty = ['calendar' => [], 'fpp' => []];
        if (!is_file($path)) {
            return $empty;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return $empty;
        }
        $decoded = @json_decode($raw, true);
        if (!is_array($decoded)) {
            return $empty;
        }
        $sources = is_array($decoded['sources'] ?? null) ? $decoded['sources'] : [];
        $out = ['calendar' => [], 'fpp' => []];
        foreach (['calendar', 'fpp'] as $source) {
            $rows = is_array($sources[$source] ?? null) ? $sources[$source] : [];
            foreach ($rows as $id => $ts) {
                if (!is_string($id) || $id === '' || !is_numeric($ts)) {
                    continue;
                }
                $n = (int)$ts;
                if ($n > 0) {
                    $out[$source][$id] = $n;
                }
            }
        }
        return $out;
    }

    /**
     * Best-effort extraction of scheduling identity (type/target) from a provider payload.
     *
     * Current contract:
     * - `type` is one of: playlist | sequence | command
     * - `target` is a non-empty string identifier (typically derived from summary)
     *
     * For Google rows, settings come from reconciled metadata.
     *
     * @param array<string,mixed> $payload
     * @return array{type:string,target:string}
     */
    private function deriveTypeAndTargetFromPayload(array $payload): array
    {
        $type = null;
        $target = null;
        $settings = $this->extractSchedulerSettingsFromPayload($payload);

        // Preferred type can still be supplied explicitly.
        if (isset($payload['type']) && is_string($payload['type']) && trim($payload['type']) !== '') {
            $type = strtolower(trim($payload['type']));
        }

        if ($type === null && isset($settings['type']) && is_string($settings['type'])) {
            $t = strtolower(trim($settings['type']));
            if ($t !== '') {
                $type = $t;
            }
        }
        // Calendar summary/title is authoritative for target.
        $summary = isset($payload['summary']) && is_string($payload['summary']) ? trim($payload['summary']) : '';
        if ($summary !== '') {
            $target = $summary;
        }

        // Final defaults.
        if ($type === null) {
            $type = 'playlist';
        }
        if ($target === null || $target === '') {
            // Never allow null/empty target; adapter/diff rely on this.
            $target = 'unknown';
        }

        // Clamp allowed types (ignore monthly support does not affect identity typing).
        if (!in_array($type, ['playlist', 'sequence', 'command'], true)) {
            $type = 'playlist';
        }

        return ['type' => $type, 'target' => $target];
    }

    /**
     * Read scheduler settings from structured metadata when available.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function extractSchedulerSettingsFromPayload(array $payload): array
    {
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        $settings = is_array($metadata['settings'] ?? null) ? $metadata['settings'] : [];

        $out = [];
        foreach ($settings as $k => $v) {
            if (!is_string($k) || $k === '') {
                continue;
            }

            $nk = strtolower(trim($k));
            $out[$nk] = $v;
        }

        return $out;
    }

    private function settingToBool(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        if (is_string($value)) {
            $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if (is_bool($parsed)) {
                return $parsed;
            }
        }

        return $default;
    }

    /**
     * Refresh the calendar snapshot by pulling directly from Google Calendar API.
     *
     * Writes a deterministic JSON wrapper to $calendarSnapshotPath:
     *   { "calendar_id": "...", "events": [ ...provider-agnostic rows... ] }
     *
     * @throws \RuntimeException on any failure.
     */
    private function refreshCalendarSnapshotFromGoogle(
        string $calendarSnapshotPath
    ): void {
        $configDir = '/home/fpp/media/config/calendar-scheduler/calendar/google';
        $config = new GoogleConfig($configDir);

        $client = new GoogleApiClient($config);
        $rawEvents = $client->listEvents($config->getCalendarId());

        // Translate provider-specific events into snapshot rows
        $translator = new GoogleCalendarTranslator();
        $translatedEvents = $translator->ingest($rawEvents, $config->getCalendarId());

        $payload = [
            'calendar_id'  => $config->getCalendarId(),
            'events'       => $translatedEvents,
            'generated_at' => (new \DateTimeImmutable(
                'now',
                new \DateTimeZone('UTC')
            ))->format(DATE_ATOM),
        ];

        $json = json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        $dir = dirname($calendarSnapshotPath);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException("Failed to create snapshot directory: {$dir}");
            }
        }

        $tmp = $calendarSnapshotPath . '.tmp';
        if (file_put_contents($tmp, $json . PHP_EOL) === false) {
            throw new \RuntimeException("Failed to write temp calendar snapshot: {$tmp}");
        }

        if (!rename($tmp, $calendarSnapshotPath)) {
            @unlink($tmp);
            throw new \RuntimeException("Failed to replace calendar snapshot: {$calendarSnapshotPath}");
        }
    }
}
