<?php
declare(strict_types=1);

/**
 * FppSemantics
 *
 * Centralized Falcon Player (FPP) semantic knowledge:
 * - Holidays (bidirectional lookup)
 * - Locale awareness
 * - Symbolic time handling (Dusk/Dawn/SunRise/SunSet)
 *
 * This file intentionally contains NO scheduler logic.
 * It is safe to call from Planner, Export, or Apply layers.
 */
final class FppSemantics
{
    private static bool $loaded = false;

    /** @var array<string,array{month:int,day:int,year:int}> */
    private static array $holidayByName = [];

    /** @var array<string,string>  MM-DD => shortName */
    private static array $holidayByMonthDay = [];

    /** @var array<string,array<string,string>>  ymd => [Dawn=>HH:MM, Dusk=>HH:MM, SunRise=>HH:MM, SunSet=>HH:MM] */
    private static array $sunTimesCache = [];

    /* =====================================================================
     * Schedule entry normalization (EXPORT SIDE)
     * ===================================================================== */

    /**
     * Normalize a scheduler entry for EXPORT.
     *
     * - Resolves symbolic times (Dusk/Dawn/SunRise/SunSet) via FPP for a representative date
     * - Rounds to nearest 30 minutes for calendar display
     * - Captures true execution intent in YAML metadata so we can restore symbolic time on import
     * - Leaves fixed times untouched
     *
     * NOTE: This normalization is intended to make exports readable in Google Calendar.
     * FPP remains the runtime authority for actual execution time.
     */
    public static function normalizeScheduleEntry(array $entry, array &$warnings): array
    {
        self::ensureLoaded();

        $out  = $entry;
        $yaml = [];

        $repDate = self::representativeDateForEntry($entry, $warnings);

        /* ---- START TIME ---- */
        if (self::isSymbolicTime($entry['startTime'] ?? null)) {
            $symbol = (string)$entry['startTime'];
            $offset = (int)($entry['startTimeOffset'] ?? 0);

            $resolved = self::resolveSymbolicTime($repDate, $symbol, $offset, $warnings);
            if ($resolved !== null) {
                $out['startTime'] = $resolved['displayTime'];
                $out['startTimeOffset'] = 0;

                $yaml['start'] = $resolved['yaml'];
            } else {
                // Could not resolve (missing date, FPP tool unavailable, etc.)
                // Keep original symbolic form and capture intent.
                $yaml['start'] = [
                    'symbolic' => $symbol,
                    'offsetMinutes' => $offset,
                    'resolvedBy' => 'unresolved',
                    'note' => 'Unable to resolve symbolic time via FPP; leaving original symbolic value.',
                ];
            }
        }

        /* ---- END TIME ---- */
        if (self::isSymbolicTime($entry['endTime'] ?? null)) {
            $symbol = (string)$entry['endTime'];
            $offset = (int)($entry['endTimeOffset'] ?? 0);

            $resolved = self::resolveSymbolicTime($repDate, $symbol, $offset, $warnings);
            if ($resolved !== null) {
                $out['endTime'] = $resolved['displayTime'];
                $out['endTimeOffset'] = 0;

                $yaml['end'] = $resolved['yaml'];
            } else {
                $yaml['end'] = [
                    'symbolic' => $symbol,
                    'offsetMinutes' => $offset,
                    'resolvedBy' => 'unresolved',
                    'note' => 'Unable to resolve symbolic time via FPP; leaving original symbolic value.',
                ];
            }
        }

        if (!empty($yaml)) {
            $yaml['note'] = 'Times shown are rounded for calendar display. FPP resolves actual execution time at runtime.';
            $yaml['rounding'] = 'nearest-30-minutes';
            $yaml['representativeDateUsed'] = $repDate ?? null;

            $out['__gcs_yaml'] = $yaml;
        }

        return $out;
    }

    /**
     * Resolve a symbolic time through FPP for a specific date, apply offset minutes,
     * then round for calendar display.
     *
     * @return array{displayTime:string,yaml:array}|null
     */
    public static function resolveSymbolicTime(?string $ymd, string $symbolicTime, int $offsetMinutes, array &$warnings): ?array
    {
        self::ensureLoaded();

        if ($ymd === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
            $warnings[] = "Export: cannot resolve {$symbolicTime} (no valid date available).";
            return null;
        }

        if (!self::isSymbolicTime($symbolicTime)) {
            return null;
        }

        $times = self::getFppSunTimesForDate($ymd, $warnings);
        if ($times === null) {
            $warnings[] = "Export: FPP sun time lookup failed for {$ymd}; cannot resolve {$symbolicTime}.";
            return null;
        }

        $baseHm = $times[$symbolicTime] ?? null;
        if ($baseHm === null) {
            $warnings[] = "Export: FPP sun time lookup did not include '{$symbolicTime}' for {$ymd}.";
            return null;
        }

        $baseSeconds = self::hmToSeconds($baseHm);
        if ($baseSeconds === null) {
            $warnings[] = "Export: invalid '{$symbolicTime}' time returned by FPP ('{$baseHm}') for {$ymd}.";
            return null;
        }

        $withOffsetSeconds = $baseSeconds + ($offsetMinutes * 60);
        $display = self::roundSecondsToNearestHalfHour($withOffsetSeconds);

        return [
            'displayTime' => $display,
            'yaml' => [
                'symbolic' => $symbolicTime,
                'offsetMinutes' => $offsetMinutes,
                'resolvedBy' => 'fppmm:GetSunRiseSet',
                'date' => $ymd,
                'baseTime' => self::hmToHms($baseHm),
                'displayTime' => $display,
                'calendarRounding' => 'nearest-30-minutes',
            ],
        ];
    }

    /* =====================================================================
     * Holiday lookups (bidirectional)
     * ===================================================================== */

    /**
     * Reverse lookup:
     *   2025-12-25 → "Christmas"
     */
    public static function holidayForDate(string $ymd): ?string
    {
        self::ensureLoaded();

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
            return null;
        }

        [, $m, $d] = explode('-', $ymd);
        $key = sprintf('%02d-%02d', (int)$m, (int)$d);

        return self::$holidayByMonthDay[$key] ?? null;
    }

    /**
     * Forward lookup:
     *   "Christmas", 2025 → "2025-12-25"
     */
    public static function dateForHoliday(string $holiday, int $year): ?string
    {
        self::ensureLoaded();

        $h = self::$holidayByName[$holiday] ?? null;
        if (!$h) {
            return null;
        }

        $y = ($h['year'] > 0) ? $h['year'] : $year;

        return sprintf(
            '%04d-%02d-%02d',
            $y,
            $h['month'],
            $h['day']
        );
    }

    /* =====================================================================
     * Symbolic time helpers
     * ===================================================================== */

    private static function isSymbolicTime($v): bool
    {
        return is_string($v) && in_array($v, ['Dawn', 'Dusk', 'SunRise', 'SunSet'], true);
    }

    /**
     * Pick a representative date for a schedule entry so we can resolve sun times.
     * We intentionally keep this as a best-effort heuristic for export display only.
     */
    private static function representativeDateForEntry(array $entry, array &$warnings): ?string
    {
        $sd = (string)($entry['startDate'] ?? '');
        $ed = (string)($entry['endDate'] ?? '');

        // Prefer explicit YYYY-MM-DD if present
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $sd)) {
            // If year is 0000, use current year (export display only)
            if (strpos($sd, '0000-') === 0) {
                $year = (int)date('Y');
                return sprintf('%04d-%s', $year, substr($sd, 5));
            }
            return $sd;
        }

        // Holiday shortName as startDate
        if ($sd !== '' && preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $sd)) {
            $year = (int)date('Y');
            $d = self::dateForHoliday($sd, $year);
            if ($d !== null) {
                return $d;
            }
        }

        // Fall back to endDate if it is explicit YYYY-MM-DD
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ed)) {
            if (strpos($ed, '0000-') === 0) {
                $year = (int)date('Y');
                return sprintf('%04d-%s', $year, substr($ed, 5));
            }
            return $ed;
        }

        // Holiday shortName as endDate
        if ($ed !== '' && preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $ed)) {
            $year = (int)date('Y');
            $d = self::dateForHoliday($ed, $year);
            if ($d !== null) {
                return $d;
            }
        }

        $warnings[] = 'Export: unable to choose a representative date for symbolic time resolution.';
        return null;
    }

    /**
     * Call FPP to retrieve sun times for a given date.
     *
     * Uses: /opt/fpp/bin/fppmm -c GetSunRiseSet -a "YYYY-MM-DD"
     *
     * Returns array keyed by:
     *   Dawn, Dusk, SunRise, SunSet  => "HH:MM"
     *
     * @return array<string,string>|null
     */
    private static function getFppSunTimesForDate(string $ymd, array &$warnings): ?array
    {
        if (isset(self::$sunTimesCache[$ymd])) {
            return self::$sunTimesCache[$ymd];
        }

        $fppmm = '/opt/fpp/bin/fppmm';
        if (!is_file($fppmm) || !is_executable($fppmm)) {
            $warnings[] = "Export: fppmm not available at {$fppmm}; cannot resolve sun times.";
            return null;
        }

        $cmd = escapeshellcmd($fppmm) . ' -c GetSunRiseSet -a ' . escapeshellarg($ymd) . ' 2>/dev/null';

        $out = [];
        $rc = 0;
        @exec($cmd, $out, $rc);

        if ($rc !== 0 || empty($out)) {
            $warnings[] = "Export: fppmm GetSunRiseSet failed for {$ymd} (rc={$rc}).";
            return null;
        }

        $raw = trim(implode("\n", $out));
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            $warnings[] = "Export: fppmm GetSunRiseSet returned non-JSON output for {$ymd}.";
            return null;
        }

        $map = self::coerceSunTimesMap($json);
        if ($map === null) {
            $warnings[] = "Export: fppmm GetSunRiseSet JSON did not contain usable keys for {$ymd}.";
            return null;
        }

        self::$sunTimesCache[$ymd] = $map;
        return $map;
    }

    /**
     * Accept slightly varying JSON shapes and normalize into:
     *   Dawn/Dusk/SunRise/SunSet => HH:MM
     *
     * @param array $json
     * @return array<string,string>|null
     */
    private static function coerceSunTimesMap(array $json): ?array
    {
        // Some FPP versions may wrap in "result"/"data" etc. Try common patterns.
        $candidates = [$json];

        foreach (['result', 'data', 'SunRiseSet', 'sun', 'suntimes'] as $k) {
            if (isset($json[$k]) && is_array($json[$k])) {
                $candidates[] = $json[$k];
            }
        }

        foreach ($candidates as $cand) {
            $out = [];

            foreach (['Dawn', 'Dusk', 'SunRise', 'SunSet'] as $key) {
                if (isset($cand[$key]) && is_string($cand[$key]) && preg_match('/^\d{1,2}:\d{2}$/', $cand[$key])) {
                    $out[$key] = self::normalizeHm($cand[$key]);
                    continue;
                }

                // Try alternate spellings (defensive)
                $alt = match ($key) {
                    'SunRise' => ['Sunrise', 'sunrise', 'sunRise'],
                    'SunSet'  => ['Sunset', 'sunset', 'sunSet'],
                    'Dawn'    => ['dawn'],
                    'Dusk'    => ['dusk'],
                    default   => [],
                };

                foreach ($alt as $a) {
                    if (isset($cand[$a]) && is_string($cand[$a]) && preg_match('/^\d{1,2}:\d{2}$/', $cand[$a])) {
                        $out[$key] = self::normalizeHm($cand[$a]);
                        break;
                    }
                }
            }

            if (!empty($out)) {
                // Only accept if we found at least one meaningful key
                return $out;
            }
        }

        return null;
    }

    private static function normalizeHm(string $hm): string
    {
        [$h, $m] = array_map('intval', explode(':', $hm));
        $h = max(0, min(23, $h));
        $m = max(0, min(59, $m));
        return sprintf('%02d:%02d', $h, $m);
    }

    private static function hmToSeconds(string $hm): ?int
    {
        if (!preg_match('/^\d{1,2}:\d{2}$/', $hm)) {
            return null;
        }
        [$h, $m] = array_map('intval', explode(':', $hm));
        if ($h < 0 || $h > 23 || $m < 0 || $m > 59) {
            return null;
        }
        return ($h * 3600) + ($m * 60);
    }

    private static function hmToHms(string $hm): string
    {
        $hm = self::normalizeHm($hm);
        return $hm . ':00';
    }

    private static function roundSecondsToNearestHalfHour(int $seconds): string
    {
        $seconds = max(0, $seconds);

        $minutes = (int)round($seconds / 60);
        $roundedMinutes = (int)(round($minutes / 30) * 30);

        $hours = intdiv($roundedMinutes, 60);
        $mins  = $roundedMinutes % 60;

        // Clamp to clock range for display
        if ($hours < 0) {
            $hours = 0;
            $mins = 0;
        } elseif ($hours > 23) {
            $hours = 23;
            $mins = 59; // last displayable minute
        }

        return sprintf('%02d:%02d:00', $hours, $mins);
    }

    /* =====================================================================
     * Locale + holiday loading
     * ===================================================================== */

    private static function ensureLoaded(): void
    {
        if (self::$loaded) {
            return;
        }

        self::$loaded = true;

        $locale = self::loadLocaleJson();
        if (!$locale || !isset($locale['holidays']) || !is_array($locale['holidays'])) {
            return;
        }

        foreach ($locale['holidays'] as $h) {
            if (
                !is_array($h) ||
                empty($h['shortName']) ||
                empty($h['month']) ||
                empty($h['day'])
            ) {
                continue;
            }

            $short = (string)$h['shortName'];
            $month = (int)$h['month'];
            $day   = (int)$h['day'];
            $year  = isset($h['year']) ? (int)$h['year'] : 0;

            self::$holidayByName[$short] = [
                'month' => $month,
                'day'   => $day,
                'year'  => $year,
            ];

            if ($year === 0) {
                $key = sprintf('%02d-%02d', $month, $day);
                self::$holidayByMonthDay[$key] = $short;
            }
        }
    }

    /**
     * Load FPP locale JSON and merge user holidays if present.
     */
    private static function loadLocaleJson(): ?array
    {
        $localeName = self::readLocaleSetting() ?? 'Global';

        $base = "/opt/fpp/etc/locale/{$localeName}.json";
        if (!is_file($base)) {
            $base = "/opt/fpp/etc/locale/Global.json";
        }

        $json = self::readJsonFile($base);
        if (!$json) {
            return null;
        }

        $user = "/home/fpp/media/config/user-holidays.json";
        if (is_file($user)) {
            $u = self::readJsonFile($user);
            if (is_array($u)) {
                $json['holidays'] ??= [];
                foreach ($u as $h) {
                    $json['holidays'][] = $h;
                }
            }
        }

        return $json;
    }

    private static function readJsonFile(string $path): ?array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Best-effort locale lookup.
     * Falls back to Global if unavailable.
     */
    private static function readLocaleSetting(): ?string
    {
        $settings = "/home/fpp/media/settings/settings.json";
        if (!is_file($settings)) {
            return null;
        }

        $json = self::readJsonFile($settings);
        return isset($json['Locale']) && is_string($json['Locale']) ? $json['Locale'] : null;
    }
}