<?php
declare(strict_types=1);

/**
 * ExportService
 *
 * Phase 30 — Per-playlist override model (FINAL + edge fix):
 * - Conflicts are evaluated ONLY within same summary (playlist/command).
 * - For the same summary and occurrence date:
 *    - Higher entry (later in schedule.json) wins.
 *    - Losers excluded via EXDATE.
 * - Same-day multiple entries with same summary are allowed ONLY if their windows
 *   are strictly separated by a gap (not overlapping and not touching).
 *
 * Edge fix:
 * - Treat "touching" windows (end == start) as a conflict for the same summary/day.
 * - Store EXDATE as exact DTSTART timestamps to avoid midnight-edge mistakes.
 */
final class ExportService
{
    public static function exportUnmanaged(): array
    {
        $warnings = [];
        $errors = [];

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

        $unmanaged = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) continue;
            if (!SchedulerIdentity::isGcsManaged($entry)) {
                $unmanaged[] = $entry;
            }
        }

        $unmanagedTotal = count($unmanaged);

        $effective = self::applyPerPlaylistOverrideExdates($unmanaged, $warnings);

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

    private static function applyPerPlaylistOverrideExdates(array $entries, array &$warnings): array
    {
        // occurrences[summary][date] = list of ['idx'=>int,'startAbs'=>int,'endAbs'=>int,'dtstartText'=>string]
        $occurrences = [];

        $totalByIdx = [];
        $excludedByIdx = [];
        $exdtstartByIdx = []; // list of "YYYY-MM-DD HH:MM:SS" for EXDATE anchoring

        foreach ($entries as $idx => $entry) {
            if (!is_array($entry)) continue;

            $summary = self::summaryForEntry($entry);
            if ($summary === '') continue;

            $sd = (string)($entry['startDate'] ?? '');
            $ed = (string)($entry['endDate'] ?? '');
            if (!self::isValidYmd($sd) || !self::isValidYmd($ed)) continue;

            $startTime = (string)($entry['startTime'] ?? '00:00:00');
            $endTime   = (string)($entry['endTime'] ?? '00:00:00');

            $win = self::windowSeconds($startTime, $endTime);
            if ($win === null) continue;

            $dates = self::expandDatesForEntry($entry, $sd, $ed);
            if (empty($dates)) continue;

            $totalByIdx[$idx] = count($dates);
            $excludedByIdx[$idx] = 0;
            $exdtstartByIdx[$idx] = [];

            foreach ($dates as $ymd) {
                [$startAbs, $endAbs] = self::occurrenceAbsRange($ymd, $win);

                $occurrences[$summary][$ymd][] = [
                    'idx' => $idx,
                    'startAbs' => $startAbs,
                    'endAbs' => $endAbs,
                    // store exact DTSTART text for EXDATE: this occurrence starts at ymd + startTime
                    'dtstartText' => $ymd . ' ' . $startTime,
                ];
            }
        }

        foreach ($occurrences as $summary => $byDate) {
            foreach ($byDate as $ymd => $list) {
                if (count($list) <= 1) continue;

                // Higher entry (later in schedule.json) wins
                usort($list, static function (array $a, array $b): int {
                    return ($b['idx'] <=> $a['idx']);
                });

                $accepted = [];

                foreach ($list as $occ) {
                    $idx = (int)$occ['idx'];
                    $s = (int)$occ['startAbs'];
                    $e = (int)$occ['endAbs'];

                    // EDGE FIX: treat touching windows as conflict (<=), not just strict overlap (<)
                    $conflict = self::overlapsOrTouchesAny($accepted, $s, $e);

                    if ($conflict) {
                        $exdtstartByIdx[$idx][$occ['dtstartText']] = true;
                        $excludedByIdx[$idx] = ($excludedByIdx[$idx] ?? 0) + 1;
                    } else {
                        $accepted[] = [$s, $e];
                    }
                }
            }
        }

        $out = [];
        foreach ($entries as $idx => $entry) {
            if (!is_array($entry)) continue;

            if (!isset($totalByIdx[$idx])) {
                $out[] = $entry;
                continue;
            }

            $total = (int)$totalByIdx[$idx];
            $excluded = (int)($excludedByIdx[$idx] ?? 0);

            if ($total > 0 && $excluded >= $total) {
                $warnings[] = "Export suppress: entry #" . ($idx + 1) . " fully overridden for its summary; not exported";
                continue;
            }

            $exSet = $exdtstartByIdx[$idx] ?? [];
            if (!empty($exSet)) {
                $entry2 = $entry;
                // Export-only: exact DTSTART timestamps for EXDATE anchoring
                $entry2['__gcs_export_exdates_dtstart'] = array_values(array_keys($exSet));

                $warnings[] =
                    "Export EXDATE: entry #" . ($idx + 1) .
                    " excluded " . count($entry2['__gcs_export_exdates_dtstart']) .
                    " occurrence(s) (per-playlist override, touch=conflict)";

                $out[] = $entry2;
            } else {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /* ========= helpers ========= */

    private static function summaryForEntry(array $entry): string
    {
        if (!empty($entry['playlist']) && is_string($entry['playlist'])) return trim($entry['playlist']);
        if (!empty($entry['command']) && is_string($entry['command']))   return trim($entry['command']);
        return '';
    }

    private static function isValidYmd(string $ymd): bool
    {
        return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd) && strpos($ymd, '0000-') !== 0;
    }

    private static function expandDatesForEntry(array $entry, string $startDate, string $endDate): array
    {
        $dayEnum = (int)($entry['day'] ?? 7);

        $start = DateTime::createFromFormat('Y-m-d', $startDate);
        $end   = DateTime::createFromFormat('Y-m-d', $endDate);
        if (!($start instanceof DateTime) || !($end instanceof DateTime)) return [];

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
        $w = (int)$dt->format('w'); // 0=Sun..6=Sat
        if ($enum === 7) return true;

        return match ($enum) {
            0,1,2,3,4,5,6 => ($w === $enum),
            8  => ($w >= 1 && $w <= 5),
            9  => ($w === 0 || $w === 6),
            10 => ($w === 1 || $w === 3 || $w === 5),
            11 => ($w === 2 || $w === 4),
            12 => ($w === 0 || ($w >= 1 && $w <= 4)),
            13 => ($w === 5 || $w === 6),
            default => true,
        };
    }

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
        if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $hms)) return null;
        [$hh, $mm, $ss] = array_map('intval', explode(':', $hms));
        if ($hh < 0 || $hh > 23) return null;
        if ($mm < 0 || $mm > 59) return null;
        if ($ss < 0 || $ss > 59) return null;
        return $hh * 3600 + $mm * 60 + $ss;
    }

    private static function occurrenceAbsRange(string $ymd, array $win): array
    {
        [$s, $e, $cross] = $win;
        if (!$cross) return [$s, $e];
        return [$s, 86400 + $e];
    }

    /**
     * Conflict check within same summary/day:
     * - Overlap OR touching counts as conflict.
     *
     * @param array<int,array{0:int,1:int}> $accepted
     */
    private static function overlapsOrTouchesAny(array $accepted, int $s, int $e): bool
    {
        foreach ($accepted as $iv) {
            $a = (int)$iv[0];
            $b = (int)$iv[1];
            // overlap-or-touch if max(start) <= min(end)
            if (max($a, $s) <= min($b, $e)) {
                // If both are empty/invalid, ignore; otherwise treat as conflict.
                if ($b > $a && $e > $s) return true;
            }
        }
        return false;
    }
}
