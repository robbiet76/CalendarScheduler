<?php

final class SchedulerRunner
{
    private array $cfg;
    private int $horizonDays;
    private bool $dryRun;

    public function __construct(array $cfg, int $horizonDays, bool $dryRun)
    {
        $this->cfg = $cfg;
        $this->horizonDays = $horizonDays;
        $this->dryRun = $dryRun;
    }

    /**
     * Execute a full calendar → intent pipeline.
     *
     * @return array<string,mixed>
     */
    public function run(): array
    {
        $now = new DateTime('now');
        $horizonEnd = (clone $now)->modify("+{$this->horizonDays} days");

        // --- Fetch ICS ---
        $fetcher = new IcsFetcher();
        $ics = $fetcher->fetch($this->cfg['calendar']['ics_url'] ?? '');

        if ($ics === '') {
            return [
                'adds' => 0,
                'updates' => 0,
                'deletes' => 0,
                'dryRun' => $this->dryRun,
                'intents_seen' => 0,
            ];
        }

        // --- Parse ICS ---
        $parser = new IcsParser();
        $events = $parser->parse($ics, $now, $horizonEnd);

        GcsLogger::instance()->info('Parser returned', [
            'eventCount' => count($events),
        ]);

        // --- Build scheduler intents ---
        $intents = [];

        foreach ($events as $event) {
            $resolved = GcsTargetResolver::resolve($event['summary'] ?? '');
            if (!$resolved) {
                continue;
            }

            $intent = [
                'uid'     => $event['uid'] ?? null,
                'summary' => $event['summary'] ?? '',
                'type'    => $resolved['type'],
                'target'  => $resolved['target'],
                'start'   => $event['start'],
                'end'     => $event['end'],
                'rrule'   => $event['rrule'] ?? null,
                'exDates' => $event['exDates'] ?? [],
                'recurrenceId' => $event['recurrenceId'] ?? null,
                'isOverride'   => $event['isOverride'] ?? false,
                'isAllDay'     => $event['isAllDay'] ?? false,
            ];

            $intents[] = $intent;
        }

        // --- Hand off to SchedulerSync (still dry-run) ---
        $sync = new SchedulerSync($this->dryRun);
        return $sync->sync($intents);
    }
}
