<?php
declare(strict_types=1);

namespace GoogleCalendarScheduler\Platform;

use DateTimeImmutable;

/**
 * HolidayResolver (V2)
 *
 * Resolves FPP holiday identifiers <-> concrete dates using
 * holiday definitions provided by FPP (via fpp-env.json).
 *
 * RESPONSIBILITIES:
 * - Resolve symbolic holiday names to hard dates
 * - Infer symbolic holiday names from hard dates when possible
 *
 * HARD RULES:
 * - No I/O
 * - No logging
 * - No scheduler knowledge
 * - No calendar-provider assumptions
 *
 * NOTES:
 * - shortName is the ONLY canonical identifier
 * - Holiday definitions are provided externally (FPP environment)
 * - Resolution is best-effort and non-destructive
 */
final class HolidayResolver
{
    /**
     * Indexed holiday definitions by shortName.
     *
     * @var array<string,array<string,mixed>>
     */
    private array $holidayIndex;

    /**
     * Map of resolved holidays by date string (Y-m-d) => shortName
     *
     * @var array<string,string>
     */
    private array $dateMap = [];

    /**
     * Tracks which years have been built to avoid recomputation
     *
     * @var array<int,bool>
     */
    private array $builtYears = [];

    /**
     * @param array<int,array<string,mixed>> $holidays Raw FPP holiday definitions
     */
    public function __construct(array $holidays)
    {
        $this->holidayIndex = [];

        foreach ($holidays as $h) {
            if (
                is_array($h)
                && isset($h['shortName'])
                && is_string($h['shortName'])
            ) {
                $this->holidayIndex[$h['shortName']] = $h;
            }
        }
    }

    /**
     * Ensure the given date is covered by the internal holiday map.
     */
    private function ensureDateCovered(DateTimeImmutable $date): void
    {
        $year = (int)$date->format('Y');

        if (!isset($this->builtYears[$year])) {
            $this->buildYear($year);
            $this->builtYears[$year] = true;
        }
    }

    /**
     * Resolve a concrete date to a symbolic holiday name using exact match only.
     *
     * This is a strict reverse lookup:
     * - Exact Y-m-d match only
     * - No heuristics or proximity logic
     *
     * Does NOT auto-build ranges; caller must call ensureRange() first.
     *
     * @param DateTimeImmutable $date
     * @return string|null Holiday shortName if exactly matched
     */
    public function resolveDate(DateTimeImmutable $date): ?string
    {
        $this->ensureDateCovered($date);

        $ymd = $date->format('Y-m-d');
        return $this->dateMap[$ymd] ?? null;
    }

    /**
     * Check whether a symbolic holiday identifier is known.
     *
     * @param string $symbolic
     * @return bool
     */
    public function isSymbolic(string $symbolic): bool
    {
        return isset($this->holidayIndex[$symbolic]);
    }

    /* ============================================================
     * Internal helpers
     * ============================================================ */

    private function buildYear(int $year): void
    {
        foreach ($this->holidayIndex as $shortName => $def) {
            $resolved = $this->resolveDefinition($def, $year);
            if ($resolved !== null) {
                $ymd = $resolved->format('Y-m-d');
                $this->dateMap[$ymd] = $shortName;
            }
        }
    }

    private function resolveDefinition(array $def, int $year): ?DateTimeImmutable
    {
        // Fixed-date holiday
        if (
            isset($def['month'], $def['day'])
            && (int)$def['month'] > 0
            && (int)$def['day'] > 0
        ) {
            return new DateTimeImmutable(
                sprintf('%04d-%02d-%02d', $year, (int)$def['month'], (int)$def['day'])
            );
        }

        // Calculated holiday
        if (isset($def['calc']) && is_array($def['calc'])) {
            $calc = $def['calc'];

            // Easter-based holiday
            if (($calc['type'] ?? '') === 'easter') {
                $base = $this->easterSunday($year);

                $offset = (int)($calc['offset'] ?? 0);
                return $base->modify(($offset >= 0 ? '+' : '') . $offset . ' days');
            }

            // Weekday-based holiday (e.g. Thanksgiving)
            if (
                !isset($calc['month'], $calc['dow'], $calc['week'], $calc['type'])
            ) {
                return null;
            }

            $month     = (int)$calc['month'];
            $fppDow    = (int)$calc['dow'];   // 0=Sunday .. 6=Saturday
            $week      = (int)$calc['week'];
            $origMonth = $month;
            $offset    = (int)($calc['offset'] ?? 0);

            // Convert FPP weekday to ISO-8601 (1=Mon .. 7=Sun)
            $isoDow = ($fppDow === 0) ? 7 : $fppDow;

            // Tail-based (e.g. last Thursday)
            if ($calc['type'] === 'tail') {
                $d = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
                $d = $d->modify('last day of this month');

                while ((int)$d->format('N') !== $isoDow) {
                    $d = $d->modify('-1 day');
                }

                $d = $d->modify('-' . (($week - 1) * 7) . ' days');

                $d = $d->modify(($offset >= 0 ? '+' : '') . $offset . ' days');

                if ((int)$d->format('n') !== $origMonth) {
                    return null;
                }

                return $d;
            }

            // Head-based (e.g. 4th Thursday)
            if ($calc['type'] === 'head') {
                $first = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
                $firstDow = (int)$first->format('N');

                $delta = ($isoDow - $firstDow + 7) % 7;
                $day   = 1 + $delta + (($week - 1) * 7);

                if ($day < 1 || $day > (int)$first->format('t')) {
                    return null;
                }

                $d = new DateTimeImmutable(
                    sprintf('%04d-%02d-%02d', $year, $month, $day)
                );

                $d = $d->modify(($offset >= 0 ? '+' : '') . $offset . ' days');

                if ((int)$d->format('n') !== $origMonth) {
                    return null;
                }

                return $d;
            }
        }

        return null;
    }

    private function easterSunday(int $year): DateTimeImmutable
    {
        // Meeus/Jones/Butcher Gregorian algorithm
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return new DateTimeImmutable(sprintf('%04d-%02d-%02d 00:00:00', $year, $month, $day));
    }
}
