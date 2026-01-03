<?php
declare(strict_types=1);

/**
 * ScheduleEntryExportAdapter
 *
 * Converts a single unmanaged FPP scheduler entry into a
 * calendar export intent suitable for ICS generation.
 *
 * FINAL EXPORT MODEL:
 * - DTSTART / DTEND represent ONE occurrence only
 * - RRULE defines recurrence and series end
 * - EXDATE represents per-occurrence overrides (per-playlist precedence)
 */
final class ScheduleEntryExportAdapter
{
    /**
     * Convert a scheduler entry to an export intent.
     *
     * @param array<string,mixed> $entry Raw scheduler.json entry
     * @param array<int,string>   $warnings Collected warnings (appended)
     * @return array<string,mixed>|null Export intent or null if skipped
     */
    public static function adapt(array $entry, array &$warnings): ?array
    {
        // Determine event summary (playlist preferred, else command)
        $summary = '';
        if (!empty($entry['playlist']) && is_string($entry['playlist'])) {
            $summary = trim($entry['playlist']);
        } elseif (!empty($entry['command']) && is_string($entry['command'])) {
            $summary = trim($entry['command']);
        }

        if ($summary === '') {
            $warnings[] = 'Skipped entry with no playlist or command name';
            return null;
        }

        // Validate export date range
        $startDateRaw = (string)($entry['startDate'] ?? '');
        $endDateRaw   = (string)($entry['endDate'] ?? '');

        if (
            !self::isValidExportDate($startDateRaw) ||
            !self::isValidExportDate($endDateRaw)
        ) {
            $warnings[] = "Skipped '{$summary}': invalid date (year 0000)";
            return null;
        }

        $startTime = (string)($entry['startTime'] ?? '00:00:00');
        $endTime   = (string)($entry['endTime'] ?? '00:00:00');

        $dtStart = self::parseDateTime($startDateRaw, $startTime);
        if (!$dtStart) {
            $warnings[] = "Skipped '{$summary}': invalid start datetime";
            return null;
        }

        // DTEND = one occurrence end
        $dtEnd = null;

        if ($endTime === '24:00:00') {
            $base = self::parseDateTime($startDateRaw, '00:00:00');
            if ($base instanceof DateTime) {
                $dtEnd = (clone $base)->modify('+1 day');
            }
        } else {
            $dtEnd = self::parseDateTime($startDateRaw, $endTime);
        }

        if (!$dtEnd) {
            $warnings[] = "Skipped '{$summary}': invalid end datetime";
            return null;
        }

        // Midnight crossing safety
        if ($dtEnd <= $dtStart) {
            $dtEnd = (clone $dtEnd)->modify('+1 day');
        }

        $rrule = self::buildRrule($entry, $endDateRaw);

        // YAML metadata
        $yaml = [
            'stopType' => self::stopTypeToString((int)($entry['stopType'] ?? 0)),
            'repeat'   => self::repeatToYaml($entry['repeat'] ?? 0),
        ];

        if (isset($entry['enabled']) && (int)$entry['enabled'] === 0) {
            $yaml['enabled'] = false;
        }

        // EXDATE dates injected by ExportService as YYYY-MM-DD
        $exdates = [];
        $rawEx = $entry['__gcs_export_exdates'] ?? null;
        if (is_array($rawEx) && !empty($rawEx)) {
            foreach ($rawEx as $ymd) {
                if (!is_string($ymd) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
                    continue;
                }
                $ex = self::parseDateTime($ymd, $startTime);
                if ($ex instanceof DateTime) {
                    $exdates[] = $ex;
                }
            }
        }

        return [
            'summary' => $summary,
            'dtstart' => $dtStart,
            'dtend'   => $dtEnd,
            'rrule'   => $rrule,
            'exdates' => $exdates,
            'yaml'    => $yaml,
        ];
    }

    /* ------------------------------------------------------------------ */

    private static function isValidExportDate(string $ymd): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) return false;
        if (strpos($ymd, '0000-') === 0) return false;

        $dt = DateTime::createFromFormat('Y-m-d', $ymd);
        return ($dt instanceof DateTime) && ($dt->format('Y-m-d') === $ymd);
    }

    private static function parseDateTime(string $date, string $time): ?DateTime
    {
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $date . ' ' . $time);
        return ($dt instanceof DateTime) ? $dt : null;
    }

    /**
     * RRULE builder (unchanged contract):
     * - DTSTART is DATE-TIME => UNTIL must be DATE-TIME (RFC5545)
     * - UNTIL expressed in UTC end-of-day is Google-friendly and stable
     */
    private static function buildRrule(array $entry, string $endDate): ?string
    {
        $dayEnum = (int)($entry['day'] ?? -1);

        if (($entry['startDate'] ?? null) === ($entry['endDate'] ?? null)) {
            return null;
        }

        $untilUtc = str_replace('-', '', $endDate) . 'T235959Z';

        if ($dayEnum === 7) {
            return 'FREQ=DAILY;UNTIL=' . $untilUtc;
        }

        $byDay = self::fppDayEnumToByDay($dayEnum);
        if ($byDay !== '') {
            return 'FREQ=WEEKLY;BYDAY=' . $byDay . ';UNTIL=' . $untilUtc;
        }

        return null;
    }

    private static function fppDayEnumToByDay(int $enum): string
    {
        return match ($enum) {
            0  => 'SU',
            1  => 'MO',
            2  => 'TU',
            3  => 'WE',
            4  => 'TH',
            5  => 'FR',
            6  => 'SA',
            8  => 'MO,TU,WE,TH,FR',
            9  => 'SU,SA',
            10 => 'MO,WE,FR',
            11 => 'TU,TH',
            12 => 'SU,MO,TU,WE,TH',
            13 => 'FR,SA',
            default => '',
        };
    }

    private static function stopTypeToString(int $v): string
    {
        return match ($v) {
            1 => 'hard',
            2 => 'graceful_loop',
            default => 'graceful',
        };
    }

    private static function repeatToYaml($v)
    {
        if (is_int($v)) {
            if ($v === 0) return 'none';
            if ($v === 1) return 'immediate';
            if ($v >= 100) return (int)($v / 100);
        }
        return 'none';
    }
}
