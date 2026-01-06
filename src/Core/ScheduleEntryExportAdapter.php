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
        // IMPORTANT (Step 1): we must fully resolve/clamp both bounds into concrete YYYY-MM-DD
        // so Google imports reliably.

        $rawStart = (string)($entry['startDate'] ?? '');
        $rawEnd   = (string)($entry['endDate'] ?? '');

        $startDate = self::resolveDateForExport($rawStart, $warnings, 'startDate', $summary, null);
        if ($startDate === null) {
            $warnings[] = "Skipped '{$summary}': unable to resolve start date '{$rawStart}'";
            return null;
        }

        // Resolve end date (if it cannot be resolved, clamp to startDate).
        $endDate = self::resolveDateForExport($rawEnd, $warnings, 'endDate', $summary, $startDate);
        if ($endDate === null) {
            $warnings[] = "Skipped '{$summary}': unable to resolve end date '{$rawEnd}'";
            return null;
        }

        // Ensure endDate >= startDate (string compare works for YYYY-MM-DD)
        if ($endDate < $startDate) {
            $warnings[] = "Export: '{$summary}' endDate {$endDate} was before startDate {$startDate}; clamped to startDate.";
            $endDate = $startDate;
        }

        // Google import compatibility: clamp to a single year window (startDate year)
        $startYear = (int)substr($startDate, 0, 4);
        $endYear   = (int)substr($endDate, 0, 4);
        if ($endYear !== $startYear) {
            $warnings[] =
                "Export: '{$summary}' RRULE end date {$endDate} clamped to {$startYear}-12-31 for Google compatibility.";
            $endDate = sprintf('%04d-12-31', $startYear);
        }

        // ---- TIMES ----
        // By the time we are here, ExportService/FppSemantics normalization should have converted
        // symbolic times (Dusk/Dawn/SunRise/SunSet) into concrete HH:MM:SS for display.
        $startTime = (string)($entry['startTime'] ?? '00:00:00');
        $endTime   = (string)($entry['endTime'] ?? '00:00:00');

        $dtStart = self::parseDateTime($startDate, $startTime);
        if (!$dtStart) {
            $warnings[] = "Skipped '{$summary}': invalid DTSTART '{$startDate} {$startTime}'";
            return null;
        }

        if ($endTime === '24:00:00') {
            $dtEnd = (clone $dtStart)->modify('+1 day')->setTime(0, 0, 0);
        } else {
            $dtEnd = self::parseDateTime($startDate, $endTime);
        }

        if (!$dtEnd) {
            $warnings[] = "Skipped '{$summary}': invalid DTEND '{$startDate} {$endTime}'";
            return null;
        }

        // If end <= start, treat as crossing midnight (export as next day end)
        if ($dtEnd <= $dtStart) {
            $dtEnd = (clone $dtEnd)->modify('+1 day');
        }

        // ---- RRULE ----
        // Step 1 export: ALWAYS emit a daily RRULE with a fully-resolved/clamped UNTIL.
        // We preserve original FPP day mask etc. in YAML so we can restore on import later.
        $rrule = self::buildDailyRruleUntilUtc($startDate, $endDate);

        // ---- YAML ----
        $yaml = [
            'fpp' => [
                'day'       => (int)($entry['day'] ?? 7),
                'repeat'    => $entry['repeat'] ?? 0,
                'stopType'  => (int)($entry['stopType'] ?? 0),
                'startDate' => $rawStart,
                'endDate'   => $rawEnd,
                'startTime' => (string)($entry['startTime'] ?? ''),
                'endTime'   => (string)($entry['endTime'] ?? ''),
                'startTimeOffset' => (int)($entry['startTimeOffset'] ?? 0),
                'endTimeOffset'   => (int)($entry['endTimeOffset'] ?? 0),
            ],
            'export' => [
                'resolvedStartDate' => $startDate,
                'resolvedEndDate'   => $endDate,
                'rruleMode'         => 'FREQ=DAILY (Step1)',
                'note'              => 'Day masks / holidays / symbolic times preserved in YAML; calendar shows simplified daily recurrence.',
            ],
            'stopType' => self::stopTypeToString((int)($entry['stopType'] ?? 0)),
            'repeat'   => self::repeatToYaml($entry['repeat'] ?? 0),
        ];

        // If normalization attached extra YAML (ex: symbolic time capture), carry it through.
        if (isset($entry['__gcs_yaml']) && is_array($entry['__gcs_yaml'])) {
            $yaml['gcs'] = $entry['__gcs_yaml'];
        }

        // If precedence planner attached EXDATE dtstart timestamps, pass through (writer will format).
        $exdates = [];
        if (isset($entry['__gcs_export_exdates_dtstart']) && is_array($entry['__gcs_export_exdates_dtstart'])) {
            foreach ($entry['__gcs_export_exdates_dtstart'] as $txt) {
                if (is_string($txt) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $txt)) {
                    $exdates[] = $txt;
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

    /* ===================================================================== */

    private static function resolveDateForExport(
        string $raw,
        array &$warnings,
        string $field,
        string $summary,
        ?string $fallback
    ): ?string {
        $raw = trim($raw);

        // YYYY-MM-DD
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            // 0000-* is a "wildcard year" pattern used by FPP schedules
            if (strpos($raw, '0000-') === 0) {
                $year = (int)date('Y');
                return sprintf('%04d-%s', $year, substr($raw, 5));
            }
            return $raw;
        }

        // Holiday shortName (e.g., Thanksgiving, Christmas, Epiphany)
        if ($raw !== '' && preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $raw)) {
            $year = (int)date('Y');
            $d = FppSemantics::dateForHoliday($raw, $year);
            if ($d !== null) {
                return $d;
            }

            // If we can't resolve a holiday (locale mismatch, missing holiday table), clamp
            if ($fallback !== null) {
                $warnings[] = "Export: '{$summary}' {$field} holiday '{$raw}' unresolved; clamped to {$fallback}.";
                return $fallback;
            }

            return null;
        }

        // If empty or unknown token, clamp to fallback (for endDate this is common)
        if ($fallback !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fallback)) {
            $warnings[] = "Export: '{$summary}' {$field} '{$raw}' unresolved; clamped to {$fallback}.";
            return $fallback;
        }

        return null;
    }

    /**
     * Build a Google-friendly UNTIL in UTC form.
     * We intentionally use 23:59:59 of endDate to include the entire day.
     */
    private static function buildDailyRruleUntilUtc(string $startDate, string $endDate): ?string
    {
        if ($startDate === $endDate) {
            return null; // single occurrence
        }

        // Convert YYYY-MM-DD to UTC UNTIL. We don't need local TZ accuracy for UNTIL day-bounding.
        $untilUtc = str_replace('-', '', $endDate) . 'T235959Z';

        return 'FREQ=DAILY;UNTIL=' . $untilUtc;
    }

    /* ===================================================================== */

    private static function parseDateTime(string $date, string $time): ?DateTime
    {
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $date . ' ' . $time);
        return ($dt instanceof DateTime) ? $dt : null;
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