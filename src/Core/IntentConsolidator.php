<?php
declare(strict_types=1);

/**
 * IntentConsolidator
 *
 * Consolidates per-occurrence scheduling intents into a single ranged intent
 * (FPP-native approach) using:
 * - A date range (startDate..endDate)
 * - A day mask (Su..Sa)
 *
 * MUST-HOLD RULES (per project requirements):
 * 1) startDate MUST be the original DTSTART date for the series when available,
 *    even if DTSTART is in the past and the first expanded occurrence is later.
 * 2) If every calendar day between startDate and endDate has an occurrence,
 *    the day mask MUST be treated as "Everyday" (all 7 days).
 *
 * HARD GUARANTEES:
 * - Different start/end TIMES are NEVER merged
 * - Overrides are NEVER merged with non-overrides
 * - Consolidation is lossless w.r.t. occurrence set (day mask + range)
 *
 * This class does NOT:
 * - Perform scheduler I/O
 * - Enforce planner caps (horizon policy)
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

        // -------------------------------------------------------------
        // 1) Group intents by immutable identity (including TIME + override)
        // -------------------------------------------------------------
        $groups = [];

        foreach ($intents as $intent) {
            if (!isset($intent['target'], $intent['start'], $intent['end'])) {
                $this->skipped++;
                continue;
            }

            try {
                $start = new DateTime((string)$intent['start']);
                $end   = new DateTime((string)$intent['end']);
            } catch (\Throwable $e) {
                $this->skipped++;
                continue;
            }

            $startTime = $start->format('H:i:s');
            $endTime   = $end->format('H:i:s');

            $key = implode('|', [
                (string)($intent['uid'] ?? ''),               // keep per-UID ranges stable
                (string)($intent['type'] ?? ''),
                (string)$intent['target'],
                (string)($intent['stopType'] ?? ''),
                (string)($intent['repeat'] ?? ''),
                $startTime,
                $endTime,
                (!empty($intent['isOverride']) ? '1' : '0'),
            ]);

            $groups[$key][] = $intent;
        }

        // -------------------------------------------------------------
        // 2) For each group, compute ONE range + mask
        // -------------------------------------------------------------
        $result = [];

        foreach ($groups as $items) {
            // Sort by start date for stable behavior
            usort(
                $items,
                fn($a, $b) => strcmp((string)($a['start'] ?? ''), (string)($b['start'] ?? ''))
            );

            $minOccDate = null;   // earliest occurrence date
            $maxOccDate = null;   // latest occurrence date
            $daysMask   = 0;      // union of DOWs present in occurrences
            $dateSet    = [];     // set of YYYY-mm-dd dates with occurrences

            // Template should be any representative intent for this group
            $template = $items[0];

            // Series DTSTART/END (preferred range anchors if provided)
            $seriesStartDate = $this->pickSeriesStartDate($items);
            $seriesEndDate   = $this->pickSeriesEndDate($items); // optional; may be null

            foreach ($items as $intent) {
                try {
                    $s = new DateTime((string)$intent['start']);
                } catch (\Throwable $e) {
                    $this->skipped++;
                    continue;
                }

                $d = $s->format('Y-m-d');
                $dateSet[$d] = true;

                if ($minOccDate === null || $d < $minOccDate) {
                    $minOccDate = $d;
                }
                if ($maxOccDate === null || $d > $maxOccDate) {
                    $maxOccDate = $d;
                }

                $dow = (int)$s->format('w'); // 0=Sun..6=Sat
                $daysMask |= (1 << $dow);
            }

            if ($minOccDate === null || $maxOccDate === null) {
                $this->skipped++;
                continue;
            }

            // ---------------------------------------------------------
            // RANGE START: MUST be original DTSTART when available
            // ---------------------------------------------------------
            $rangeStart = $seriesStartDate ?? $minOccDate;

            // RANGE END: prefer seriesEndDate if present, else last occurrence
            // (planner may cap elsewhere; this consolidator stays lossless)
            $rangeEnd = $seriesEndDate ?? $maxOccDate;

            // ---------------------------------------------------------
            // EVERYDAY RULE:
            // If EVERY calendar day in [rangeStart..rangeEnd] has an occurrence,
            // force mask to ALL days (Everyday).
            // ---------------------------------------------------------
            if ($this->hasEveryDayCoverage($rangeStart, $rangeEnd, $dateSet)) {
                $daysMask = self::WD_ALL;
            }

            $result[] = [
                'template' => $template,
                'range' => [
                    'start' => $rangeStart,
                    'end'   => $rangeEnd,
                    'days'  => self::weekdayMaskToShortDays($daysMask),
                ],
            ];
            $this->rangeCount++;
        }

        return $result;
    }

    /**
     * Prefer seriesStartDate from intents (set by SchedulerRunner).
     * @param array<int,array<string,mixed>> $items
     */
    private function pickSeriesStartDate(array $items): ?string
    {
        foreach ($items as $it) {
            $v = (string)($it['seriesStartDate'] ?? '');
            if ($this->isValidYmd($v)) {
                return $v;
            }
        }
        return null;
    }

    /**
     * Prefer seriesEndDate from intents when provided (optional).
     * @param array<int,array<string,mixed>> $items
     */
    private function pickSeriesEndDate(array $items): ?string
    {
        foreach ($items as $it) {
            $v = (string)($it['seriesEndDate'] ?? '');
            if ($this->isValidYmd($v)) {
                return $v;
            }
        }
        return null;
    }

    /**
     * True if there is an occurrence on EVERY date from start..end inclusive.
     * @param array<string,bool> $dateSet
     */
    private function hasEveryDayCoverage(string $start, string $end, array $dateSet): bool
    {
        if (!$this->isValidYmd($start) || !$this->isValidYmd($end) || $start > $end) {
            return false;
        }

        try {
            $d = new DateTime($start);
            $e = new DateTime($end);
        } catch (\Throwable $ignored) {
            return false;
        }

        while ($d <= $e) {
            $k = $d->format('Y-m-d');
            if (empty($dateSet[$k])) {
                return false;
            }
            $d->modify('+1 day');
        }
        return true;
    }

    private function isValidYmd(string $s): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
            return false;
        }
        $dt = DateTime::createFromFormat('Y-m-d', $s);
        return ($dt instanceof DateTime) && ($dt->format('Y-m-d') === $s);
    }

    /* ---------------------------------------------------------------------
     * Shared helpers (used by mappers, tests, etc.)
     * ------------------------------------------------------------------ */

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
