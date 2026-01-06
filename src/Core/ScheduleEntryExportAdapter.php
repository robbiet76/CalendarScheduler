<?php
declare(strict_types=1);

final class ScheduleEntryExportAdapter
{
    public static function adapt(array $entry, array &$warnings): ?array
    {
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

        // ---- START / END DATE RESOLUTION ----

        $startDate = self::resolveDateForExport(
            (string)($entry['startDate'] ?? ''),
            $warnings,
            'startDate',
            $entry
        );

        if ($startDate === null) {
            $warnings[] = "Skipped '{$summary}': unable to resolve start date";
            return null;
        }

        $endDate = self::resolveDateForExport(
            (string)($entry['endDate'] ?? ''),
            $warnings,
            'endDate',
            $entry,
            $startDate
        );

        if ($endDate === null) {
            $warnings[] = "Skipped '{$summary}': unable to resolve end date";
            return null;
        }

        // ---- TIMES ----

        $startTime = (string)($entry['startTime'] ?? '00:00:00');
        $endTime   = (string)($entry['endTime'] ?? '00:00:00');

        $dtStart = self::parseDateTime($startDate, $startTime);
        if (!$dtStart) {
            $warnings[] = "Skipped '{$summary}': invalid DTSTART";
            return null;
        }

        if ($endTime === '24:00:00') {
            $dtEnd = (clone $dtStart)->modify('+1 day')->setTime(0, 0, 0);
        } else {
            $dtEnd = self::parseDateTime($startDate, $endTime);
        }

        if (!$dtEnd) {
            $warnings[] = "Skipped '{$summary}': invalid DTEND";
            return null;
        }

        if ($dtEnd <= $dtStart) {
            $dtEnd = (clone $dtEnd)->modify('+1 day');
        }

        // ---- RRULE ----

        $rrule = self::buildClampedRrule($entry, $startDate, $endDate, $warnings);

        return [
            'summary' => $summary,
            'dtstart' => $dtStart,
            'dtend'   => $dtEnd,
            'rrule'   => $rrule,
            'exdates' => [],
            'yaml'    => [
                'stopType' => self::stopTypeToString((int)($entry['stopType'] ?? 0)),
                'repeat'   => self::repeatToYaml($entry['repeat'] ?? 0),
            ],
        ];
    }

    /* ===================================================================== */

    private static function resolveDateForExport(
        string $raw,
        array &$warnings,
        string $field,
        array $entry,
        ?string $fallback = null
    ): ?string {
        // YYYY-MM-DD
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            if (strpos($raw, '0000-') === 0) {
                $year = (int)date('Y');
                return sprintf('%04d-%s', $year, substr($raw, 5));
            }
            return $raw;
        }

        // Holiday shortName
        if ($raw !== '' && preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $raw)) {
            $year = (int)date('Y');
            $d = FppSemantics::dateForHoliday($raw, $year);
            if ($d !== null) {
                return $d;
            }
        }

        // Fallback to startDate year
        if ($fallback !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fallback)) {
            $warnings[] = "Export: {$field} '{$raw}' unresolved; clamped to {$fallback}";
            return $fallback;
        }

        return null;
    }

    private static function buildClampedRrule(
        array $entry,
        string $startDate,
        string $endDate,
        array &$warnings
    ): ?string {
        if (($entry['startDate'] ?? null) === ($entry['endDate'] ?? null)) {
            return null;
        }

        $startYear = (int)substr($startDate, 0, 4);
        $endYear   = (int)substr($endDate, 0, 4);

        if ($endYear !== $startYear) {
            $warnings[] =
                "Export: RRULE end date {$endDate} clamped to {$startYear}-12-31 for Google compatibility.";
            $endDate = sprintf('%04d-12-31', $startYear);
        }

        $untilUtc = str_replace('-', '', $endDate) . 'T235959Z';

        $dayEnum = (int)($entry['day'] ?? -1);

        if ($dayEnum === 7) {
            return 'FREQ=DAILY;UNTIL=' . $untilUtc;
        }

        $byDay = self::fppDayEnumToByDay($dayEnum);
        if ($byDay !== '') {
            return 'FREQ=WEEKLY;BYDAY=' . $byDay . ';UNTIL=' . $untilUtc;
        }

        return null;
    }

    /* ===================================================================== */

    private static function parseDateTime(string $date, string $time): ?DateTime
    {
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $date . ' ' . $time);
        return ($dt instanceof DateTime) ? $dt : null;
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