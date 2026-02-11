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
        $calendarIntents = $this->normalizer->normalizePlannerIntents($plannerIntents);

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
            $fppIntents[$intent->identityHash] = $intent;

            $ts = $event['updatedAtEpoch'] ?? $event['sourceUpdatedAt'] ?? 0;
            $computedFppUpdatedAtById[$intent->identityHash] = is_int($ts) ? $ts : (int)$ts;
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
