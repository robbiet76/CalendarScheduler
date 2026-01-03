<?php
declare(strict_types=1);

/**
 * ExportService
 *
 * Orchestrates read-only export of unmanaged FPP scheduler entries to ICS.
 *
 * Responsibilities:
 * - Read scheduler.json (read-only)
 * - Select unmanaged scheduler entries only
 * - Convert scheduler entries into export intents
 * - Generate an ICS representation of those intents
 *
 * Guarantees:
 * - Never mutates scheduler.json
 * - Never exports GCS-managed entries
 * - Best-effort processing: invalid entries are skipped with warnings
 *
 * Export correctness note (Phase 30):
 * - FPP can tolerate overlapping unmanaged entries for the same playlist/time window
 *   due to internal precedence logic. Google Calendar cannot.
 * - To prevent duplicate/shifted-looking events in Google, we clamp overlapping date
 *   ranges for identical schedule patterns during export only.
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

        // Select unmanaged scheduler entries only (ownership determined by GCS identity tag)
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

        // Clamp overlapping date ranges for identical patterns to avoid duplicate events in Google Calendar.
        $unmanaged = self::clampOverlapsForExport($unmanaged, $warnings);

        // Convert unmanaged scheduler entries into export intents
        $exportEvents = [];
        $skipped = 0;

        foreach ($unmanaged as $entry) {
            $intent = ScheduleEntryExportAdapter::adapt($entry, $warnings);
            if ($intent === null) {
                $skipped++;
                continue;
            }
            $exportEvents[] = $intent;
        }

        $exported = count($exportEvents);

        // Generate ICS output (may be empty; caller can handle messaging)
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
     * Clamp overlapping date ranges for identical schedule patterns.
     *
     * We define "identical pattern" as:
     * - same summary (playlist or command)
     * - same day enum (repeat selector)
     * - same startTime / endTime
     *
     * If two entries in the same pattern group overlap in date range, we clamp
     * the earlier entry's endDate to (next.startDate - 1 day), but only when
     * the next entry starts on a strictly later date.
     *
     * @param array<int,array<string,mixed>> $entries
     * @param array<int,string> $warnings
     * @return array<int,array<string,mixed>>
     */
    private static function clampOverlapsForExport(array $entries, array &$warnings): array
    {
        // Group by pattern key
        $groups = [];
        foreach ($entries as $e) {
            if (!is_array($e)) continue;
            $key = self::patternKey($e);
            if ($key === '') {
                // Can't safely group; leave as-is
                $groups['__ungrouped__'][] = $e;
                continue;
            }
            $groups[$key][] = $e;
        }

        $out = [];

        foreach ($groups as $key => $group) {
            if (count($group) <= 1 || $key === '__ungrouped__') {
                foreach ($group as $e) $out[] = $e;
                continue;
            }

            // Sort by startDate ascending (and then by startTime as tie-breaker)
            usort($group, static function (array $a, array $b): int {
                $sdA = (string)($a['startDate'] ?? '');
                $sdB = (string)($b['startDate'] ?? '');
                if ($sdA !== $sdB) {
                    return strcmp($sdA, $sdB);
                }
                $stA = (string)($a['startTime'] ?? '');
                $stB = (string)($b['startTime'] ?? '');
                return strcmp($stA, $stB);
            });

            $n = count($group);
            for ($i = 0; $i < $n; $i++) {
                $curr = $group[$i];

                $currStart = (string)($curr['startDate'] ?? '');
                $currEnd   = (string)($curr['endDate'] ?? '');

                // If dates missing, just pass through.
                if ($currStart === '' || $currEnd === '') {
                    $out[] = $curr;
                    continue;
                }

                // Clamp against next entry if it overlaps.
                if ($i < $n - 1) {
                    $next = $group[$i + 1];
                    $nextStart = (string)($next['startDate'] ?? '');

                    if ($nextStart !== '' && $nextStart > $currStart && $nextStart <= $currEnd) {
                        $clampedEnd = self::dayBefore($nextStart);
                        if ($clampedEnd !== null && $clampedEnd < $currEnd) {
                            $curr2 = $curr;
                            $curr2['endDate'] = $clampedEnd;

                            $warnings[] =
                                "Export clamp: '{$key}' endDate {$currEnd} -> {$clampedEnd} " .
                                "(to avoid overlap with next starting {$nextStart})";

                            $out[] = $curr2;
                            continue;
                        }
                    }
                }

                $out[] = $curr;
            }
        }

        return $out;
    }

    /**
     * Build a stable grouping key for "same schedule pattern".
     * Returns '' if a summary cannot be determined.
     */
    private static function patternKey(array $entry): string
    {
        $summary = self::summaryForEntry($entry);
        if ($summary === '') {
            return '';
        }

        $day = (string)($entry['day'] ?? '');
        $st  = (string)($entry['startTime'] ?? '');
        $et  = (string)($entry['endTime'] ?? '');

        return $summary . '|day=' . $day . '|start=' . $st . '|end=' . $et;
    }

    /**
     * Summary is playlist preferred, else command (mirrors adapter intent).
     */
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

    /**
     * Compute YYYY-MM-DD for (date - 1 day).
     */
    private static function dayBefore(string $ymd): ?string
    {
        $dt = DateTime::createFromFormat('Y-m-d', $ymd);
        if (!($dt instanceof DateTime)) {
            return null;
        }
        $dt->modify('-1 day');
        return $dt->format('Y-m-d');
    }
}
