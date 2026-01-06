<?php
declare(strict_types=1);

/**
 * FppSemantics
 *
 * Centralized Falcon Player (FPP) semantic knowledge:
 * - Holidays (bidirectional lookup)
 * - Locale awareness
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

    /**
     * Normalize a scheduler entry (placeholder – step 1 stub).
     * For now this is a pass-through.
     */
    public static function normalizeScheduleEntry(array $entry, array &$warnings): array
    {
        self::ensureLoaded();
        return $entry;
    }

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

        [$y, $m, $d] = explode('-', $ymd);
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

    /* -----------------------------------------------------------------
     * Internal loading
     * ----------------------------------------------------------------- */

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

            // Only month/day based holidays participate in reverse lookup
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

        // Merge user holidays
        $user = "/home/fpp/media/config/user-holidays.json";
        if (is_file($user)) {
            $u = self::readJsonFile($user);
            if (isset($u) && is_array($u)) {
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
        return $json['Locale'] ?? null;
    }
}