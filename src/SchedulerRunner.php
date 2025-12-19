<?php

final class GcsSchedulerRunner
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
     * Execute calendar → scheduler pipeline.
     *
     * @return array<string,mixed>
     */
    public function run(): array
    {
        $now = new DateTime('now');
        $horizonEnd = (clone $now)->modify('+' . $this->horizonDays . ' days');

        // ------------------------------------------------------------
        // Fetch ICS
        // ------------------------------------------------------------
        $fetcher = new GcsIcsFetcher();
        $ics = $fetcher->fetch($this->cfg['calendar']['ics_url'] ?? '');
        if ($ics === '') {
            return $this->emptyResult();
        }

        // ------------------------------------------------------------
        // Parse ICS
        // ------------------------------------------------------------
        $parser = new GcsIcsParser();
        $events = $parser->parse($ics, $now, $horizonEnd);

        GcsLog::info('Parser returned', [
            'eventCount' => count($events),
        ]);

        // ------------------------------------------------------------
        // Build scheduler intents (strict normalization)
        // ------------------------------------------------------------
        $intents = [];

        foreach ($events as $ev) {
            // Normalize event fields we care about
            $uid         = $this->normalizeString($ev['uid'] ?? null);
            $summary     = $this->normalizeString($ev['summary'] ?? null);
            $description = $this->normalizeString($ev['description'] ?? null);
            $start       = $this->normalizeString($ev['start'] ?? null);
            $end         = $this->normalizeString($ev['end'] ?? null);
            $isAllDay    = (bool)($ev['isAllDay'] ?? false);

            if ($uid === '' || $start === '' || $end === '') {
                continue;
            }

            // Resolver expects SUMMARY STRING only
            $resolved = GcsTargetResolver::resolve($summary);
            if ($resolved === null) {
                continue;
            }

            // Optional YAML metadata (Phase 9/10)
            $yaml = [];
            if ($description !== '') {
                $yaml = GcsYamlMetadata::parse($description);
            }

            $type   = $this->normalizeString($resolved['type'] ?? null);
            $target = $this->normalizeString($resolved['target'] ?? null);

            if ($type === '') {
                continue;
            }

            $intents[] = [
                'uid'        => $uid,
                'summary'    => $summary,
                'type'       => $type,
                'target'     => $target,
                'enabled'    => 1,
                'stopType'   => (string)($yaml['stopType'] ?? $resolved['stopType'] ?? 'graceful'),
                'repeat'     => (string)($yaml['repeat'] ?? $resolved['repeat'] ?? 'none'),
                'isAllDay'   => $isAllDay,
                'isOverride' => (bool)($yaml['override'] ?? false),
                'start'      => $start,
                'end'        => $end,
                'tag'        => 'gcs:v1:' . $uid,
            ];
        }

        // ------------------------------------------------------------
        // Consolidate intents into ranges
        // ------------------------------------------------------------
        $consolidator = new GcsIntentConsolidator();
        $ranges = $consolidator->consolidate($intents);

        GcsLog::info('Intent consolidation', [
            'inputIntents' => count($intents),
            'outputRanges' => count($ranges),
            'skipped'      => $consolidator->getSkippedCount(),
            'rangeCount'   => $consolidator->getRangeCount(),
        ]);

        // ------------------------------------------------------------
        // Map to FPP schedule entries
        // ------------------------------------------------------------
        $mapped = [];

        foreach ($ranges as $rangeItem) {
            $template = $rangeItem['template'] ?? null;
            $range    = $rangeItem['range'] ?? null;

            if (!is_array($template) || !is_array($range)) {
                continue;
            }

            $weekdayMask = GcsIntentConsolidator::shortDaysToWeekdayMask(
                (string)($range['days'] ?? '')
            );

            $entry = GcsFppScheduleMapper::mapRangeIntentToSchedule([
                'uid'         => $template['uid'],
                'summary'     => $template['summary'],
                'type'        => $template['type'],
                'target'      => $template['target'],
                'enabled'     => $template['enabled'],
                'stopType'    => $template['stopType'],
                'repeat'      => $template['repeat'],
                'isAllDay'    => $template['isAllDay'],
                'isOverride'  => $template['isOverride'],
                'start'       => new DateTime($template['start']),
                'end'         => new DateTime($template['end']),
                'startDate'   => (string)($range['start'] ?? ''),
                'endDate'     => (string)($range['end'] ?? ''),
                'weekdayMask' => $weekdayMask,
                'tag'         => $template['tag'],
            ]);

            if ($entry !== null) {
                $mapped[] = $entry;
            }
        }

        // ------------------------------------------------------------
        // Diff + apply (GCS-only identity)
        // ------------------------------------------------------------
        $state = GcsSchedulerState::load($this->horizonDays);

        $diff = new GcsSchedulerDiff($mapped, $state);
        $diffResult = $diff->compute();

        $apply = new GcsSchedulerApply($this->dryRun);
        $applySummary = $apply->apply($diffResult);

        return [
            'dryRun'         => $this->dryRun,
            'eventCount'     => count($events),
            'intentCount'    => count($intents),
            'rangeCount'     => count($ranges),
            'mappedCount'    => count($mapped),
            'diff'           => $diffResult->toArray(),
            'apply'          => $applySummary,
        ];
    }

    /**
     * Normalize any external value to a safe string.
     *
     * @param mixed $value
     */
    private function normalizeString($value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_array($value) && isset($value['value']) && is_string($value['value'])) {
            return $value['value'];
        }

        return '';
    }

    private function emptyResult(): array
    {
        return [
            'dryRun'      => $this->dryRun,
            'eventCount'  => 0,
            'intentCount' => 0,
            'rangeCount'  => 0,
            'mappedCount' => 0,
            'diff'        => [],
            'apply'       => [],
        ];
    }
}
