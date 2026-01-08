<?php
declare(strict_types=1);

/**
 * HolidayResolver
 *
 * Resolves holidays using the FPP runtime locale exported via fpp-env.json.
 *
 * Canonical rules:
 * - shortName is the ONLY canonical identifier
 * - UI "name" is treated as an alias
 * - All rules come from FPP (no hard-coded tables)
 *
 * This class is PURE:
 * - No I/O
 * - No scheduler logic
 * - No DateTime side effects
 */
final class HolidayResolver
{
    /**
     * Cached holiday definitions indexed by normalized key.
     *
     * @var array<string,array<string,mixed>>|null
     */
    private static ?array $holidayIndex = null;

    /* ============================================================
     * Public API
     * ============================================================ */

    /**
     * Resolve a holiday string (UI name or shortName) to a DateTime.
     *
     * @param string $input Holiday identifier (UI label OR shortName)
     * @param int    $year  Target year
     *
     * @return DateTime|null
     */
    public static function dateFromHoliday(string $input, int $year): ?DateTime
    {
        $index = self::getHolidayIndex();
        if ($index === []) {
            return null;
        }

        $key = self::normalize($input);
        if (!isset($index[$key])) {
            return null;
        }

        $def = $index[$key];

        // Fixed date
        if (!empty($def['month']) && !empty($def['day'])) {
            return new DateTime(sprintf(
                '%04d-%02d-%02d',
                $year,
                (int)$def['month'],
                (int)$def['day']
            ));
        }

        // Calculated holiday
        if (isset($def['calc']) && is_array($def['calc'])) {
            return self::resolveCalculatedHoliday($def['calc'], $year);
        }

        return null;
    }

    /* ============================================================
     * Internal helpers
     * ============================================================ */

    /**
     * Build lookup index from FPP locale.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function getHolidayIndex(): array
    {
        if (self::$holidayIndex !== null) {
            return self::$holidayIndex;
        }

        self::$holidayIndex = [];

        if (!class_exists('FppEnvironment')) {
            return self::$holidayIndex;
        }

        $raw = (new ReflectionClass('FppEnvironment'))
            ->getMethod('getRaw')
            ->invoke(
                (new ReflectionClass('FppEnvironment'))
                    ->newInstanceWithoutConstructor()
            );

        $holidays = $raw['rawLocale']['holidays'] ?? null;
        if (!is_array($holidays)) {
            return self::$holidayIndex;
        }

        foreach ($holidays as $h) {
            if (!is_array($h)) {
                continue;
            }

            if (empty($h['shortName'])) {
                continue;
            }

            $short = self::normalize($h['shortName']);
            self::$holidayIndex[$short] = $h;

            // UI name alias
            if (!empty($h['name'])) {
                self::$holidayIndex[self::normalize($h['name'])] = $h;
            }
        }

        return self::$holidayIndex;
    }

    /**
     * Resolve calculated (non-fixed) holidays.
     */
    private static function resolveCalculatedHoliday(array $calc, int $year): ?DateTime
    {
        // Easter-based
        if (($calc['type'] ?? '') === 'easter') {
            $base = new DateTime();
            $base->setTimestamp(easter_date($year));
            $base->setTime(0, 0, 0);

            $offset = (int)($calc['offset'] ?? 0);
            return $base->modify(($offset >= 0 ? '+' : '') . $offset . ' days');
        }

        // Weekday-based (head / tail)
        if (!isset($calc['month'], $calc['dow'], $calc['week'], $calc['type'])) {
            return null;
        }

        $month = (int)$calc['month'];
        $dow   = (int)$calc['dow'];   // 1 = Monday
        $week  = (int)$calc['week'];

        $d = new DateTime(sprintf('%04d-%02d-01', $year, $month));

        if ($calc['type'] === 'tail') {
            $d->modify('last day of this month');
            while ((int)$d->format('N') !== $dow) {
                $d->modify('-1 day');
            }
        } else {
            while ((int)$d->format('N') !== $dow) {
                $d->modify('+1 day');
            }
        }

        $d->modify('+' . (($week - 1) * 7) . ' days');
        return $d;
    }

    /**
     * Normalize holiday keys for matching.
     */
    private static function normalize(string $s): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', $s));
    }
}