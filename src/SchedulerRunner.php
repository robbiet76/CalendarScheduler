<?php

/**
 * GcsSchedulerRunner
 *
 * Top-level orchestrator:
 * - fetch ICS
 * - parse events within horizon
 * - resolve target/intent
 * - consolidate
 * - sync (dry-run safe)
 *
 * IMPORTANT:
 * - Must not rely on autoloading (bootstrap.php requires this file)
 * - Must pass a BOOLEAN to SchedulerSync::__construct()
 */
final class GcsSchedulerRunner
{
    private array $cfg;
    private int $horizonDays;
    private bool $dryRun;

    public function __construct(array $cfg, int $horizonDays, bool $dryRun)
    {
        $this->cfg = $cfg;
        $this->horizonDays = $horizonDays;
        $this->dryRun = (bool)$dryRun;
    }

    public function run(): array
    {
        $icsUrl = trim((string)($this->cfg['calendar']['ics_url'] ?? ''));
        if ($icsUrl === '') {
            GcsLogger::instance()->warn('No ICS URL configured');
            return $this->emptyResult();
        }

        // 1) Fetch ICS (use canonical fetcher class from bootstrap)
        $ics = (new GcsIcsFetcher())->fetch($icsUrl);
        if ($ics === '') {
            return $this->emptyResult();
        }

        // 2) Parse ICS within horizon (canonical parser)
        $now = new DateTime('now');
        $horizonEnd = (clone $now)->modify('+' . $this->horizonDays . ' days');

        $parser = new GcsIcsParser();
        $events = $parser->parse($ics, $now, $horizonEnd);

        GcsLogger::instance()->info('Parser returned', [
            'eventCount' => is_array($events) ? count($events) : 0,
        ]);

        if (!is_array($events) || empty($events)) {
            return $this->emptyResult();
        }

        // 3) Build intents (simple base events only for now)
        $rawIntents = [];

        foreach ($events as $ev) {
            if (!is_array($ev)) {
                continue;
            }

            $uid = (string)($ev['uid'] ?? '');
            if ($uid === '') {
                continue;
            }

            $summary = (string)($ev['summary'] ?? '');
            $start   = (string)($ev['start'] ?? '');
            $end     = (string)($ev['end'] ?? '');

            if ($start === '' || $end === '') {
                continue;
            }

            // Resolve target intent (playlist/preset/etc)
            $resolved = GcsTargetResolver::resolve($summary);
            if (!$resolved || !is_array($resolved)) {
                GcsLogger::instance()->warn('Unresolved target', [
                    'uid' => $uid,
                    'summary' => $summary,
                ]);
                continue;
            }

            // Skip all-day events for scheduler mapping (current behavior)
            if (!empty($ev['isAllDay'])) {
                continue;
            }

            $rawIntents[] = [
                'uid'     => $uid,
                'summary' => $summary,
                'type'    => (string)($resolved['type'] ?? ''),
                'target'  => $resolved['target'] ?? null,
                'start'   => (new DateTime($start))->format('Y-m-d H:i:s'),
                'end'     => (new DateTime($end))->format('Y-m-d H:i:s'),
                'stopType'=> 'graceful',
                'repeat'  => 'none',
            ];
        }

        // 4) Consolidate intents (if available)
        $consolidated = $rawIntents;
        try {
            $consolidator = new GcsIntentConsolidator();
            $maybe = $consolidator->consolidate($rawIntents);
            if (is_array($maybe)) {
                $consolidated = $maybe;
            }
        } catch (Throwable $ignored) {
            // Consolidation should not break preview/apply wiring
        }

        // 5) Sync (dry-run safe)
        // CRITICAL: SchedulerSync expects bool; enforce it.
        $sync = new SchedulerSync((bool)$this->dryRun);
        return $sync->sync($consolidated);
    }

    private function emptyResult(): array
    {
        return [
            'adds'         => 0,
            'updates'      => 0,
            'deletes'      => 0,
            'dryRun'       => $this->dryRun,
            'intents_seen' => 0,
        ];
    }
}
