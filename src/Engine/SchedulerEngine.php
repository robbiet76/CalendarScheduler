<?php
declare(strict_types=1);

namespace CalendarScheduler\Engine;

use CalendarScheduler\Intent\IntentNormalizer;
use CalendarScheduler\Intent\NormalizationContext;
use CalendarScheduler\Adapter\Calendar\CalendarSnapshot;
use CalendarScheduler\Resolution\ResolutionEngine;
use CalendarScheduler\Planner\ManifestPlanner;
use CalendarScheduler\Diff\Diff;
use CalendarScheduler\Diff\Reconciler;
use CalendarScheduler\Adapter\Calendar\Google\GoogleApiClient;
use CalendarScheduler\Adapter\Calendar\Google\GoogleConfig;
use CalendarScheduler\Adapter\Calendar\Google\GoogleCalendarTranslator;

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
        // -----------------------------------------------------------------
        // Resolve paths
        // -----------------------------------------------------------------

        $schedulePath = $opts['schedule']
            ?? '/home/fpp/media/config/schedule.json';

        $manifestPath = $opts['manifest']
            ?? '/home/fpp/media/config/calendar-scheduler/manifest.json';

        // -----------------------------------------------------------------
        // Build NormalizationContext
        // -----------------------------------------------------------------

        $context = new NormalizationContext(
            new \DateTimeZone('UTC'),
            new \CalendarScheduler\Platform\FPPSemantics(),
            new \CalendarScheduler\Platform\HolidayResolver([])
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
        $calendarSnapshotEpoch = time();

        $calendarUpdatedAtById = [];

        // -----------------------------------------------------------------
        // Ingest FPP schedule.json
        // -----------------------------------------------------------------

        $fppAdapter = new \CalendarScheduler\Adapter\FppScheduleAdapter();
        $fppEvents = $fppAdapter->loadManifestEvents($context, $schedulePath);

        $fppMtime = filemtime($schedulePath);
        $fppSnapshotEpoch = is_int($fppMtime) ? $fppMtime : time();

        $fppUpdatedAtById = [];

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

        return $this->run(
            $currentManifest,
            $calendarEvents,
            $fppEvents,
            $calendarUpdatedAtById,
            $fppUpdatedAtById,
            $context,
            $calendarSnapshotEpoch,
            $fppSnapshotEpoch
        );
    }

    /**
     * Execute a scheduler run.
     *
     * @param array<string,mixed> $currentManifest
     * @param array<int,array<string,mixed>> $calendarEvents
     * @param array<int,array<string,mixed>> $fppEvents
     * @param array<string,int> $calendarUpdatedAtById
     * @param array<string,int> $fppUpdatedAtById
     */
    public function run(
        array $currentManifest,
        array $calendarEvents,
        array $fppEvents,
        array $calendarUpdatedAtById,
        array $fppUpdatedAtById,
        NormalizationContext $context,
        int $calendarSnapshotEpoch,
        int $fppSnapshotEpoch
    ): SchedulerRunResult {
        $computedCalendarUpdatedAtById = [];
        $computedFppUpdatedAtById = [];

        // ------------------------------------------------------------
        // Calendar events → Snapshot → Resolution → PlannerIntents → Intents
        // ------------------------------------------------------------

        // CalendarSnapshot groups already-translated provider rows.
        $snapshot = new CalendarSnapshot();
        $snapshot->snapshot($calendarEvents);

        $resolver = new ResolutionEngine();
        $resolvedSchedule = $resolver->resolve($snapshot);

        $plannerIntents = $resolvedSchedule->toPlannerIntents();

        // Resolution already produces PlannerIntent objects with correct timing.
        // At this stage (resolution-smoke-pass baseline), no further normalization
        // of planner intents is required. We simply index them by identityHash
        // for manifest building.
        $calendarIntents = [];

        foreach ($plannerIntents as $plannerIntent) {
            // Derive executable identity (type/target) from payload.
            // Google snapshot payload does not guarantee explicit `type`/`target` keys.
            // We treat `[settings]` in description as authoritative when present.
            $payload = is_array($plannerIntent->payload ?? null) ? $plannerIntent->payload : [];
            $derived = $this->deriveTypeAndTargetFromPayload($payload);
            $eventType = $derived['type'];
            $eventTarget = $derived['target'];

            // Date range comes from scope.
            // FPP schedule entries are range-based and use an INCLUSIVE end date.
            // Our ResolutionScope is [start, end) (end-exclusive), so inclusive end date
            // is (scopeEnd - 1 day).
            $scopeStart = $plannerIntent->scope->getStart();
            $scopeEndExclusive = $plannerIntent->scope->getEnd();
            $scopeEndInclusive = $scopeEndExclusive->modify('-1 day');

            // Guard: if somehow the scope is a single day [d, d+1), endInclusive == start day.
            // If scope is malformed (shouldn't be), this still prevents null dates.
            if ($scopeEndInclusive < $scopeStart) {
                $scopeEndInclusive = $scopeStart;
            }

            $manifestEvent = [
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
                    'start_time' => [
                        'hard'     => $plannerIntent->start->format('H:i:s'),
                        'symbolic' => null,
                        'offset'   => 0,
                    ],
                    'end_time'   => [
                        'hard'     => $plannerIntent->end->format('H:i:s'),
                        'symbolic' => null,
                        'offset'   => 0,
                    ],
                    'days' => null,
                ],
                'payload' => $payload,
                'ownership' => [
                    'managed' => true,
                ],
                'correlation' => [
                    'sourceEventUid' => $plannerIntent->sourceEventUid,
                ],
                'source' => 'calendar',
            ];

            $intent = $this->normalizer->fromManifestEvent(
                $manifestEvent,
                $context
            );

            $calendarIntents[$intent->identityHash] = $intent;
        }

        foreach ($calendarEvents as $event) {
            $ts = $event['updatedAtEpoch'] ?? $event['sourceUpdatedAt'] ?? 0;
            $computedCalendarUpdatedAtById[
                $event['uid'] ?? $event['sourceEventUid'] ?? spl_object_id((object)$event)
            ] = is_int($ts) ? $ts : (int) $ts;
        }

        // ------------------------------------------------------------
        // Normalize FPP events → Intents
        // ------------------------------------------------------------
        $fppIntents = [];
        foreach ($fppEvents as $event) {
            $intent = $this->normalizer->fromManifestEvent($event, $context);
            $hash = $intent->identityHash;

            $fppIntents[$hash] = $intent;

            $ts = $event['updatedAtEpoch'] ?? $event['sourceUpdatedAt'] ?? 0;
            $computedFppUpdatedAtById[$hash] = is_int($ts) ? $ts : (int)$ts;
        }

        // ------------------------------------------------------------
        // Build manifests
        // ------------------------------------------------------------
        $calendarManifest = $this->manifestPlanner
            ->buildManifestFromIntents($calendarIntents);

        $fppManifest = $this->manifestPlanner
            ->buildManifestFromIntents($fppIntents);

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
     * Best-effort extraction of scheduling identity (type/target) from a provider payload.
     *
     * Current contract:
     * - `type` is one of: playlist | sequence | command
     * - `target` is a non-empty string identifier (typically derived from summary)
     *
     * For Google rows, the authoritative settings live in the `[settings]` block inside `description`.
     * We parse that block and fall back to summary-based defaults.
     *
     * @param array<string,mixed> $payload
     * @return array{type:string,target:string}
     */
    private function deriveTypeAndTargetFromPayload(array $payload): array
    {
        $type = null;
        $target = null;

        // Preferred: explicit keys (future/other providers may supply these).
        if (isset($payload['type']) && is_string($payload['type']) && trim($payload['type']) !== '') {
            $type = strtolower(trim($payload['type']));
        }
        if (isset($payload['target']) && is_string($payload['target']) && trim($payload['target']) !== '') {
            $target = trim($payload['target']);
        }

        // Google exporter path: parse [settings] from description.
        $desc = isset($payload['description']) && is_string($payload['description']) ? $payload['description'] : '';
        if ($desc !== '') {
            $settings = $this->parseSettingsBlock($desc);

            if ($type === null && isset($settings['type']) && is_string($settings['type'])) {
                $t = strtolower(trim($settings['type']));
                if ($t !== '') {
                    $type = $t;
                }
            }

            // Optional explicit target override
            if ($target === null && isset($settings['target']) && is_string($settings['target'])) {
                $tgt = trim($settings['target']);
                if ($tgt !== '') {
                    $target = $tgt;
                }
            }
        }

        // Fallback target from summary.
        if ($target === null) {
            $summary = isset($payload['summary']) && is_string($payload['summary']) ? trim($payload['summary']) : '';
            if ($summary !== '') {
                $target = $summary;
            }
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
     * Parse a simple INI-like `[settings]` block from a description.
     *
     * Expected format:
     *   [settings]
     *   key = value
     *   ...
     *
     * Parsing stops at the first blank line after `[settings]` or at a comment-only section.
     *
     * @return array<string,string>
     */
    private function parseSettingsBlock(string $description): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $description);
        if (!is_array($lines)) {
            return [];
        }

        $in = false;
        $out = [];

        foreach ($lines as $line) {
            $line = (string)$line;
            $trim = trim($line);

            if (!$in) {
                if (strtolower($trim) === '[settings]') {
                    $in = true;
                }
                continue;
            }

            // Stop at first blank line (notes separator) once inside settings.
            if ($trim === '') {
                break;
            }

            // Ignore comment lines.
            if (str_starts_with($trim, '#') || str_starts_with($trim, ';')) {
                continue;
            }

            // key = value
            if (preg_match('/^([A-Za-z0-9_\-]+)\s*=\s*(.*?)\s*$/', $trim, $m) === 1) {
                $k = strtolower($m[1]);
                $v = $m[2];
                $out[$k] = $v;
            }
        }

        return $out;
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
