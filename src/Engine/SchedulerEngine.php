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
use CalendarScheduler\Platform\FPPSemantics;
use CalendarScheduler\Platform\HolidayResolver;
use CalendarScheduler\Platform\SunTimeDisplayEstimator;

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
    public const SYNC_MODE_BOTH = Reconciler::MODE_BOTH;
    public const SYNC_MODE_CALENDAR = Reconciler::MODE_CALENDAR;
    public const SYNC_MODE_FPP = Reconciler::MODE_FPP;

    /** @var array{calendar:array<string,int>,fpp:array<string,int>} */
    private array $lastTombstonesBySource = ['calendar' => [], 'fpp' => []];
    /** @var array{calendar:array<string,int>,fpp:array<string,int>} */
    private array $loadedTombstonesBySource = ['calendar' => [], 'fpp' => []];
    private ?float $orderingLatitude = null;
    private ?float $orderingLongitude = null;
    private string $orderingTimezone = 'UTC';
    /** @var array<string,int|null> */
    private array $symbolicDisplaySecondsCache = [];

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
        $syncMode = $this->normalizeSyncMode($opts['sync-mode'] ?? $opts['sync_mode'] ?? null);

        // -----------------------------------------------------------------
        // Resolve paths
        // -----------------------------------------------------------------

        $schedulePath = $opts['schedule']
            ?? '/home/fpp/media/config/schedule.json';

        $manifestPath = $opts['manifest']
            ?? '/home/fpp/media/config/calendar-scheduler/manifest.json';
        $tombstonesPath = '/home/fpp/media/config/calendar-scheduler/runtime/tombstones.json';
        $calendarScope = 'default';
        $tombstonesBySource = $this->loadTombstones($tombstonesPath, $calendarScope);

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

        // Symbolic ordering heuristics use the same environment context as FPP.
        $this->orderingLatitude = $this->optionalFloat($fppEnvRaw['latitude'] ?? null);
        $this->orderingLongitude = $this->optionalFloat($fppEnvRaw['longitude'] ?? null);
        $this->orderingTimezone = $contextTimezone->getName();
        $this->symbolicDisplaySecondsCache = [];

        $context = new NormalizationContext(
            $contextTimezone,
            new FPPSemantics(),
            new HolidayResolver($holidays)
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
        if (!is_string($calendarId) || trim($calendarId) === '') {
            $calendarId = 'default';
        } else {
            $calendarId = trim($calendarId);
        }

        // Re-scope calendar tombstones after calendar_id is known from snapshot.
        $tombstonesBySource = $this->loadTombstones($tombstonesPath, $calendarId);

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
            $fppSnapshotEpoch,
            $syncMode,
            $calendarId
        );

        $this->saveTombstones($tombstonesPath, $this->lastTombstonesBySource, $calendarId);

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
        int $fppSnapshotEpoch,
        string $syncMode = self::SYNC_MODE_BOTH,
        string $calendarScope = 'default'
    ): SchedulerRunResult {
        $syncMode = $this->normalizeSyncMode($syncMode);
        $calendarScope = trim($calendarScope) !== '' ? trim($calendarScope) : 'default';
        $this->orderingTimezone = $context->timezone->getName();
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
            $payload = is_array($plannerIntent->payload ?? null)
                ? $plannerIntent->payload
                : [];

            // If provider payload includes an explicit manifest event linkage,
            // prefer it over provider UIDs so independently-created calendar events
            // can be grouped back into one manifest event.
            $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
            $manifestEventId = $metadata['manifestEventId'] ?? null;
            if (is_string($manifestEventId) && trim($manifestEventId) !== '') {
                $parentUid = trim($manifestEventId);
            }

            // Fallback: derive from payload UID fields if resolution did not set parentUid
            if ($parentUid === null) {
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
        $globalExecutionRanks = $this->computeExecutionOrderRanks($plannerIntents);

        foreach ($groupedByParent as $parentUid => $intentsForParent) {
            $anchorIntents = $intentsForParent;
            usort(
                $anchorIntents,
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

            // Split one calendar parent bundle into per-target buckets so target
            // overrides become distinct FPP entries instead of inheriting the base target.
            $bucketedByTarget = [];
            foreach ($anchorIntents as $plannerIntent) {
                $plannerPayload = is_array($plannerIntent->payload ?? null)
                    ? $plannerIntent->payload
                    : [];
                $derived = $this->deriveTypeAndTargetFromPayload($plannerPayload);
                $bucketKey = $derived['type'] . '|' . $derived['target'];
                if (!isset($bucketedByTarget[$bucketKey])) {
                    $bucketedByTarget[$bucketKey] = [
                        'type' => $derived['type'],
                        'target' => $derived['target'],
                        'intents' => [],
                    ];
                }
                $bucketedByTarget[$bucketKey]['intents'][] = $plannerIntent;
            }

            foreach ($bucketedByTarget as $bucket) {
                $eventType = (string)($bucket['type'] ?? 'playlist');
                $eventTarget = (string)($bucket['target'] ?? 'unknown');
                $bucketIntents = is_array($bucket['intents'] ?? null) ? $bucket['intents'] : [];
                if ($bucketIntents === []) {
                    continue;
                }

                $anchor = $bucketIntents[0];
                $anchorPayload = is_array($anchor->payload ?? null) ? $anchor->payload : [];
                $subEvents = [];
                $orderedBucketIntents = $bucketIntents;
                usort($orderedBucketIntents, function (PlannerIntent $a, PlannerIntent $b) use ($globalExecutionRanks): int {
                    $aRank = $globalExecutionRanks[spl_object_id($a)] ?? PHP_INT_MAX;
                    $bRank = $globalExecutionRanks[spl_object_id($b)] ?? PHP_INT_MAX;
                    if ($aRank !== $bRank) {
                        return $aRank <=> $bRank;
                    }
                    return $this->comparePlannerIntentOrder($a, $b);
                });

                foreach ($orderedBucketIntents as $plannerIntent) {
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
                    $startSetting = isset($settings['start']) && is_string($settings['start'])
                        ? trim((string)$settings['start'])
                        : null;
                    $endSetting = isset($settings['end']) && is_string($settings['end'])
                        ? trim((string)$settings['end'])
                        : null;

                    $startSymbolic = null;
                    $endSymbolic = null;
                    $startHardOverride = null;
                    $endHardOverride = null;

                    if (is_string($startSetting) && $startSetting !== '') {
                        $normalized = FPPSemantics::normalizeSymbolicTimeToken($startSetting);
                        if (is_string($normalized) && FPPSemantics::isSymbolicTime($normalized)) {
                            $startSymbolic = $normalized;
                        } elseif (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $startSetting) === 1) {
                            $startHardOverride = strlen($startSetting) === 5
                                ? $startSetting . ':00'
                                : $startSetting;
                        }
                    }
                    if (is_string($endSetting) && $endSetting !== '') {
                        $normalized = FPPSemantics::normalizeSymbolicTimeToken($endSetting);
                        if (is_string($normalized) && FPPSemantics::isSymbolicTime($normalized)) {
                            $endSymbolic = $normalized;
                        } elseif (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $endSetting) === 1) {
                            $endHardOverride = strlen($endSetting) === 5
                                ? $endSetting . ':00'
                                : $endSetting;
                        }
                    }

                    $subEvents[] = [
                        'type'   => $eventType,
                        'target' => $eventTarget,
                        'executionOrder' => $globalExecutionRanks[spl_object_id($plannerIntent)] ?? 0,
                        'executionOrderManual' => $this->extractExecutionOrderManualFromPayload($payload),
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
                                : (($startHardOverride !== null && $startHardOverride !== '')
                                    ? [
                                        'hard'     => $startHardOverride,
                                        'symbolic' => null,
                                        'offset'   => 0,
                                    ]
                                : [
                                    'hard'     => $plannerIntent->start->format('H:i:s'),
                                    'symbolic' => null,
                                    'offset'   => 0,
                                ]),
                            'end_time' => ($endSymbolic !== null && $endSymbolic !== '')
                                ? [
                                    'hard'     => null,
                                    'symbolic' => $endSymbolic,
                                    'offset'   => isset($settings['end_offset'])
                                        ? (int)$settings['end_offset']
                                        : 0,
                                ]
                                : (($endHardOverride !== null && $endHardOverride !== '')
                                    ? [
                                        'hard'     => $endHardOverride,
                                        'symbolic' => null,
                                        'offset'   => 0,
                                    ]
                                : [
                                    'hard'     => $plannerIntent->end->format('H:i:s'),
                                    'symbolic' => null,
                                    'offset'   => 0,
                                ]),
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

                if ($subEvents === []) {
                    continue;
                }

                // ------------------------------------------------------------
                // Build one manifest event per target bucket and normalize once
                // ------------------------------------------------------------
                $manifestEvent = [
                    // Canonical manifest identity (flat structure expected by IntentNormalizer)
                    'type'   => $eventType,
                    'target' => $eventTarget,
                    'timing' => $this->selectIdentityTimingFromSubEvents($subEvents),

                    // Top-level payload (anchor payload required by normalization)
                    'payload' => $anchorPayload,

                    // Full state (expanded occurrences)
                    'subEvents' => $subEvents,

                    // Ownership / correlation metadata
                    'ownership' => ['managed' => true],
                    'correlation' => [
                        'sourceEventUid' => $parentUid,
                        'sourceCalendarId' => $calendarScope,
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

        $effectiveTombstonesBySource = $tombstonesBySource;
        if ($syncMode === self::SYNC_MODE_BOTH) {
            $effectiveTombstonesBySource = $this->deriveEffectiveTombstones(
                $currentManifest,
                $calendarManifest,
                $fppManifest,
                $tombstonesBySource,
                $calendarSnapshotEpoch,
                $calendarScope
            );
        }
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
            $fppSnapshotEpoch,
            $syncMode,
            $calendarScope
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
     * @param string $calendarScope
     * @return array{calendar:array<string,int>,fpp:array<string,int>}
     */
    private function deriveEffectiveTombstones(
        array $currentManifest,
        array $calendarManifest,
        array $fppManifest,
        array $tombstonesBySource,
        int $calendarEpoch,
        string $calendarScope
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

            // Only infer a calendar tombstone from events that were actually
            // calendar-originated in the current manifest. This prevents
            // unrelated/manual FPP entries from being interpreted as calendar deletes.
            $source = $event['source'] ?? null;
            if (!is_string($source) || strtolower(trim($source)) !== 'calendar') {
                continue;
            }

            // Only infer calendar deletes for events tied to the currently selected
            // calendar. This prevents cross-calendar leakage when users switch calendars.
            $correlation = is_array($event['correlation'] ?? null) ? $event['correlation'] : [];
            $sourceCalendarId = $correlation['sourceCalendarId'] ?? null;
            if (!is_string($sourceCalendarId) || trim($sourceCalendarId) === '') {
                continue;
            }
            if (trim($sourceCalendarId) !== $calendarScope) {
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
    private function saveTombstones(string $path, array $tombstonesBySource, string $calendarScope): void
    {
        // Keep FPP tombstones global, but namespace calendar tombstones by selected calendar.
        $calendarScope = trim($calendarScope) !== '' ? trim($calendarScope) : 'default';
        $merged = $this->loadedTombstonesBySource;
        if (!isset($merged['calendar']) || !is_array($merged['calendar'])) {
            $merged['calendar'] = [];
        }
        if (!isset($merged['fpp']) || !is_array($merged['fpp'])) {
            $merged['fpp'] = [];
        }

        $prefix = $calendarScope . '::';
        foreach (array_keys($merged['calendar']) as $rawKey) {
            if (is_string($rawKey) && strpos($rawKey, $prefix) === 0) {
                unset($merged['calendar'][$rawKey]);
            }
        }

        foreach ($tombstonesBySource['calendar'] as $id => $ts) {
            if (!is_string($id) || $id === '' || !is_int($ts) || $ts <= 0) {
                continue;
            }
            $merged['calendar'][$prefix . $id] = $ts;
        }

        $merged['fpp'] = $tombstonesBySource['fpp'];

        $doc = [
            'version' => 1,
            'generatedAtEpoch' => time(),
            'sources' => $merged,
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
    private function loadTombstones(string $path, string $calendarScope): array
    {
        $empty = ['calendar' => [], 'fpp' => []];
        $calendarScope = trim($calendarScope) !== '' ? trim($calendarScope) : 'default';
        if (!is_file($path)) {
            $this->loadedTombstonesBySource = $empty;
            return $empty;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            $this->loadedTombstonesBySource = $empty;
            return $empty;
        }
        $decoded = @json_decode($raw, true);
        if (!is_array($decoded)) {
            $this->loadedTombstonesBySource = $empty;
            return $empty;
        }
        $sources = is_array($decoded['sources'] ?? null) ? $decoded['sources'] : [];
        $rawOut = ['calendar' => [], 'fpp' => []];
        foreach (['calendar', 'fpp'] as $source) {
            $rows = is_array($sources[$source] ?? null) ? $sources[$source] : [];
            foreach ($rows as $rawKey => $ts) {
                if (!is_string($rawKey) || $rawKey === '' || !is_numeric($ts)) {
                    continue;
                }
                $n = (int)$ts;
                if ($n > 0) {
                    $rawOut[$source][$rawKey] = $n;
                }
            }
        }

        $this->loadedTombstonesBySource = $rawOut;

        $out = ['calendar' => [], 'fpp' => $rawOut['fpp']];
        $prefix = $calendarScope . '::';
        foreach ($rawOut['calendar'] as $rawKey => $ts) {
            if (!is_string($rawKey)) {
                continue;
            }
            if (strpos($rawKey, $prefix) !== 0) {
                continue;
            }
            $identity = substr($rawKey, strlen($prefix));
            if (!is_string($identity) || $identity === '') {
                continue;
            }
            $out['calendar'][$identity] = $ts;
        }

        return $out;
    }

    /**
     * Compute deterministic execution order ranks for planner intents.
     *
     * Lower rank = higher precedence (earlier FPP row).
     *
     * @param array<int,PlannerIntent> $intents
     * @return array<int,int> map of spl_object_id(PlannerIntent) => rank
     */
    private function computeExecutionOrderRanks(array $intents): array
    {
        if ($intents === []) {
            return [];
        }

        $ranks = [];
        $used = [];
        $missing = $intents;

        // Canonical ordering is always enforced. Explicit/manual order metadata is
        // intentionally ignored so scheduler execution stays deterministic.

        // Treat each bundle as an atomic block in global ordering.
        // Rows are sorted inside each bundle first; bundle blocks are then
        // ordered using baseline chronology plus overlap-aware precedence.
        $bundleGroups = [];
        foreach ($missing as $intent) {
            $bundleKey = trim((string)$intent->bundleUid);
            if ($bundleKey === '') {
                $bundleKey = '__single__' . spl_object_id($intent);
            }
            if (!isset($bundleGroups[$bundleKey])) {
                $bundleGroups[$bundleKey] = [];
            }
            $bundleGroups[$bundleKey][] = $intent;
        }

        foreach ($bundleGroups as &$bundleIntents) {
            usort($bundleIntents, function (PlannerIntent $a, PlannerIntent $b): int {
                return $this->comparePlannerIntentOrder($a, $b);
            });
        }
        unset($bundleIntents);

        $bundleKeys = array_keys($bundleGroups);
        $bundleKeys = $this->orderBundlesWithConstraints($bundleKeys, $bundleGroups);

        $orderedMissing = [];
        foreach ($bundleKeys as $bundleKey) {
            foreach ($bundleGroups[$bundleKey] as $intent) {
                $orderedMissing[] = $intent;
            }
        }

        $next = 0;
        foreach ($orderedMissing as $intent) {
            while (isset($used[$next])) {
                $next++;
            }
            $ranks[spl_object_id($intent)] = $next;
            $used[$next] = true;
            $next++;
        }

        return $ranks;
    }

    /**
     * Constrained bundle ordering:
     * - Hard precedence edges from overlap semantics
     * - Topological sort
     * - Readability tie-breaks only among currently legal candidates
     *
     * @param array<int,string> $bundleKeys
     * @param array<string,array<int,PlannerIntent>> $bundleGroups
     * @return array<int,string>
     */
    private function orderBundlesWithConstraints(array $bundleKeys, array $bundleGroups): array
    {
        $hardEdges = $this->buildHardBundlePrecedenceEdges($bundleKeys, $bundleGroups);
        $adjacency = [];
        $inDegree = [];

        foreach ($bundleKeys as $key) {
            $adjacency[$key] = [];
            $inDegree[$key] = 0;
        }

        foreach ($hardEdges as $edge) {
            $from = $edge['from'];
            $to = $edge['to'];
            if (!isset($adjacency[$from]) || !isset($inDegree[$to])) {
                continue;
            }
            if (isset($adjacency[$from][$to])) {
                continue;
            }

            $adjacency[$from][$to] = true;
            $inDegree[$to]++;
        }

        $available = [];
        foreach ($bundleKeys as $key) {
            if (($inDegree[$key] ?? 0) === 0) {
                $available[] = $key;
            }
        }

        $ordered = [];
        $remaining = array_fill_keys($bundleKeys, true);
        $lastGroupKey = null;

        while ($remaining !== []) {
            if ($available === []) {
                // Cycle safety fallback: choose deterministic chronological minimum.
                $candidates = array_keys($remaining);
                usort($candidates, function (string $a, string $b) use ($bundleGroups): int {
                    return $this->compareBundleChronology(
                        $bundleGroups[$a],
                        $bundleGroups[$b],
                        $a,
                        $b
                    );
                });
                $available[] = $candidates[0];
            }

            usort($available, function (string $a, string $b) use ($bundleGroups, $lastGroupKey): int {
                if ($lastGroupKey !== null && $lastGroupKey !== '') {
                    $aGroup = $this->bundleReadabilityGroupKey($bundleGroups[$a]);
                    $bGroup = $this->bundleReadabilityGroupKey($bundleGroups[$b]);
                    $aSame = $aGroup === $lastGroupKey;
                    $bSame = $bGroup === $lastGroupKey;
                    if ($aSame !== $bSame) {
                        return $aSame ? -1 : 1;
                    }
                }

                $cmp = $this->compareBundleChronology(
                    $bundleGroups[$a],
                    $bundleGroups[$b],
                    $a,
                    $b
                );
                if ($cmp !== 0) {
                    return $cmp;
                }

                $aGroup = $this->bundleReadabilityGroupKey($bundleGroups[$a]);
                $bGroup = $this->bundleReadabilityGroupKey($bundleGroups[$b]);
                if ($aGroup !== $bGroup) {
                    return strcmp($aGroup, $bGroup);
                }

                return strcmp($a, $b);
            });

            $pick = array_shift($available);
            if (!is_string($pick) || !isset($remaining[$pick])) {
                continue;
            }

            $ordered[] = $pick;
            unset($remaining[$pick]);
            $lastGroupKey = $this->bundleReadabilityGroupKey($bundleGroups[$pick]);

            foreach (array_keys($adjacency[$pick] ?? []) as $to) {
                if (!isset($inDegree[$to])) {
                    continue;
                }
                $inDegree[$to]--;
                if ($inDegree[$to] === 0 && isset($remaining[$to])) {
                    $available[] = $to;
                }
            }

            // Deduplicate available list while preserving legality.
            $seen = [];
            $dedup = [];
            foreach ($available as $key) {
                if (!is_string($key) || isset($seen[$key]) || !isset($remaining[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $dedup[] = $key;
            }
            $available = $dedup;
        }

        return $ordered;
    }

    /**
     * Build hard precedence edges for overlapping bundles only.
     *
     * @param array<int,string> $bundleKeys
     * @param array<string,array<int,PlannerIntent>> $bundleGroups
     * @return array<int,array{from:string,to:string}>
     */
    private function buildHardBundlePrecedenceEdges(array $bundleKeys, array $bundleGroups): array
    {
        $edges = [];
        $count = count($bundleKeys);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $leftKey = $bundleKeys[$i];
                $rightKey = $bundleKeys[$j];
                $left = $bundleGroups[$leftKey];
                $right = $bundleGroups[$rightKey];

                if (!$this->bundlesOverlapForOrdering($left, $right)) {
                    continue;
                }

                $cmp = $this->compareOverlappingBundlePrecedence(
                    $left,
                    $right,
                    $leftKey,
                    $rightKey
                );
                if ($cmp < 0) {
                    $edges[] = ['from' => $leftKey, 'to' => $rightKey];
                } elseif ($cmp > 0) {
                    $edges[] = ['from' => $rightKey, 'to' => $leftKey];
                }
            }
        }

        return $edges;
    }

    private function comparePlannerIntentOrder(PlannerIntent $a, PlannerIntent $b): int
    {
        // Bundle exception: override rows stay above base rows when overlap
        // requires precedence.
        if ($a->bundleUid === $b->bundleUid && $a->role !== $b->role) {
            if ($this->plannerIntentsOverlapForOrdering($a, $b)) {
                if ($a->role === 'override' && $b->role === 'base') {
                    return -1;
                }
                if ($a->role === 'base' && $b->role === 'override') {
                    return 1;
                }
            }
        }

        // Default order: chronological by effective start date/time.
        $aStart = $a->start->getTimestamp();
        $bStart = $b->start->getTimestamp();
        if ($aStart !== $bStart) {
            return $aStart <=> $bStart;
        }

        $aEnd = $a->end->getTimestamp();
        $bEnd = $b->end->getTimestamp();
        if ($aEnd !== $bEnd) {
            return $aEnd <=> $bEnd;
        }

        // Keep overrides before base as a stable tiebreaker as well.
        if ($a->role !== $b->role) {
            if ($a->role === 'override') {
                return -1;
            }
            if ($b->role === 'override') {
                return 1;
            }
        }

        return strcmp($a->sourceEventUid, $b->sourceEventUid);
    }

    /**
     * Compare bundle blocks using overlap-aware precedence rules.
     *
     * Return:
     * - `-1` when first bundle should be above second bundle
     * - `1` when second bundle should be above first bundle
     * - `0` when no preference beyond deterministic tie
     *
     * @param array<int,PlannerIntent> $first
     * @param array<int,PlannerIntent> $second
     */
    private function compareOverlappingBundlePrecedence(
        array $first,
        array $second,
        string $firstKey,
        string $secondKey
    ): int {
        // Rule 1: later daily start wins.
        $dailyStartCmp = $this->compareBundleEffectiveDailyStart($first, $second);
        if ($dailyStartCmp !== 0) {
            return $this->applyStarvationGuard(
                $dailyStartCmp,
                $first,
                $second,
                $firstKey,
                $secondKey
            );
        }

        // Rule 2: same daily start -> later calendar start date wins.
        $calendarStartCmp = $this->compareBundleCalendarStartDate($first, $second);
        if ($calendarStartCmp !== 0) {
            return $this->applyStarvationGuard(
                $calendarStartCmp,
                $first,
                $second,
                $firstKey,
                $secondKey
            );
        }

        // Rule 3: specificity wins (narrower active footprint above broader).
        $specificityCmp = $this->compareBundleSpecificity($first, $second);
        if ($specificityCmp !== 0) {
            return $this->applyStarvationGuard(
                $specificityCmp,
                $first,
                $second,
                $firstKey,
                $secondKey
            );
        }

        // No hard precedence relation; caller should resolve by chronology/tie-break.
        return 0;
    }

    /**
     * @param array<int,PlannerIntent> $first
     * @param array<int,PlannerIntent> $second
     */
    private function compareBundleChronology(
        array $first,
        array $second,
        string $firstKey,
        string $secondKey
    ): int {
        $firstStart = $this->bundleAnchorStart($first);
        $secondStart = $this->bundleAnchorStart($second);
        if ($firstStart !== $secondStart) {
            return $firstStart <=> $secondStart;
        }

        $firstEnd = $this->bundleAnchorEnd($first);
        $secondEnd = $this->bundleAnchorEnd($second);
        if ($firstEnd !== $secondEnd) {
            return $firstEnd <=> $secondEnd;
        }

        return strcmp($firstKey, $secondKey);
    }

    /**
     * Readability grouping key (soft preference only).
     *
     * @param array<int,PlannerIntent> $bundle
     */
    private function bundleReadabilityGroupKey(array $bundle): string
    {
        if ($bundle === []) {
            return '';
        }

        $first = $bundle[0];
        $derived = $this->deriveTypeAndTargetFromPayload(
            is_array($first->payload ?? null) ? $first->payload : []
        );

        $type = strtolower(trim((string)($derived['type'] ?? '')));
        $target = trim((string)($derived['target'] ?? ''));
        if ($type === '' && $target === '') {
            $type = 'unknown';
            $target = $first->sourceEventUid;
        }

        return $type . '|' . $target;
    }

    /**
     * @param array<int,PlannerIntent> $first
     * @param array<int,PlannerIntent> $second
     */
    private function compareBundleSpecificity(array $first, array $second): int
    {
        $firstTuple = $this->bundleSpecificityTuple($first);
        $secondTuple = $this->bundleSpecificityTuple($second);

        for ($i = 0; $i < count($firstTuple); $i++) {
            if ($firstTuple[$i] === $secondTuple[$i]) {
                continue;
            }
            // Lower tuple value = narrower footprint = higher precedence.
            return $firstTuple[$i] < $secondTuple[$i] ? -1 : 1;
        }

        return 0;
    }

    /**
     * @param array<int,PlannerIntent> $bundle
     * @return array{0:int,1:int,2:int}
     */
    private function bundleSpecificityTuple(array $bundle): array
    {
        return [
            $this->bundleScopeSpanDays($bundle),
            $this->bundleWeekdayCoverageCount($bundle),
            $this->bundleDailyWindowSpanSeconds($bundle),
        ];
    }

    /**
     * @param array<int,PlannerIntent> $bundle
     */
    private function bundleScopeSpanDays(array $bundle): int
    {
        $start = $this->bundleAnchorStart($bundle);
        $end = $this->bundleAnchorEnd($bundle);
        $seconds = max(0, $end - $start);
        return (int)max(1, (int)ceil($seconds / 86400));
    }

    /**
     * @param array<int,PlannerIntent> $bundle
     */
    private function bundleWeekdayCoverageCount(array $bundle): int
    {
        $days = [];
        foreach ($bundle as $intent) {
            foreach (array_keys($this->plannerIntentWeekdaySet($intent)) as $day) {
                $days[$day] = true;
            }
        }

        return max(1, count($days));
    }

    /**
     * @param array<int,PlannerIntent> $bundle
     */
    private function bundleDailyWindowSpanSeconds(array $bundle): int
    {
        $maxSpan = 0;
        foreach ($bundle as $intent) {
            $span = $this->intentDailyWindowSpanSeconds($intent);
            if ($span > $maxSpan) {
                $maxSpan = $span;
            }
        }

        return max(1, min(86400, $maxSpan));
    }

    private function intentDailyWindowSpanSeconds(PlannerIntent $intent): int
    {
        if ($intent->allDay) {
            return 86400;
        }

        $segments = $this->intentDailyWindowSegments($intent);
        $span = 0;
        foreach ($segments as $segment) {
            $span += max(0, $segment[1] - $segment[0]);
        }

        return max(1, min(86400, $span));
    }

    /**
     * @param array<int,PlannerIntent> $first
     * @param array<int,PlannerIntent> $second
     */
    private function compareBundleEffectiveDailyStart(array $first, array $second): int
    {
        $firstStart = $this->bundleEffectiveDailyStart($first);
        $secondStart = $this->bundleEffectiveDailyStart($second);
        if ($firstStart === null || $secondStart === null) {
            return 0;
        }
        if ($firstStart === $secondStart) {
            return 0;
        }

        // Later start should be above.
        return $firstStart > $secondStart ? -1 : 1;
    }

    /**
     * @param array<int,PlannerIntent> $bundle
     * @return int|null
     */
    private function bundleEffectiveDailyStart(array $bundle): ?int
    {
        $value = null;

        foreach ($bundle as $intent) {
            $candidate = $this->intentDailyStartCandidate($intent);
            if ($candidate === null) {
                return null;
            }

            if ($value === null || $candidate > $value) {
                $value = $candidate;
            }
        }

        if ($value === null) {
            return null;
        }

        return $value;
    }

    /**
     * @return int|null
     */
    private function intentDailyStartCandidate(PlannerIntent $intent): ?int
    {
        $settings = $this->extractSchedulerSettingsFromPayload($intent->payload);
        $startSetting = isset($settings['start']) && is_string($settings['start'])
            ? trim((string)$settings['start'])
            : '';

        if ($startSetting !== '') {
            $startSeconds = $this->resolveDailyTimeSeconds($intent, $startSetting, 'start');
            if ($startSeconds !== null) {
                return $startSeconds;
            }
        }

        return $this->secondsSinceMidnight($intent->start);
    }

    /**
     * @param array<int,PlannerIntent> $first
     * @param array<int,PlannerIntent> $second
     */
    private function compareBundleCalendarStartDate(array $first, array $second): int
    {
        $firstStart = $this->bundleAnchorStart($first);
        $secondStart = $this->bundleAnchorStart($second);
        if ($firstStart === $secondStart) {
            return 0;
        }

        // Later calendar start date should be above.
        return $firstStart > $secondStart ? -1 : 1;
    }

    /**
     * Apply starvation guard to a precedence decision.
     *
     * @param array<int,PlannerIntent> $first
     * @param array<int,PlannerIntent> $second
     */
    private function applyStarvationGuard(
        int $decision,
        array $first,
        array $second,
        string $firstKey,
        string $secondKey
    ): int {
        if ($decision === 0) {
            return 0;
        }

        if ($decision < 0) {
            $firstStarvesSecond = $this->bundleOrderingWouldStarve($first, $second);
            $secondStarvesFirst = $this->bundleOrderingWouldStarve($second, $first);
            if ($firstStarvesSecond && !$secondStarvesFirst) {
                return 1;
            }
            if ($firstStarvesSecond && $secondStarvesFirst) {
                return $this->compareBundleChronology($first, $second, $firstKey, $secondKey);
            }
            return -1;
        }

        $secondStarvesFirst = $this->bundleOrderingWouldStarve($second, $first);
        $firstStarvesSecond = $this->bundleOrderingWouldStarve($first, $second);
        if ($secondStarvesFirst && !$firstStarvesSecond) {
            return -1;
        }
        if ($secondStarvesFirst && $firstStarvesSecond) {
            return $this->compareBundleChronology($first, $second, $firstKey, $secondKey);
        }
        return 1;
    }

    /**
     * @param array<int,PlannerIntent> $above
     * @param array<int,PlannerIntent> $below
     */
    private function bundleOrderingWouldStarve(array $above, array $below): bool
    {
        if (!$this->bundlesOverlapForOrdering($above, $below)) {
            return false;
        }

        $aboveStart = $this->bundleAnchorStart($above);
        $aboveEnd = $this->bundleAnchorEnd($above);
        $belowStart = $this->bundleAnchorStart($below);
        $belowEnd = $this->bundleAnchorEnd($below);
        $dateContains = $aboveStart <= $belowStart && $aboveEnd >= $belowEnd;

        $aboveDays = $this->bundleWeekdaySet($above);
        $belowDays = $this->bundleWeekdaySet($below);
        $daysContains = $this->weekdaySetContains($aboveDays, $belowDays);

        $aboveSegments = $this->bundleDailyWindowSegments($above);
        $belowSegments = $this->bundleDailyWindowSegments($below);
        if ($aboveSegments === null || $belowSegments === null) {
            return false;
        }
        $timeContains = $this->segmentsContain($aboveSegments, $belowSegments);

        $strictDate = $aboveStart < $belowStart || $aboveEnd > $belowEnd;
        $strictDays = count($aboveDays) > count($belowDays);
        $strictTime = !$this->segmentsEqual($aboveSegments, $belowSegments);

        return $dateContains
            && $daysContains
            && $timeContains
            && ($strictDate || $strictDays || $strictTime);
    }

    /**
     * @param array<int,array{0:int,1:int}> $segments
     */
    private function segmentsEqual(array $first, array $second): bool
    {
        if (count($first) !== count($second)) {
            return false;
        }
        foreach ($first as $i => $segment) {
            if (!isset($second[$i])) {
                return false;
            }
            if ($segment[0] !== $second[$i][0] || $segment[1] !== $second[$i][1]) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<int,array{0:int,1:int}> $container
     * @param array<int,array{0:int,1:int}> $containee
     */
    private function segmentsContain(array $container, array $containee): bool
    {
        foreach ($containee as $need) {
            $covered = false;
            foreach ($container as $have) {
                if ($have[0] <= $need[0] && $have[1] >= $need[1]) {
                    $covered = true;
                    break;
                }
            }
            if (!$covered) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<int,PlannerIntent> $first
     * @param array<int,PlannerIntent> $second
     */
    private function bundlesOverlapForOrdering(array $first, array $second): bool
    {
        foreach ($first as $firstIntent) {
            foreach ($second as $secondIntent) {
                if ($this->plannerIntentsOverlapForOrdering($firstIntent, $secondIntent)) {
                    return true;
                }
            }
        }
        return false;
    }

    private function plannerIntentsOverlapForOrdering(PlannerIntent $first, PlannerIntent $second): bool
    {
        $firstScopeStart = $first->scope->getStart()->getTimestamp();
        $firstScopeEnd = $first->scope->getEnd()->getTimestamp();
        $secondScopeStart = $second->scope->getStart()->getTimestamp();
        $secondScopeEnd = $second->scope->getEnd()->getTimestamp();

        // Touching edges do not overlap.
        if (max($firstScopeStart, $secondScopeStart) >= min($firstScopeEnd, $secondScopeEnd)) {
            return false;
        }

        if (!$this->weekdaySetsIntersect(
            $this->plannerIntentWeekdaySet($first),
            $this->plannerIntentWeekdaySet($second)
        )) {
            return false;
        }

        $firstSegments = $this->intentDailyWindowSegments($first);
        $secondSegments = $this->intentDailyWindowSegments($second);

        foreach ($firstSegments as $a) {
            foreach ($secondSegments as $b) {
                if (max($a[0], $b[0]) < min($a[1], $b[1])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<int,PlannerIntent> $bundle
     * @return array<string,bool>
     */
    private function bundleWeekdaySet(array $bundle): array
    {
        $days = [];
        foreach ($bundle as $intent) {
            foreach (array_keys($this->plannerIntentWeekdaySet($intent)) as $day) {
                $days[$day] = true;
            }
        }
        return $days;
    }

    /**
     * @param array<string,bool> $first
     * @param array<string,bool> $second
     */
    private function weekdaySetsIntersect(array $first, array $second): bool
    {
        foreach ($first as $day => $_) {
            if (isset($second[$day])) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string,bool> $container
     * @param array<string,bool> $containee
     */
    private function weekdaySetContains(array $container, array $containee): bool
    {
        foreach ($containee as $day => $_) {
            if (!isset($container[$day])) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return array<string,bool>
     */
    private function plannerIntentWeekdaySet(PlannerIntent $intent): array
    {
        $days = is_array($intent->weeklyDays) ? $intent->weeklyDays : [];
        if ($days === []) {
            // Unspecified day mask is conservatively treated as all days.
            $days = ['SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA'];
        }

        $set = [];
        foreach ($days as $day) {
            if (!is_string($day)) {
                continue;
            }
            $token = strtoupper(trim($day));
            if ($token === '') {
                continue;
            }
            $set[$token] = true;
        }

        if ($set === []) {
            return [
                'SU' => true,
                'MO' => true,
                'TU' => true,
                'WE' => true,
                'TH' => true,
                'FR' => true,
                'SA' => true,
            ];
        }

        return $set;
    }

    private function intentUsesSymbolicDailyTime(PlannerIntent $intent): bool
    {
        $settings = $this->extractSchedulerSettingsFromPayload($intent->payload);
        foreach (['start', 'end'] as $key) {
            $value = $settings[$key] ?? null;
            if (!is_string($value) || trim($value) === '') {
                continue;
            }
            $normalized = FPPSemantics::normalizeSymbolicTimeToken($value);
            if (is_string($normalized) && FPPSemantics::isSymbolicTime($normalized)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int,PlannerIntent> $bundle
     * @return array<int,array{0:int,1:int}>|null
     */
    private function bundleDailyWindowSegments(array $bundle): ?array
    {
        $segments = [];
        foreach ($bundle as $intent) {
            if ($intent->allDay) {
                return [[0, 86400]];
            }
            foreach ($this->intentDailyWindowSegments($intent) as $segment) {
                $segments[] = $segment;
            }
        }

        return $this->mergeDailySegments($segments);
    }

    /**
     * @return array<int,array{0:int,1:int}>
     */
    private function intentDailyWindowSegments(PlannerIntent $intent): array
    {
        if ($intent->allDay) {
            return [[0, 86400]];
        }

        $settings = $this->extractSchedulerSettingsFromPayload($intent->payload);
        $startSetting = isset($settings['start']) && is_string($settings['start'])
            ? trim((string)$settings['start'])
            : '';
        $endSetting = isset($settings['end']) && is_string($settings['end'])
            ? trim((string)$settings['end'])
            : '';

        if ($startSetting !== '' || $endSetting !== '') {
            $start = $startSetting !== ''
                ? $this->resolveDailyTimeSeconds($intent, $startSetting, 'start')
                : $this->secondsSinceMidnight($intent->start);
            $end = $endSetting !== ''
                ? $this->resolveDailyTimeSeconds($intent, $endSetting, 'end')
                : $this->secondsSinceMidnight($intent->end);

            if ($start === null || $end === null) {
                // Unknown symbolic tokens must remain conservative for overlap logic.
                return [[0, 86400]];
            }
        } else {
            $start = $this->secondsSinceMidnight($intent->start);
            $end = $this->secondsSinceMidnight($intent->end);
        }

        if ($start === $end) {
            // Ambiguous/zero-width windows are treated conservatively as full-day.
            return [[0, 86400]];
        }

        if ($end > $start) {
            return [[$start, $end]];
        }

        // Overnight wrap.
        return [
            [$start, 86400],
            [0, $end],
        ];
    }

    private function secondsSinceMidnight(\DateTimeImmutable $value): int
    {
        return ((int)$value->format('H')) * 3600
            + ((int)$value->format('i')) * 60
            + (int)$value->format('s');
    }

    /**
     * Resolve a scheduler time setting into seconds since local midnight.
     */
    private function resolveDailyTimeSeconds(PlannerIntent $intent, string $setting, string $boundary): ?int
    {
        $trimmed = trim($setting);
        if ($trimmed === '') {
            return null;
        }

        $normalized = FPPSemantics::normalizeSymbolicTimeToken($trimmed);
        if (is_string($normalized) && FPPSemantics::isSymbolicTime($normalized)) {
            $settings = $this->extractSchedulerSettingsFromPayload($intent->payload);
            $offsetKey = strtolower($boundary) === 'end' ? 'end_offset' : 'start_offset';
            $offsetAltKey = strtolower($boundary) === 'end' ? 'endoffset' : 'startoffset';
            $offsetValue = $settings[$offsetKey] ?? $settings[$offsetAltKey] ?? 0;
            $offsetMinutes = is_numeric($offsetValue) ? (int)$offsetValue : 0;
            return $this->resolveSymbolicDisplaySeconds($intent, $normalized, $offsetMinutes);
        }

        return $this->parseHardTimeSeconds($trimmed);
    }

    private function resolveSymbolicDisplaySeconds(
        PlannerIntent $intent,
        string $symbolic,
        int $offsetMinutes
    ): ?int {
        $tzName = is_string($intent->timezone ?? null) && trim((string)$intent->timezone) !== ''
            ? trim((string)$intent->timezone)
            : $this->orderingTimezone;

        try {
            $tz = new \DateTimeZone($tzName);
        } catch (\Throwable) {
            $tzName = 'UTC';
            $tz = new \DateTimeZone('UTC');
        }

        $anchorDate = $intent->scope->getStart()
            ->setTimezone($tz)
            ->format('Y-m-d');

        $cacheKey = implode('|', [
            $anchorDate,
            $symbolic,
            (string)$offsetMinutes,
            $tzName,
            $this->orderingLatitude !== null ? (string)$this->orderingLatitude : '~',
            $this->orderingLongitude !== null ? (string)$this->orderingLongitude : '~',
        ]);
        if (array_key_exists($cacheKey, $this->symbolicDisplaySecondsCache)) {
            return $this->symbolicDisplaySecondsCache[$cacheKey];
        }

        $seconds = null;
        if ($this->orderingLatitude !== null && $this->orderingLongitude !== null) {
            $estimated = SunTimeDisplayEstimator::estimate(
                $anchorDate,
                $symbolic,
                $this->orderingLatitude,
                $this->orderingLongitude,
                $tzName,
                $offsetMinutes,
                30
            );
            if (is_string($estimated) && $estimated !== '') {
                $seconds = $this->parseHardTimeSeconds($estimated);
            }
        }

        if ($seconds === null) {
            $seconds = $this->fallbackSymbolicDisplaySeconds($symbolic, $offsetMinutes);
        }

        $this->symbolicDisplaySecondsCache[$cacheKey] = $seconds;
        return $seconds;
    }

    private function fallbackSymbolicDisplaySeconds(string $symbolic, int $offsetMinutes): ?int
    {
        $baseSeconds = match ($symbolic) {
            'Dawn' => (6 * 3600),
            'SunRise' => (7 * 3600),
            'SunSet' => (18 * 3600),
            'Dusk' => (18 * 3600) + (30 * 60),
            default => null,
        };
        if (!is_int($baseSeconds)) {
            return null;
        }

        $seconds = $baseSeconds + ($offsetMinutes * 60);
        $mod = $seconds % 86400;
        if ($mod < 0) {
            $mod += 86400;
        }

        return $mod;
    }

    private function parseHardTimeSeconds(string $value): ?int
    {
        if (preg_match('/^(\d{2}):(\d{2})(?::(\d{2}))?$/', $value, $m) !== 1) {
            return null;
        }

        $h = (int)$m[1];
        $min = (int)$m[2];
        $sec = isset($m[3]) ? (int)$m[3] : 0;
        if ($h < 0 || $h > 23 || $min < 0 || $min > 59 || $sec < 0 || $sec > 59) {
            return null;
        }

        return ($h * 3600) + ($min * 60) + $sec;
    }

    /**
     * @param array<int,array{0:int,1:int}> $segments
     * @return array<int,array{0:int,1:int}>
     */
    private function mergeDailySegments(array $segments): array
    {
        if ($segments === []) {
            return [[0, 86400]];
        }

        usort($segments, static function (array $a, array $b): int {
            if ($a[0] !== $b[0]) {
                return $a[0] <=> $b[0];
            }
            return $a[1] <=> $b[1];
        });

        $merged = [];
        foreach ($segments as $segment) {
            $start = max(0, min(86400, (int)$segment[0]));
            $end = max(0, min(86400, (int)$segment[1]));
            if ($end <= $start) {
                continue;
            }

            if ($merged === []) {
                $merged[] = [$start, $end];
                continue;
            }

            $lastIndex = count($merged) - 1;
            $last = $merged[$lastIndex];
            if ($start <= $last[1]) {
                $merged[$lastIndex][1] = max($last[1], $end);
            } else {
                $merged[] = [$start, $end];
            }
        }

        return $merged === [] ? [[0, 86400]] : $merged;
    }

    /**
     * @param array<int,array<string,mixed>> $subEvents
     * @return array<string,mixed>
     */
    private function selectIdentityTimingFromSubEvents(array $subEvents): array
    {
        if ($subEvents === []) {
            return [];
        }

        $bestTiming = is_array($subEvents[0]['timing'] ?? null) ? $subEvents[0]['timing'] : [];
        $bestHash = is_string($subEvents[0]['stateHash'] ?? null) ? (string)$subEvents[0]['stateHash'] : '';

        foreach ($subEvents as $sub) {
            if (!is_array($sub)) {
                continue;
            }
            $timing = is_array($sub['timing'] ?? null) ? $sub['timing'] : [];
            $hash = is_string($sub['stateHash'] ?? null) ? (string)$sub['stateHash'] : '';

            $cmp = strcmp(
                $this->identityTimingSortKey($timing),
                $this->identityTimingSortKey($bestTiming)
            );
            if ($cmp < 0 || ($cmp === 0 && strcmp($hash, $bestHash) < 0)) {
                $bestTiming = $timing;
                $bestHash = $hash;
            }
        }

        return $bestTiming;
    }

    /**
     * @param array<string,mixed> $timing
     */
    private function identityTimingSortKey(array $timing): string
    {
        $date = is_array($timing['start_date'] ?? null) ? $timing['start_date'] : [];
        $time = is_array($timing['start_time'] ?? null) ? $timing['start_time'] : [];

        $dateHard = is_string($date['hard'] ?? null) ? trim((string)$date['hard']) : '';
        $dateSymbolic = is_string($date['symbolic'] ?? null) ? trim((string)$date['symbolic']) : '';
        $timeHard = is_string($time['hard'] ?? null) ? trim((string)$time['hard']) : '';
        $timeSymbolic = is_string($time['symbolic'] ?? null) ? trim((string)$time['symbolic']) : '';
        $offset = (int)($time['offset'] ?? 0);

        return implode('|', [
            $dateSymbolic !== '' ? $dateSymbolic : '~',
            $dateHard !== '' ? $dateHard : '9999-99-99',
            $timeSymbolic !== '' ? $timeSymbolic : '~',
            $timeHard !== '' ? $timeHard : '99:99:99',
            sprintf('%+06d', $offset),
            !empty($timing['all_day']) ? '1' : '0',
        ]);
    }

    /**
     * @param array<int,PlannerIntent> $bundleIntents
     */
    private function bundleAnchorStart(array $bundleIntents): int
    {
        $min = PHP_INT_MAX;
        foreach ($bundleIntents as $intent) {
            $ts = $intent->start->getTimestamp();
            if ($ts < $min) {
                $min = $ts;
            }
        }
        return $min === PHP_INT_MAX ? 0 : $min;
    }

    /**
     * @param array<int,PlannerIntent> $bundleIntents
     */
    private function bundleAnchorEnd(array $bundleIntents): int
    {
        $max = PHP_INT_MIN;
        foreach ($bundleIntents as $intent) {
            $ts = $intent->end->getTimestamp();
            if ($ts > $max) {
                $max = $ts;
            }
        }
        return $max === PHP_INT_MIN ? 0 : $max;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function extractExecutionOrderFromPayload(array $payload): ?int
    {
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];

        $value = $metadata['executionOrder'] ?? null;
        if ($value === null) {
            $settings = is_array($metadata['settings'] ?? null) ? $metadata['settings'] : [];
            $value = $settings['executionOrder'] ?? $settings['execution_order'] ?? null;
        }
        if (is_int($value)) {
            return $value >= 0 ? $value : 0;
        }
        if (is_string($value) && is_numeric($value)) {
            $n = (int)$value;
            return $n >= 0 ? $n : 0;
        }
        return null;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function extractExecutionOrderManualFromPayload(array $payload): bool
    {
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        $value = $metadata['executionOrderManual'] ?? null;
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            return $parsed === true;
        }
        return false;
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

    private function optionalFloat(mixed $value): ?float
    {
        if (is_float($value) || is_int($value)) {
            return (float)$value;
        }
        if (is_string($value) && trim($value) !== '' && is_numeric($value)) {
            return (float)$value;
        }

        return null;
    }

    private function normalizeSyncMode(mixed $syncMode): string
    {
        if (!is_string($syncMode)) {
            return self::SYNC_MODE_BOTH;
        }

        $syncMode = strtolower(trim($syncMode));
        if (
            $syncMode === self::SYNC_MODE_BOTH
            || $syncMode === self::SYNC_MODE_CALENDAR
            || $syncMode === self::SYNC_MODE_FPP
        ) {
            return $syncMode;
        }

        return self::SYNC_MODE_BOTH;
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
