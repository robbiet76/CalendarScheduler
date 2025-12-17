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

    public function run(): array
    {
        $icsUrl = trim($this->cfg['calendar']['ics_url'] ?? '');
        if ($icsUrl === '') {
            GcsLogger::instance()->warn('No ICS URL configured');
            return $this->emptyResult();
        }

        // 1. Fetch ICS
        $ics = (new IcsFetcher())->fetch($icsUrl);
        if ($ics === '') {
            return $this->emptyResult();
        }

        // 2. Parse ICS
        $now = new DateTime('now');
        $horizonEnd = (clone $now)->modify('+' . $this->horizonDays . ' days');

        $parser = new GcsIcsParser();
        $events = $parser->parse($ics, $now, $horizonEnd);

        GcsLogger::instance()->info('Parser returned', [
            'eventCount' => count($events)
        ]);

        // 3. Group by UID
        $groups = [];
        foreach ($events as $ev) {
            $uid = $ev['uid'] ?? null;
            if (!$uid) {
                continue;
            }

            $groups[$uid] ??= [
                'base' => null,
                'overrides' => [],
            ];

            if (!empty($ev['isOverride'])) {
                $groups[$uid]['overrides'][$ev['recurrenceId']] = $ev;
            } else {
                $groups[$uid]['base'] = $ev;
            }
        }

        $rawIntents = [];

        // 4. Process each UID group
        foreach ($groups as $uid => $group) {
            $base = $group['base'];
            $overrides = $group['overrides'];

            if (!$base) {
                continue;
            }

            // Resolve target
            $resolved = GcsTargetResolver::resolve($base['summary'] ?? '');
            if (!$resolved) {
                GcsLogger::instance()->warn('Unresolved target', [
                    'uid' => $uid,
                    'summary' => $base['summary'] ?? ''
                ]);
                continue;
            }

            $hasRrule = !empty($base['rrule']);
            $hasExdates = !empty($base['exDates']);
            $hasOverrides = !empty($overrides);

            $collapsible =
                $hasRrule &&
                !$hasExdates &&
                !$hasOverrides;

            GcsLogger::instance()->info('Event classified', [
                'uid' => $uid,
                'collapsible' => $collapsible,
                'hasRrule' => $hasRrule,
                'exdateCount' => count($base['exDates'] ?? []),
                'overrideCount' => count($overrides),
            ]);

            if ($collapsible) {
                // Single intent (later consolidated to range)
                $rawIntents[] = $this->buildIntent(
                    $uid,
                    $base,
                    $resolved,
                    new DateTime($base['start']),
                    new DateTime($base['end'])
                );
                continue;
            }

            // 5. Expand RRULE if present
            $occurrences = $hasRrule
                ? $this->expandRrule($base, $now, $horizonEnd)
                : [new DateTime($base['start'])];

            // Apply EXDATE filtering
            $exdateSet = [];
            foreach ($base['exDates'] ?? [] as $ex) {
                $exdateSet[$ex] = true;
            }

            foreach ($occurrences as $occStart) {
                $key = $occStart->format('Y-m-d H:i:s');
                if (isset($exdateSet[$key])) {
                    continue;
                }

                // Override?
                if (isset($overrides[$key])) {
                    $ov = $overrides[$key];
                    $rawIntents[] = $this->buildIntent(
                        $uid,
                        $ov,
                        $resolved,
                        new DateTime($ov['start']),
                        new DateTime($ov['end'])
                    );
                    continue;
                }

                // Normal expanded instance
                $dur = strtotime($base['end']) - strtotime($base['start']);
                $occEnd = (clone $occStart)->modify('+' . $dur . ' seconds');

                $rawIntents[] = $this->buildIntent(
                    $uid,
                    $base,
                    $resolved,
                    $occStart,
                    $occEnd
                );
            }
        }

        // 6. Consolidate
        $consolidator = new GcsIntentConsolidator();
        $consolidated = $consolidator->consolidate($rawIntents);

        // 7. Sync (dry-run safe)
        $sync = new SchedulerSync($this->dryRun);
        return $sync->sync($consolidated);
    }

    /* ---------------- helpers ---------------- */

    private function buildIntent(
        string $uid,
        array $event,
        array $resolved,
        DateTime $start,
        DateTime $end
    ): array {
        return [
            'uid'     => $uid,
            'summary' => $event['summary'] ?? '',
            'type'    => $resolved['type'],
            'target'  => $resolved['target'],
            'start'   => $start->format('Y-m-d H:i:s'),
            'end'     => $end->format('Y-m-d H:i:s'),
            'stopType'=> 'graceful',
            'repeat'  => 'none',
        ];
    }

    private function expandRrule(array $base, DateTime $from, DateTime $to): array
    {
        // Minimal RRULE expansion (already proven in earlier phases)
        // Assumes DTSTART already normalized

        $out = [];
        $start = new DateTime($base['start']);

        $rrule = $base['rrule'] ?? [];
        $freq = strtoupper($rrule['FREQ'] ?? '');

        if ($freq !== 'DAILY' && $freq !== 'WEEKLY') {
            return [$start];
        }

        $interval = intval($rrule['INTERVAL'] ?? 1);
        $cursor = clone $start;

        while ($cursor <= $to) {
            if ($cursor >= $from) {
                $out[] = clone $cursor;
            }
            $cursor->modify($freq === 'DAILY'
                ? "+{$interval} days"
                : "+{$interval} weeks"
            );
        }

        return $out;
    }

    private function emptyResult(): array
    {
        return [
            'adds' => 0,
            'updates' => 0,
            'deletes' => 0,
            'dryRun' => $this->dryRun,
            'intents_seen' => 0,
        ];
    }
}
