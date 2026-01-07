<?php
declare(strict_types=1);

/**
 * HolidayResolver
 *
 * Pure deterministic holiday date resolver.
 *
 * RESPONSIBILITIES:
 * - Convert a holiday name + year into a concrete DateTime
 * - Perform weekday / Easter / offset calculations
 *
 * NON-RESPONSIBILITIES:
 * - No validation of whether a holiday "exists"
 * - No locale selection logic
 * - No interaction with FPP or plugin configuration
 *
 * This class is a math engine only.
 * All authority and validation lives in FPPSemantics.
 */
final class HolidayResolver
{
    public const LOCALE_GLOBAL = 'global';
    public const LOCALE_USA    = 'usa';
    public const LOCALE_CAN   = 'canada';

    /**
     * Canonical holiday rule table.
     *
     * IMPORTANT:
     * - Names MUST exactly match FPP UI labels
     * - Rules must be deterministic and reversible
     */
    private const HOLIDAYS = [

        /* ===============================================================
         * GLOBAL
         * ============================================================ */

        self::LOCALE_GLOBAL => [
            'Christmas Eve'   => ['type' => 'fixed', 'month' => 12, 'day' => 24],
            'Christmas'       => ['type' => 'fixed', 'month' => 12, 'day' => 25],
            'New Year\'s Eve' => ['type' => 'fixed', 'month' => 12, 'day' => 31],
            'New Year\'s Day' => ['type' => 'fixed', 'month' => 1,  'day' => 1],
        ],

        /* ===============================================================
         * USA
         * ============================================================ */

        self::LOCALE_USA => [

            'New Year\'s Day'   => ['type' => 'fixed', 'month' => 1,  'day' => 1],
            'Epiphany'          => ['type' => 'fixed', 'month' => 1,  'day' => 6],
            'MLK Day'           => ['type' => 'weekday', 'month' => 1, 'weekday' => 1, 'nth' => 3],
            'Valentine\'s Day'  => ['type' => 'fixed', 'month' => 2,  'day' => 14],
            'President\'s Day'  => ['type' => 'weekday', 'month' => 2, 'weekday' => 1, 'nth' => 3],
            'St. Patrick\'s Day'=> ['type' => 'fixed', 'month' => 3,  'day' => 17],

            'Memorial Day'        => ['type' => 'weekday_last', 'month' => 5, 'weekday' => 1],
            'Memorial Day Friday'=> ['type' => 'offset', 'base' => 'Memorial Day', 'days' => -3],

            'Independence Day' => ['type' => 'fixed', 'month' => 7, 'day' => 4],

            'Labor Day'        => ['type' => 'weekday', 'month' => 9, 'weekday' => 1, 'nth' => 1],
            'Labor Day Friday'=> ['type' => 'offset', 'base' => 'Labor Day', 'days' => -3],

            'Halloween'       => ['type' => 'fixed', 'month' => 10, 'day' => 31],
            'Veteran\'s Day'  => ['type' => 'fixed', 'month' => 11, 'day' => 11],

            'Thanksgiving'    => ['type' => 'weekday', 'month' => 11, 'weekday' => 4, 'nth' => 4],
            'Black Friday'    => ['type' => 'offset', 'base' => 'Thanksgiving', 'days' => 1],

            'Christmas Eve'   => ['type' => 'fixed', 'month' => 12, 'day' => 24],
            'Christmas'       => ['type' => 'fixed', 'month' => 12, 'day' => 25],
            'Boxing Day'      => ['type' => 'fixed', 'month' => 12, 'day' => 26],
            'New Year\'s Eve' => ['type' => 'fixed', 'month' => 12, 'day' => 31],

            /* Easter-based */
            'Ash Wednesday'   => ['type' => 'easter_offset', 'days' => -46],
            'Palm Sunday'     => ['type' => 'easter_offset', 'days' => -7],
            'Maundy Thursday' => ['type' => 'easter_offset', 'days' => -3],
            'Good Friday'     => ['type' => 'easter_offset', 'days' => -2],
            'Easter Saturday' => ['type' => 'easter_offset', 'days' => -1],
            'Easter'          => ['type' => 'easter_offset', 'days' => 0],
            'Easter Monday'   => ['type' => 'easter_offset', 'days' => 1],
            'Ascension Day'   => ['type' => 'easter_offset', 'days' => 39],
            'Pentecost'       => ['type' => 'easter_offset', 'days' => 49],
            'Whit Monday'     => ['type' => 'easter_offset', 'days' => 50],
            'Trinity Sunday'  => ['type' => 'easter_offset', 'days' => 56],
            'Corpus Chrisi'   => ['type' => 'easter_offset', 'days' => 60],
        ],

        /* ===============================================================
         * CANADA
         * ============================================================ */

        self::LOCALE_CAN => [

            'New Year\'s Day'   => ['type' => 'fixed', 'month' => 1, 'day' => 1],
            'Epiphany'          => ['type' => 'fixed', 'month' => 1, 'day' => 6],
            'Valentine\'s Day'  => ['type' => 'fixed', 'month' => 2, 'day' => 14],
            'President\'s Day'  => ['type' => 'weekday', 'month' => 2, 'weekday' => 1, 'nth' => 3],

            'Canada Day' => ['type' => 'fixed', 'month' => 7, 'day' => 1],

            'Labour Day'        => ['type' => 'weekday', 'month' => 9, 'weekday' => 1, 'nth' => 1],
            'Labour Day Friday'=> ['type' => 'offset', 'base' => 'Labour Day', 'days' => -3],

            'Thanksgiving'    => ['type' => 'weekday', 'month' => 10, 'weekday' => 1, 'nth' => 2],
            'Halloween'       => ['type' => 'fixed', 'month' => 10, 'day' => 31],
            'Remembrance Day' => ['type' => 'fixed', 'month' => 11, 'day' => 11],

            'Christmas Eve'   => ['type' => 'fixed', 'month' => 12, 'day' => 24],
            'Christmas'       => ['type' => 'fixed', 'month' => 12, 'day' => 25],
            'Boxing Day'      => ['type' => 'fixed', 'month' => 12, 'day' => 26],
            'New Year\'s Eve' => ['type' => 'fixed', 'month' => 12, 'day' => 31],

            /* Easter-based */
            'Ash Wednesday'   => ['type' => 'easter_offset', 'days' => -46],
            'Palm Sunday'     => ['type' => 'easter_offset', 'days' => -7],
            'Maundy Thursday' => ['type' => 'easter_offset', 'days' => -3],
            'Good Friday'     => ['type' => 'easter_offset', 'days' => -2],
            'Easter Saturday' => ['type' => 'easter_offset', 'days' => -1],
            'Easter'          => ['type' => 'easter_offset', 'days' => 0],
            'Easter Monday'   => ['type' => 'easter_offset', 'days' => 1],
            'Ascension Day'   => ['type' => 'easter_offset', 'days' => 39],
            'Pentecost'       => ['type' => 'easter_offset', 'days' => 49],
            'Whit Monday'     => ['type' => 'easter_offset', 'days' => 50],
            'Trinity Sunday'  => ['type' => 'easter_offset', 'days' => 56],
            'Corpus Chrisi'   => ['type' => 'easter_offset', 'days' => 60],
        ],
    ];

    /* ===============================================================
     * Public API
     * ============================================================ */

    public static function dateFromHoliday(
        string $holiday,
        int $year,
        string $locale
    ): ?DateTime {
        $rule = self::HOLIDAYS[$locale][$holiday] ?? null;
        if (!$rule) {
            return null;
        }

        return self::resolveRule($rule, $holiday, $year, $locale);
    }

    public static function holidaysFromDate(
        DateTime $date,
        string $locale
    ): array {
        $year = (int)$date->format('Y');
        $out  = [];

        foreach (self::HOLIDAYS[$locale] ?? [] as $name => $_) {
            $d = self::dateFromHoliday($name, $year, $locale);
            if ($d && $d->format('Y-m-d') === $date->format('Y-m-d')) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /* ===============================================================
     * Internal helpers
     * ============================================================ */

    private static function resolveRule(
        array $rule,
        string $name,
        int $year,
        string $locale
    ): ?DateTime {
        switch ($rule['type']) {

            case 'fixed':
                return new DateTime(sprintf('%04d-%02d-%02d', $year, $rule['month'], $rule['day']));

            case 'weekday':
                return self::nthWeekdayOfMonth(
                    $year,
                    $rule['month'],
                    $rule['weekday'],
                    $rule['nth']
                );

            case 'weekday_last':
                return self::lastWeekdayOfMonth(
                    $year,
                    $rule['month'],
                    $rule['weekday']
                );

            case 'offset':
                $base = self::dateFromHoliday($rule['base'], $year, $locale);
                return $base
                    ? (clone $base)->modify(($rule['days'] >= 0 ? '+' : '') . $rule['days'] . ' days')
                    : null;

            case 'easter_offset':
                $easter = self::easterSunday($year);
                return (clone $easter)->modify(($rule['days'] >= 0 ? '+' : '') . $rule['days'] . ' days');
        }

        return null;
    }

    private static function easterSunday(int $year): DateTime
    {
        return (new DateTime())
            ->setTimestamp(easter_date($year))
            ->setTime(0, 0, 0);
    }

    private static function nthWeekdayOfMonth(
        int $year,
        int $month,
        int $weekday,
        int $nth
    ): DateTime {
        $d = new DateTime(sprintf('%04d-%02d-01', $year, $month));

        while ((int)$d->format('N') !== $weekday) {
            $d->modify('+1 day');
        }

        $d->modify('+' . (($nth - 1) * 7) . ' days');
        return $d;
    }

    private static function lastWeekdayOfMonth(
        int $year,
        int $month,
        int $weekday
    ): DateTime {
        $d = new DateTime(sprintf('%04d-%02d-01', $year, $month));
        $d->modify('last day of this month');

        while ((int)$d->format('N') !== $weekday) {
            $d->modify('-1 day');
        }

        return $d;
    }
}