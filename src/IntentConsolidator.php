<?php

/**
 * IntentConsolidator
 *
 * Groups per-occurrence scheduler intents into date ranges.
 *
 * IMPORTANT:
 * - Different start/end TIMES must never be merged into the same range.
 * - Overrides should naturally remain separate because their time usually differs,
 *   but we still include isOverride in grouping identity for safety.
 */
class IntentConsolidator
{
    private int $skipped = 0;
    private int $rangeCount = 0;

    /**
     * @param array<int,array<string,mixed>> $intents
     * @return array<int,array<string,mixed>>
     */
    public function consolidate(array $intents): array
    {
        if (empty($intents)) {
            return [];
        }

        $groups = [];

        foreach ($intents as $intent) {
            if (!isset($intent['target'], $intent['start'], $intent['end'])) {
                $this->skipped++;
                continue;
            }

            $start = new DateTime((string)$intent['start']);
            $end   = new DateTime((string)$intent['end']);

            $startTime = $start->format('H:i:s');
            $endTime   = $end->format('H:i:s');

            // Stable identity MUST include time + override flag (lossless)
            $key = implode('|', [
                (string)($intent['type'] ?? ''),
                (string)$intent['target'],
                (string)($intent['stopType'] ?? ''),
                (string)($intent['repeat'] ?? ''),
                (!empty($intent['isAllDay']) ? '1' : '0'),
                $startTime,
                $endTime,
                (!empty($intent['isOverride']) ? '1' : '0'),
            ]);

            // Cache time fields for later
            $intent['_startTime'] = $startTime;
            $intent['_endTime']   = $endTime;

            $groups[$key][] = $intent;
        }

        $result = [];

        foreach ($groups as $items) {
            usort($items, fn($a, $b) => strcmp((string)$a['start'], (string)$b['start']));

            $range = null;

            foreach ($items as $intent) {
                $start = new DateTime((string)$intent['start']);
                $dow   = (int)$start->format('w'); // 0=Sun..6=Sat

                if ($range === null) {
                    $range = [
                        'template'  => $intent,
                        'startDate' => $start,
                        'endDate'   => $start,
                        'days'      => [$dow => true],
                    ];
                    continue;
                }

                $expected = (clone $range['endDate'])->modify('+1 day');

                if ($start->format('Y-m-d') === $expected->format('Y-m-d')) {
                    $range['endDate'] = $start;
                    $range['days'][$dow] = true;
                } else {
                    $result[] = $this->finalizeRange($range);
                    $this->rangeCount++;

                    $range = [
                        'template'  => $intent,
                        'startDate' => $start,
                        'endDate'   => $start,
                        'days'      => [$dow => true],
                    ];
                }
            }

            if ($range !== null) {
                $result[] = $this->finalizeRange($range);
                $this->rangeCount++;
            }
        }

        return $result;
    }

    private function finalizeRange(array $range): array
    {
        $daysMap = ['Su','Mo','Tu','We','Th','Fr','Sa'];
        $days = '';

        foreach ($daysMap as $i => $label) {
            if (!empty($range['days'][$i])) {
                $days .= $label;
            }
        }

        return [
            'template' => $range['template'],
            'range' => [
                'start' => $range['startDate']->format('Y-m-d'),
                'end'   => $range['endDate']->format('Y-m-d'),
                'days'  => $days,
            ]
        ];
    }

    public function getSkippedCount(): int
    {
        return $this->skipped;
    }

    public function getRangeCount(): int
    {
        return $this->rangeCount;
    }
}

/**
 * Compatibility alias expected elsewhere
 */
class GcsIntentConsolidator extends IntentConsolidator
{
}
