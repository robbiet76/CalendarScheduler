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

    /* ============================================================
     * Symbolic → Hard
     * ============================================================ */

    /**
     * Resolve a symbolic holiday name to a concrete date.
     *
     * @param string $symbolic Holiday shortName
     * @param int    $year
     *
     * @return DateTimeImmutable|null
     */
    public function resolveSymbolic(string $symbolic, int $year): ?DateTimeImmutable
    {
        if (!isset($this->holidayIndex[$symbolic])) {
            return null;
        }

        $def = $this->holidayIndex[$symbolic];

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
            return $this->resolveCalculatedHoliday($def['calc'], $year);
        }

        return null;
    }

    /* ============================================================
     * Hard → Symbolic
     * ============================================================ */

    /**
     * Resolve a concrete date to a symbolic holiday name using exact match only.
     *
     * This is a strict reverse lookup:
     * - Exact Y-m-d match only
     * - No heuristics or proximity logic
     *
     * @param DateTimeImmutable $date
     * @return string|null Holiday shortName if exactly matched
     */
    public function reverseResolveExact(DateTimeImmutable $date): ?string
    {
        $year = (int)$date->format('Y');
        $ymd  = $date->format('Y-m-d');

        foreach ($this->holidayIndex as $shortName => $_def) {
            $resolved = $this->resolveSymbolic($shortName, $year);
            if ($resolved && $resolved->format('Y-m-d') === $ymd) {
                return $shortName;
            }
        }

        return null;
    }

    /**
     * Attempt to infer a symbolic holiday from a concrete date.
     *
     * NOTE:
     * This method exists for backward compatibility.
     * New code should prefer reverseResolveExact().
     *
     * @param DateTimeImmutable $date
     * @return string|null Holiday shortName if matched
     */
    public function inferSymbolic(DateTimeImmutable $date): ?string
    {
        $year = (int)$date->format('Y');
        $ymd  = $date->format('Y-m-d');

        foreach ($this->holidayIndex as $shortName => $def) {
            $resolved = $this->resolveSymbolic($shortName, $year);
            if ($resolved && $resolved->format('Y-m-d') === $ymd) {
                return $shortName;
            }
        }

        return null;
    }

    /* ============================================================
     * Internal helpers
     * ============================================================ */

    private function resolveCalculatedHoliday(array $calc, int $year): ?DateTimeImmutable
    {
        // Easter-based holiday
        if (($calc['type'] ?? '') === 'easter') {
            $base = (new DateTimeImmutable())
                ->setTimestamp(easter_date($year))
                ->setTime(0, 0, 0);

            $offset = (int)($calc['offset'] ?? 0);
            return $base->modify(($offset >= 0 ? '+' : '') . $offset . ' days');
        }

        // Weekday-based holiday (e.g. Thanksgiving)
        if (
            !isset($calc['month'], $calc['dow'], $calc['week'], $calc['type'])
        ) {
            return null;
        }

        $month    = (int)$calc['month'];
        $fppDow   = (int)$calc['dow'];   // 0=Sunday .. 6=Saturday
        $week     = (int)$calc['week'];
        $origMonth = $month;

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

            return new DateTimeImmutable(
                sprintf('%04d-%02d-%02d', $year, $month, $day)
            );
        }

        return null;
    }
}