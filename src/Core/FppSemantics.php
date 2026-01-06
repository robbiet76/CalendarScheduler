<?php
declare(strict_types=1);

/**
 * FppSemantics
 *
 * Central authority for Falcon Player (FPP) domain semantics.
 *
 * RESPONSIBILITIES (Phase 31+):
 * - Load FPP locale definitions (holidays, names, metadata)
 * - Merge user-defined holidays
 * - Provide helpers for resolving FPP-specific tokens:
 *     - Holiday names (e.g. Christmas, Epiphany)
 *     - Day enums
 *     - Sentinel dates (0000-*)
 *     - Dusk/Dawn (future)
 *
 * IMPORTANT:
 * - This class MUST be side-effect free
 * - No scheduler writes
 * - No network calls
 * - File reads only
 *
 * This file intentionally starts as a skeleton and will be
 * expanded incrementally during Phase 31.
 */
final class FppSemantics
{
    /**
     * Cached locale JSON (merged locale + user holidays)
     *
     * @var array<string,mixed>|null
     */
    private static ?array $locale = null;

    /**
     * Load and return the active FPP locale definition.
     *
     * Mirrors FPP logic:
     * - Determine locale name (default: Global)
     * - Load /opt/fpp/etc/locale/<Locale>.json
     * - Fallback to Global.json
     * - Merge /home/fpp/media/config/user-holidays.json if present
     *
     * @return array<string,mixed>
     */
    public static function getLocale(): array
    {
        if (self::$locale !== null) {
            return self::$locale;
        }

        $localeName = self::getConfiguredLocaleName();
        $basePath   = '/opt/fpp/etc/locale/';

        $localeFile = $basePath . $localeName . '.json';
        if (!is_file($localeFile)) {
            $localeFile = $basePath . 'Global.json';
        }

        $locale = self::loadJsonFile($localeFile);

        // Merge user-defined holidays (if any)
        $userHolidaysFile = '/home/fpp/media/config/user-holidays.json';
        if (is_file($userHolidaysFile)) {
            $userHolidays = self::loadJsonFile($userHolidaysFile);

            if (isset($userHolidays[0]) && is_array($userHolidays)) {
                if (!isset($locale['holidays']) || !is_array($locale['holidays'])) {
                    $locale['holidays'] = [];
                }

                foreach ($userHolidays as $holiday) {
                    if (is_array($holiday)) {
                        $locale['holidays'][] = $holiday;
                    }
                }
            }
        }

        self::$locale = $locale;
        return self::$locale;
    }

    /**
     * Return configured FPP locale name.
     *
     * For now:
     * - Use Global if not determinable
     *
     * NOTE:
     * FPP stores this in settings; we intentionally
     * avoid shelling out or parsing settings files
     * until absolutely required.
     */
    private static function getConfiguredLocaleName(): string
    {
        // Safe default; future enhancement may read FPP settings
        return 'Global';
    }

    /**
     * Load and decode a JSON file into an associative array.
     *
     * @return array<string,mixed>
     */
    private static function loadJsonFile(string $path): array
    {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    /* -----------------------------------------------------------------
     * Placeholder APIs (Phase 31.1+)
     * -----------------------------------------------------------------
     * These are intentionally NO-OP for now.
     * They document intent and provide safe extension points.
     */

        /**
     * Normalize a raw FPP scheduler entry into a form
     * suitable for export or planning.
     *
     * CURRENT BEHAVIOR:
     * - Pass-through (no modification)
     *
     * FUTURE:
     * - Resolve holidays
     * - Normalize sentinel dates
     * - Resolve Dusk/Dawn
     * - Expand FPP-specific fields
     *
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    public static function normalizeScheduleEntry(array $entry): array
    {
        return $entry;
    }
    
    /**
     * Resolve an FPP holiday token to a concrete YYYY-MM-DD date.
     *
     * @param string $token Holiday shortName (e.g. "Christmas")
     * @param int    $year  Target year
     *
     * @return string|null  YYYY-MM-DD or null if unresolved
     */
    public static function resolveHoliday(string $token, int $year): ?string
    {
        // Implemented in Phase 31.1
        return null;
    }

    /**
     * Determine whether a value is an FPP sentinel date (0000-*)
     */
    public static function isSentinelDate(string $date): bool
    {
        return str_starts_with($date, '0000-');
    }

    /**
     * Clear cached locale (for testing only)
     */
    public static function clearCache(): void
    {
        self::$locale = null;
    }
}