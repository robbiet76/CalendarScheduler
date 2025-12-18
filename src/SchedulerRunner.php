<?php

final class SchedulerRunner
{
    private array $cfg;
    private int $horizonDays;
    private bool $dryRun;

    public function __construct(array $cfg, int $horizonDays, bool $dryRun)
    {
        $this->cfg = $cfg;
        $this->horizonDays = $horizonDays;
        $this->dryRun = $dryRun;
    }

    /**
     * Execute calendar → intent pipeline (dry-run only at this phase).
     *
     * @return array<string,mixed>
     */
    public function run(): array
    {
        $now = new DateTime('now');
        $horizonEnd = (clone $now)->modify("+{$this->horizonDays} days");

        // --- Fetch ICS ---
        $fetcher = new IcsFetcher();
        $ics = $fetcher->fetch($this->cfg['calendar']['ics_url'] ?? '');
        if ($ics === '') {
            return [
                'adds' => 0,
                'updates' => 0,
                'deletes' => 0,
                'dryRun' => $this->dryRun,
                'intents_seen' => 0,
            ];
        }

        // --- Parse ICS (includes RRULE/EXDATE/RECURRENCE-ID metadata now) ---
        $parser = new IcsParser();
        $events = $parser->parse($ics, $now, $horizonEnd);

        GcsLogger::instance()->info('Parser returned', [
            'eventCount' => count($events),
        ]);

        if (!$events) {
            $sync = new SchedulerSync($this->dryRun);
            return $sync->sync([]);
        }

        // Split base events vs overrides (RECURRENCE-ID)
        $baseEvents = [];
        $overridesByKey = []; // key = uid|recurrenceId

        foreach ($events as $e) {
            $uid = $e['uid'] ?? null;
            $isOverride = !empty($e['isOverride']);
            $rid = $e['recurrenceId'] ?? null;

            if ($isOverride && $uid && $rid) {
                $overridesByKey[$uid . '|' . $rid] = $e;
            } else {
                $baseEvents[] = $e;
            }
        }

        $intents = [];

        foreach ($baseEvents as $event) {
            $summary = (string)($event['summary'] ?? '');
            $resolved = GcsTargetResolver::resolve($summary);
            if (!$resolved) {
                continue;
            }

            // Expand occurrences (or single if non-recurring)
            $occurrences = $this->expandOccurrences($event, $now, $horizonEnd);

            // Apply overrides (R5): replace the base occurrence if an override exists
            $uid = $event['uid'] ?? null;
            if ($uid) {
                foreach ($occurrences as &$occ) {
                    $key = $uid . '|' . $occ['start'];
                    if (isset($overridesByKey[$key])) {
                        $ov = $overridesByKey[$key];
                        // Use override's actual start/end
                        $occ['start'] = (string)$ov['start'];
                        $occ['end']   = (string)$ov['end'];
                        $occ['isOverride'] = true;
                    }
                }
                unset($occ);
            }

            // Convert occurrences to intents
            foreach ($occurrences as $occ) {
                $intents[] = [
                    'uid'     => $event['uid'] ?? null,
                    'summary' => $summary,
                    'type'    => $resolved['type'],
                    'target'  => $resolved['target'],
                    'start'   => $occ['start'],
                    'end'     => $occ['end'],
                    'rrule'   => $event['rrule'] ?? null,
                    'exDates' => $event['exDates'] ?? [],
                    'recurrenceId' => $event['recurrenceId'] ?? null,
                    'isOverride'   => !empty($occ['isOverride']),
                    'isAllDay'     => !empty($event['isAllDay']),
                ];
            }
        }

        $sync = new SchedulerSync($this->dryRun);
        return $sync->sync($intents);
    }

    /**
     * Expand an event to per-occurrence start/end (strings) with EXDATE filtering.
     *
     * @return array<int,array{start:string,end:string,isOverride?:bool}>
     */
    private function expandOccurrences(array $event, DateTime $now, DateTime $horizonEnd): array
    {
        $startStr = $event['start'] ?? null;
        $endStr   = $event['end'] ?? null;

        if (!is_string($startStr) || !is_string($endStr)) {
            return [];
        }

        $start = new DateTime($startStr);
        $end   = new DateTime($endStr);

        $durationSeconds = max(0, $end->getTimestamp() - $start->getTimestamp());

        $rrule = $event['rrule'] ?? null;
        $exDates = $event['exDates'] ?? [];
        if (!is_array($exDates)) {
            $exDates = [];
        }
        $exSet = array_fill_keys($exDates, true);

        // Non-recurring: just one (unless exdated)
        if (!$rrule || !is_array($rrule) || empty($rrule['FREQ'])) {
            $s = $start->format('Y-m-d H:i:s');
            if (isset($exSet[$s])) {
                return [];
            }
            return [[
                'start' => $s,
                'end'   => (clone $start)->modify("+{$durationSeconds} seconds")->format('Y-m-d H:i:s'),
            ]];
        }

        $freq = strtoupper(trim((string)$rrule['FREQ']));
        $interval = 1;
        if (isset($rrule['INTERVAL']) && ctype_digit((string)$rrule['INTERVAL'])) {
            $interval = max(1, (int)$rrule['INTERVAL']);
        }

        $count = null;
        if (isset($rrule['COUNT']) && ctype_digit((string)$rrule['COUNT'])) {
            $count = (int)$rrule['COUNT'];
        }

        $until = null;
        if (isset($rrule['UNTIL']) && is_string($rrule['UNTIL']) && trim($rrule['UNTIL']) !== '') {
            $until = $this->parseRruleUntil(trim($rrule['UNTIL']));
        }

        $out = [];

        if ($freq === 'DAILY') {
            $i = 0;
            $cur = clone $start;

            while (true) {
                $i++;

                // Stop conditions
                if ($count !== null && $i > $count) {
                    break;
                }
                if ($until && $cur > $until) {
                    break;
                }
                if ($cur > $horizonEnd) {
                    break;
                }

                $s = $cur->format('Y-m-d H:i:s');

                // Filter EXDATE
                if (!isset($exSet[$s])) {
                    $e = (clone $cur)->modify("+{$durationSeconds} seconds")->format('Y-m-d H:i:s');
                    $out[] = ['start' => $s, 'end' => $e];
                }

                $cur->modify("+{$interval} day");
            }

            return $out;
        }

        if ($freq === 'WEEKLY') {
            // Support BYDAY=MO,TU,... if present; otherwise repeat on DTSTART weekday
            $byday = [];
            if (!empty($rrule['BYDAY'])) {
                $byday = array_filter(array_map('trim', explode(',', (string)$rrule['BYDAY'])));
            }
            if (!$byday) {
                $byday = [$this->dowToByday((int)$start->format('N'))]; // N: 1=Mon..7=Sun
            }

            $wanted = array_fill_keys($byday, true);

            $i = 0;
            $cur = clone $start;

            // We iterate day-by-day but jump weeks by INTERVAL at week boundaries.
            // Simple and robust for our use cases.
            $weeksAdvanced = 0;
            $weekStart = (clone $start)->modify('monday this week');

            while (true) {
                // Stop conditions based on horizon/until
                if ($cur > $horizonEnd) {
                    break;
                }
                if ($until && $cur > $until) {
                    break;
                }

                $by = $this->dowToByday((int)$cur->format('N'));
                if (isset($wanted[$by])) {
                    $i++;
                    if ($count !== null && $i > $count) {
                        break;
                    }

                    $s = $cur->format('Y-m-d H:i:s');
                    if (!isset($exSet[$s])) {
                        $e = (clone $cur)->modify("+{$durationSeconds} seconds")->format('Y-m-d H:i:s');
                        $out[] = ['start' => $s, 'end' => $e];
                    }
                }

                // Advance one day
                $cur->modify('+1 day');

                // If we crossed into a new week, optionally skip weeks based on INTERVAL
                $newWeekStart = (clone $cur)->modify('monday this week');
                if ($newWeekStart->format('Y-m-d') !== $weekStart->format('Y-m-d')) {
                    $weeksAdvanced++;
                    $weekStart = $newWeekStart;

                    if ($interval > 1) {
                        // We only "emit" weeks where weeksAdvanced % interval == 0
                        // If not an emitting week, jump ahead (interval-1) weeks
                        if (($weeksAdvanced % $interval) !== 0) {
                            $skipWeeks = $interval - (($weeksAdvanced % $interval));
                            $cur->modify("+{$skipWeeks} week");
                            $weeksAdvanced += $skipWeeks;
                            $weekStart = (clone $cur)->modify('monday this week');
                        }
                    }
                }
            }

            return $out;
        }

        // Unsupported FREQ: fall back to single event (but still respect EXDATE)
        $s = $start->format('Y-m-d H:i:s');
        if (isset($exSet[$s])) {
            return [];
        }
        return [[
            'start' => $s,
            'end'   => (clone $start)->modify("+{$durationSeconds} seconds")->format('Y-m-d H:i:s'),
        ]];
    }

    /**
     * Parse RRULE UNTIL into DateTime.
     * Accepts:
     *  - YYYYMMDDTHHMMSSZ
     *  - YYYYMMDDTHHMMSS
     *  - YYYYMMDD
     */
    private function parseRruleUntil(string $raw): ?DateTime
    {
        try {
            if (preg_match('/^\d{8}T\d{6}Z$/', $raw)) {
                $dt = DateTime::createFromFormat('Ymd\THis\Z', $raw, new DateTimeZone('UTC'));
                if ($dt) {
                    $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
                }
                return $dt ?: null;
            }
            if (preg_match('/^\d{8}T\d{6}$/', $raw)) {
                return DateTime::createFromFormat('Ymd\THis', $raw) ?: null;
            }
            if (preg_match('/^\d{8}$/', $raw)) {
                $dt = DateTime::createFromFormat('Ymd', $raw);
                if ($dt) {
                    $dt->setTime(23, 59, 59);
                }
                return $dt ?: null;
            }
            return new DateTime($raw);
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Map ISO-8601 weekday number (1=Mon..7=Sun) to BYDAY token.
     */
    private function dowToByday(int $n): string
    {
        switch ($n) {
            case 1: return 'MO';
            case 2: return 'TU';
            case 3: return 'WE';
            case 4: return 'TH';
            case 5: return 'FR';
            case 6: return 'SA';
            case 7: return 'SU';
        }
        return 'MO';
    }
}
