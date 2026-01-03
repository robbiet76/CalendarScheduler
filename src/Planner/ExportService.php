<?php
declare(strict_types=1);

/**
 * ExportService
 *
 * Orchestrates read-only export of unmanaged FPP scheduler entries to ICS.
 *
 * Phase 30 — Per-playlist override model (FINAL):
 * - Overlaps are allowed across different playlists/summaries.
 * - Conflicts are only evaluated within the same playlist/summary.
 * - For a given summary and occurrence date, overlapping time windows are resolved
 *   by schedule.json order: higher (later) entry wins.
 * - Same-name entries on the same day are allowed if their windows do NOT overlap.
 * - Losing occurrences are excluded via EXDATE on the losing VEVENT.
 * - If all occurrences of an entry are excluded, that entry is not exported.
 *
 * Guarantees:
 * - Never mutates scheduler.json
 * - Never exports GCS-managed entries
 * - Best-effort: invalid entries are skipped with warnings, not exceptions
 */
final class ExportService
{
    /**
     * Export unmanaged scheduler entries to an ICS document.
     *
     * @return array{
     *   ok: bool,
     *   exported: int,
     *   skipped: int,
     *   unmanaged_total: int,
     *   warnings: string[],
     *   errors: string[],
     *   ics: string
     * }
     */
    public static function exportUnmanaged(): array
    {
        $warnings = [];
        $errors = [];

        // Read scheduler entries (read-only)
        try {
            $entries = SchedulerSync::readScheduleJsonStatic(
                SchedulerSync::SCHEDULE_JSON_PATH
            );
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'exported' => 0,
                'skipped' => 0,
                'unmanaged_total' => 0,
                'warnings' => [],
                'errors' => ['Failed to read schedule.json: ' . $e->getMessage()],
                'ics' => '',
            ];
        }

        // Select unmanaged scheduler entries only (preserve schedule.json order)
        $unmanaged = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (!SchedulerIdentity::isGcsManaged($entry)) {
                $unmanaged[] = $entry;
            }
        }

        $unmanagedTotal = count($unmanaged);

        // Compute per-playlist EXDATEs using scheduler precedence within same summary only
        $effective = self::applyPerPlaylistOverrideExdates($unmanaged, $warnings);

        // Convert effective unmanaged scheduler entries into export intents
        $exportEvents = [];
        $skipped = 0;

        foreach ($effective as $entry) {
            $intent = ScheduleEntryExportAdapter::adapt($entry, $warnings);
            if ($intent === null) {
                $skipped++;
                continue;
            }
            $exportEvents[] = $intent;
        }

        $exported = count($exportEvents);

        // Generate ICS output
        $ics = '';
        try {
            $ics = IcsWriter::build($exportEvents);
        } catch (Throwable $e) {
            $errors[] = 'Failed to generate ICS: ' . $e->getMessage();
            $ics = '';
        }

        return [
            'ok' => empty($errors),
            'exported' => $exported,
            'skipped' => $skipped,
            'unmanaged_total' => $unmanagedTotal,
            'warnings' => $warnings,
            'errors' => $errors,
            'ics' => $ics,
        ];
    }

    /**
     * Apply per-playlist conflict resolution:
     * - Group occurrences by (summary, occurrenceDate)
     * - Within each group, resolve overlapping windows by schedule.json order:
     *   higher (later index) wins; losers get EXDATE for that date.
     * - Same-name non-overlapping windows are both kept (multiple events).
     *
     * Adds export-only fields:
     *  - __gcs_export_exdates: array<int,string> YYYY-MM-DD to exclude for this entry
     *
     * Entries whose ALL occurrences are excluded are removed from output (to avoid
     * exporting a one-off VEVENT that EXDATE cannot suppress).
     *
     * @param array<int,array<string,mixed>> $entries Unmanaged entries in schedule.json order
     * @param array<int,string> $warnings
     * @return array<int,array<string,mixed>>
     */
    private static function applyPerPlaylistOverrideExdates(array $entries, array &$warnings): array
    {
        // Collect occurrences by summary+date.
        // occurrences[summary][date] = list of ['idx'=>int,'startAbs'=>int,'endAbs'=>int,'startTime'=>string]
        $occurrences = [];

        // Track counts per entry index for full-suppression logic
        $totalByIdx = [];
        $excludedByIdx = [];
        $exdatesByIdx = [];

        // Expand occurrences for each entry (by its own recurrence definition)
        foreach ($entries as $idx => $entry) {
            if (!is_array($entry)) continue;

            $summary = self::summaryForEntry($entry);
            if ($summary === '') {
                continue;
            }

            $sd = (string)($entry['startDate'] ?? '');
            $ed = (string)($entry['endDate'] ?? '');
            if (!self::isValidYmd($sd) || !self::isValidYmd($ed)) {
                continue;
            }

            $startTime = (string)($entry['startTime'] ?? '00:00:00');
            $endTime   = (string)($entry['endTime'] ?? '00:00:00');

            $win = self::windowSeconds($startTime, $endTime);
            if ($win === null) {
                continue;
            }

            $dates = self::expandDatesForEntry($entry, $sd, $ed);
            if (empty($dates)) continue;

            $totalByIdx[$idx] = count($dates);
            $excludedByIdx[$idx] = 0;
            $exdatesByIdx[$idx] = [];

            foreach ($dates as $ymd) {
                [$startAbs, $endAbs] = self::occurrenceAbsRange($ymd, $win);

                $occurrences[$summary][$ymd][] = [
                    'idx' => $idx,
                    'startAbs' => $startAbs,
                    'endAbs' => $endAbs,
                    'startTime' => $startTime,
                ];
            }
        }

        // Resolve conflicts within each summary+date group
        foreach ($occurrences as $summary => $byDate) {
            foreach ($byDate as $ymd => $list) {
                if (count($list) <= 1) {
                    continue;
                }

                // Higher entry (later in schedule.json) wins => sort by idx DESC
                usort($list, static function (array $a, array $b): int {
                    return ($b['idx'] <=> $a['idx']);
                });

                // Accepted intervals for this summary+date on an absolute axis
                $accepted = [];

                foreach ($list as $occ) {
                    $idx = (int)$occ['idx'];
                    $s = (int)$occ['startAbs'];
                    $e = (int)$occ['endAbs'];

                    $overlap = self::overlapsAny($accepted, $s, $e);

                    if ($overlap) {
                        // Loser occurrence: exclude this date for that entry
                        $exdatesByIdx[$idx][$ymd] = true;
                        $excludedByIdx[$idx] = ($excludedByIdx[$idx] ?? 0) + 1;
                    } else {
                        $accepted[] = [$s, $e];
                    }
                }
            }
        }

        // Build output entries with __gcs_export_exdates applied; suppress fully-excluded entries
        $out = [];
        foreach ($entries as $idx => $entry) {
            if (!is_array($entry)) continue;

            if (!isset($totalByIdx[$idx])) {
                // No expanded occurrences (invalid / unknown); keep as-is and let adapter decide
                $out[] = $entry;
                continue;
            }

            $total = (int)$totalByIdx[$idx];
            $excluded = (int)($excludedByIdx[$idx] ?? 0);

            if ($total > 0 && $excluded >= $total) {
                $warnings[] = "Export suppress: entry #" . ($idx + 1) . " fully overridden for its summary; not exported";
                continue;
            }

            $exSet = $exdatesByIdx[$idx] ?? [];
            if (!empty($exSet)) {
                $entry2 = $entry;
                $entry2['__gcs_export_exdates'] = array_values(array_keys($exSet));
                $warnings[] = "Export EXDATE: entry #" . ($idx + 1) . " excluded " . count($entry2['__gcs_export_exdates']) . " occurrence(s) (per-playlist override)";
                $out[] = $entry2;
            } else {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /* =========================
     * Helpers
     * ========================= */

    private static function summaryForEntry(array $entry): string
    {
        if (!empty($entry['playlist']) && is_string($entry['playlist'])) {
            return trim($entry['playlist']);
        }
        if (!empty($entry['command']) && is_string($entry['command'])) {
            return trim($entry['command']);
        }
        return '';
    }

    private static function isValidYmd(string $ymd): bool
    {
        return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd) && strpos($ymd, '0000-') !== 0;
    }

    /**
     * Expand dates for this entry based on its 'day' enum and date range.
     *
     * @return array<int,string> YYYY-MM-DD dates
     */
    private static function expandDatesForEntry(array $entry, string $startDate, string $endDate): array
    {
        $dayEnum = (int)($entry['day'] ?? 7);

        $start = DateTime::createFromFormat('Y-m-d', $startDate);
        $end   = DateTime::createFromFormat('Y-m-d', $endDate);
        if (!($start instanceof DateTime) || !($end instanceof DateTime)) {
            return [];
        }

        $out = [];
        $cursor = clone $start;

        while ($cursor <= $end) {
            if (self::matchesDayEnum($cursor, $dayEnum)) {
                $out[] = $cursor->format('Y-m-d');
            }
            $cursor->modify('+1 day');
        }

        return $out;
    }

    private static function matchesDayEnum(DateTime $dt, int $enum): bool
    {
        // PHP: 0=Sun..6=Sat
        $w = (int)$dt->format('w');

        if ($enum === 7) { // everyday
            return true;
        }

        return match ($enum) {
            0,1,2,3,4,5,6 => ($w === $enum),
            8  => ($w >= 1 && $w <= 5),               // MO-FR
            9  => ($w === 0 || $w === 6),             // SU,SA
            10 => ($w === 1 || $w === 3 || $w === 5), // MO,WE,FR
            11 => ($w === 2 || $w === 4),             // TU,TH
            12 => ($w === 0 || ($w >= 1 && $w <= 4)), // SU-TH
            13 => ($w === 5 || $w === 6),             // FR,SA
            default => true,
        };
    }

    /**
     * Convert start/end times to seconds in day with crossing flag.
     *
     * @return array{0:int,1:int,2:bool}|null [startSec,endSec,crossesMidnight]
     */
    private static function windowSeconds(string $startTime, string $endTime): ?array
    {
        $s = self::hmsToSeconds($startTime);
        if ($s === null) return null;

        if ($endTime === '24:00:00') {
            return [$s, 0, true];
        }

        $e = self::hmsToSeconds($endTime);
        if ($e === null) return null;

        $cross = ($e <= $s);
        return [$s, $e, $cross];
    }

    private static function hmsToSeconds(string $hms): ?int
    {
        if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $hms)) {
            return null;
        }
        [$hh, $mm, $ss] = array_map('intval', explode(':', $hms));
        if ($hh < 0 || $hh > 23) return null;
        if ($mm < 0 || $mm > 59) return null;
        if ($ss < 0 || $ss > 59) return null;
        return $hh * 3600 + $mm * 60 + $ss;
    }

    /**
     * Compute an absolute range on a per-date axis (seconds from local midnight of $ymd).
     * If crossing midnight, endAbs extends beyond 86400.
     *
     * @param string $ymd
     * @param array{0:int,1:int,2:bool} $win
     * @return array{0:int,1:int} [startAbs,endAbs]
     */
    private static function occurrenceAbsRange(string $ymd, array $win): array
    {
        [$s, $e, $cross] = $win;

        if (!$cross) {
            return [$s, $e];
        }

        // cross-midnight: represent as [s..(86400+e)]
        return [$s, 86400 + $e];
    }

    /**
     * @param array<int,array{0:int,1:int}> $accepted
     */
    private static function overlapsAny(array $accepted, int $s, int $e): bool
    {
        foreach ($accepted as $iv) {
            $a = (int)$iv[0];
            $b = (int)$iv[1];
            // overlap if max(start) < min(end)
            if (max($a, $s) < min($b, $e)) {
                return true;
            }
        }
        return false;
    }
}
