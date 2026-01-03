<?php
declare(strict_types=1);

/**
 * ScheduleEntryExportAdapter
 *
 * Converts a single unmanaged FPP scheduler entry into a
 * calendar export intent suitable for ICS generation.
 *
 * Responsibilities:
 * - Translate one scheduler entry into one calendar event
 * - Preserve runtime semantics via YAML metadata
 * - Validate dates and times for export safety
 *
 * Guarantees:
 * - Read-only (never mutates scheduler entries)
 * - Never exports GCS-managed entries (caller responsibility)
 * - Invalid entries are skipped with warnings, not exceptions
 *
 * This adapter performs no scheduling logic and is used
 * exclusively by export orchestration services.
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

        // Parse start datetime
        $startTime = (string)($entry['startTime'] ?? '00:00:00');
        $endTime   = (string)($entry['endTime'] ?? '00:00:00');

        $dtStart = self::parseDateTime($startDateRaw, $startTime);
        if (!$dtStart) {
            $warnings[] = "Skipped '{$summary}': invalid start datetime";
            return null;
        }

        // Parse end datetime using END DATE (not start date).
        // This is critical for multi-day entries and midnight-crossing schedules.
        $dtEnd = null;

        if ($endTime === '24:00:00') {
            // Treat as midnight at startDate+1 day (FPP-style 24:00)
            $base = self::parseDateTime($startDateRaw, '00:00:00');
            if ($base instanceof DateTime) {
                $dtEnd = (clone $base)->modify('+1 day'); // 00:00:00 next day
            }
        } else {
            // Prefer the schedule's endDate for DTEND.
            $dtEnd = self::parseDateTime($endDateRaw, $endTime);
        }

        if (!$dtEnd) {
            $warnings[] = "Skipped '{$summary}': invalid end datetime";
            return null;
        }

        // Export-safety: ensure dtEnd is strictly after dtStart.
        // If not, assume a midnight rollover and push dtEnd forward 1 day.
        if ($dtEnd <= $dtStart) {
            $dtEnd = (clone $dtEnd)->modify('+1 day');
        }

        // Build RRULE if entry represents a recurring schedule
        $rrule = self::buildRrule($entry, $endDateRaw);

        // Preserve runtime semantics via YAML metadata
        $yaml = [
            'stopType' => self::stopTypeToString((int)($entry['stopType'] ?? 0)),
            'repeat'   => self::repeatToYaml($entry['repeat'] ?? 0),
        ];

        if (isset($entry['enabled']) && (int)$entry['enabled'] === 0) {
            $yaml['enabled'] = false;
        }

        return [
            'summary' => $summary,
            'dtstart' => $dtStart,
            'dtend'   => $dtEnd,
            'rrule'   => $rrule,
            'yaml'    => $yaml,
        ];
    }

    /* ------------------------------------------------------------------ */

    private static function isValidExportDate(string $ymd): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
            return false;
        }

        // Explicitly reject year 0000 (invalid in ICS)
        if (strpos($ymd, '0000-') === 0) {
            return false;
        }

        $dt = DateTime::createFromFormat('Y-m-d', $ymd);
        return ($dt instanceof DateTime) && ($dt->format('Y-m-d') === $ymd);
    }

    private static function parseDateTime(string $date, string $time): ?DateTime
    {
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $date . ' ' . $time);
        return ($dt instanceof DateTime) ? $dt : null;
    }

    /**
     * Build an RRULE string based on FPP scheduler fields.
     *
     * IMPORTANT (Phase 30):
     * - DTSTART is a DATE-TIME, therefore UNTIL must be DATE-TIME (RFC5545).
     * - We emit UNTIL in UTC form as YYYYMMDDT235959Z, using the entry endDate.
     * - IcsWriter emits DTSTART/DTEND as UTC; RRULE UNTIL must align.
     */
    private static function buildRrule(array $entry, string $endDate): ?string
    {
        $dayEnum = (int)($entry['day'] ?? -1);

        // Single-day schedule (no recurrence)
        if (($entry['startDate'] ?? null) === ($entry['endDate'] ?? null)) {
            return null;
        }

        // UNTIL at inclusive end-of-day in UTC format.
        // (We don't do timezone conversion here; writer handles UTC for DTSTART/DTEND.
        // UNTIL is expressed as UTC wall time end-of-day to match Google import behavior.)
        $untilUtc = str_replace('-', '', $endDate) . 'T235959Z';

        // Everyday
        if ($dayEnum === 7) {
            return 'FREQ=DAILY;UNTIL=' . $untilUtc;
        }

        // Weekly patterns
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
            8  => 'MO,TU,WE,TH,FR', // Weekdays
            9  => 'SU,SA',          // Weekends
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
