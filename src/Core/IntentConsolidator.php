<?php
declare(strict_types=1);

/**
 * IntentConsolidator
 *
 * Consolidates per-occurrence scheduling intents into the minimum number
 * of FPP-native ranged intents while remaining strictly LOSSLESS.
 *
 * FPP Semantics:
 * - An entry is defined by: start/end times + date range + weekday mask + action
 * - The weekday mask controls which days within the date range run
 * - Date continuity is NOT required (weekly schedules are naturally sparse)
 *
 * HARD GUARANTEES:
 * - Occurrences with differing start/end TIMES are NEVER merged
 * - Overrides are NEVER merged with non-overrides
 * - Consolidation is strictly lossless:
 *   For any emitted range, every calendar date inside the range whose DOW
 *   matches the mask MUST exist as an occurrence. Otherwise the range is split.
 *
 * This class does NOT:
 * - Infer scheduling policy
 * - Modify intent semantics
 * - Perform scheduler I/O
 */
final class IntentConsolidator
{
    /* ---------------------------------------------------------------------
     * Weekday bitmask constants (Sunday = 0, matches DateTime::format('w'))
     * ------------------------------------------------------------------ */
    public const WD_SUN = 1 << 0; // 1
    public const WD_MON = 1 << 1; // 2
    public const WD_TUE = 1 << 2; // 4
    public const WD_WED = 1 << 3; // 8
    public const WD_THU = 1 << 4; // 16
    public const WD_FRI = 1 << 5; // 32
    public const WD_SAT = 1 << 6; // 64

    public const WD_ALL =
        self::WD_SUN |
        self::WD_MON |
        self::WD_TUE |
        self::WD_WED |
        self::WD_THU |
        self::WD_FRI |
        self::WD_SAT;

    /* ---------------------------------------------------------------------
     * Internal metrics (diagnostic only)
     * ------------------------------------------------------------------ */
    private int $skipped = 0;
    private int $rangeCount = 0;

    /**
     * Consolidate a set of per-occurrence intents into ranged intents.
     *
     * @param array<int,array<string,mixed>> $intents
     * @return array<int,array<string,mixed>>
     */
    public function consolidate(array $intents): array
    {
        if (empty($intents)) {
            return [];
        }

        /* -------------------------------------------------------------
         * 1. Group intents by immutable identity (FPP entry invariants)
         * ---------------------------------------------------------- */
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

            // Stable identity MUST include time and override flag
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

            $groups[$key][] = $intent;
        }

        /* -------------------------------------------------------------
         * 2. For each group, derive:
         *    - weekday mask from actual occurrences
         *    - one or more date ranges that are LOSSLESS under that mask
         * ---------------------------------------------------------- */
        $result = [];

        foreach ($groups as $items) {
            $groupOut = $this->consolidateGroupFppNative($items);
            foreach ($groupOut as $row) {
                $result[] = $row;
            }
        }

        return $result;
    }

    /**
     * Consolidate a single identity group using FPP-native semantics.
     *
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>
     */
    private function consolidateGroupFppNative(array $items): array
    {
        // Collect unique occurrence dates (Y-m-d) and DOW set
        $activeDates = []; // set: 'Y-m-d' => true
        $mask = 0;

        // Template: pick the first valid intent as the base template
        $template = null;

        foreach ($items as $intent) {
            try {
                $dt = new DateTime((string)$intent['start']);
            } catch (Throwable) {
                $this->skipped++;
                continue;
            }

            if ($template === null) {
                $template = $intent;
            }

            $date = $dt->format('Y-m-d');
            $activeDates[$date] = true;

            $dow = (int)$dt->format('w'); // 0..6
            $mask |= (1 << $dow);
        }

        if (empty($activeDates) || $template === null) {
            return [];
        }

        ksort($activeDates);
        $dates = array_keys($activeDates);
        $minDate = $dates[0];
        $maxDate = $dates[count($dates) - 1];

        $ranges = $this->buildLosslessRanges($activeDates, $mask, $minDate, $maxDate);

        $out = [];
        foreach ($ranges as [$startYmd, $endYmd]) {
            $out[] = [
                'template' => $template,
                'range' => [
                    'start' => $startYmd,
                    'end'   => $endYmd,
                    'days'  => self::weekdayMaskToShortDays($mask),
                ],
            ];
            $this->rangeCount++;
        }

        return $out;
    }

    /**
     * Build the minimum set of date ranges that remain LOSSLESS for the given mask.
     *
     * Lossless rule:
     * For any date inside [rangeStart..rangeEnd] where (mask includes that DOW),
     * that date MUST exist in $activeDates. If it doesn't, we must split ranges
     * to avoid scheduling extra runs.
     *
     * @param array<string,bool> $activeDates  set of 'Y-m-d'
     * @return array<int,array{0:string,1:string}> list of [start,end] Y-m-d
     */
    private function buildLosslessRanges(array $activeDates, int $mask, string $minYmd, string $maxYmd): array
    {
        $ranges = [];

        $cursor = DateTime::createFromFormat('Y-m-d', $minYmd);
        $end    = DateTime::createFromFormat('Y-m-d', $maxYmd);

        if (!$cursor || !$end) {
            // Fallback: single range
            return [[$minYmd, $maxYmd]];
        }

        $rangeStart = null; // DateTime|null
        $prev = null;       // DateTime|null

        while ($cursor <= $end) {
            $ymd = $cursor->format('Y-m-d');
            $dow = (int)$cursor->format('w');
            $expectedActive = (bool)($mask & (1 << $dow));
            $actualActive   = !empty($activeDates[$ymd]);

            if ($rangeStart === null) {
                // We only start a range on an actual active date
                if ($actualActive) {
                    $rangeStart = clone $cursor;
                }
            } else {
                // If this day would run under the mask but is missing, we must split
                if ($expectedActive && !$actualActive) {
                    // Close at previous day
                    if ($prev !== null) {
                        $ranges[] = [
                            $rangeStart->format('Y-m-d'),
                            $prev->format('Y-m-d'),
                        ];
                    } else {
                        // Should not happen, but be safe
                        $ranges[] = [
                            $rangeStart->format('Y-m-d'),
                            $rangeStart->format('Y-m-d'),
                        ];
                    }
                    $rangeStart = null;
                }
            }

            $prev = clone $cursor;
            $cursor->modify('+1 day');
        }

        if ($rangeStart !== null) {
            $ranges[] = [
                $rangeStart->format('Y-m-d'),
                $prev ? $prev->format('Y-m-d') : $rangeStart->format('Y-m-d'),
            ];
        }

        // Defensive cleanup: remove any inverted/empty ranges
        $clean = [];
        foreach ($ranges as [$a, $b]) {
            if ($a === '' || $b === '') continue;
            if ($a > $b) continue;
            $clean[] = [$a, $b];
        }

        // If everything got filtered (shouldn't), fallback single
        if (empty($clean)) {
            $clean[] = [$minYmd, $maxYmd];
        }

        return $clean;
    }

    /* ---------------------------------------------------------------------
     * Shared helpers (used by FppScheduleMapper and others)
     * ------------------------------------------------------------------ */

    /**
     * Convert short-day string (e.g. "SuMoTu") to weekday bitmask.
     */
    public static function shortDaysToWeekdayMask(string $days): int
    {
        $map = [
            'Su' => self::WD_SUN,
            'Mo' => self::WD_MON,
            'Tu' => self::WD_TUE,
            'We' => self::WD_WED,
            'Th' => self::WD_THU,
            'Fr' => self::WD_FRI,
            'Sa' => self::WD_SAT,
        ];

        $mask = 0;
        foreach ($map as $abbr => $bit) {
            if (strpos($days, $abbr) !== false) {
                $mask |= $bit;
            }
        }

        return $mask;
    }

    /**
     * Convert weekday bitmask to short-day string (e.g. "SuMoTu").
     */
    public static function weekdayMaskToShortDays(int $mask): string
    {
        $map = [
            self::WD_SUN => 'Su',
            self::WD_MON => 'Mo',
            self::WD_TUE => 'Tu',
            self::WD_WED => 'We',
            self::WD_THU => 'Th',
            self::WD_FRI => 'Fr',
            self::WD_SAT => 'Sa',
        ];

        $out = '';
        foreach ($map as $bit => $abbr) {
            if ($mask & $bit) {
                $out .= $abbr;
            }
        }

        return $out;
    }

    /* ---------------------------------------------------------------------
     * Diagnostics
     * ------------------------------------------------------------------ */

    public function getSkippedCount(): int
    {
        return $this->skipped;
    }

    public function getRangeCount(): int
    {
        return $this->rangeCount;
    }
}
