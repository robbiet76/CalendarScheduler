<?php
declare(strict_types=1);

namespace CalendarScheduler\Adapter\Calendar;

use CalendarScheduler\Platform\HolidayResolver;
use CalendarScheduler\Platform\SunTimeDisplayEstimator;

final class MapperShared
{
    private const FPP_RUNTIME_PATH = '/home/fpp/media/config/calendar-scheduler/runtime/fpp-runtime.json';
    private const UI_PREFS_PATH = '/home/fpp/media/config/calendar-scheduler/runtime/ui-prefs.json';

    public static function extractExecutionOrder(array $subEvent): ?int
    {
        $value = $subEvent['executionOrder'] ?? null;
        if (is_int($value)) {
            return $value >= 0 ? $value : 0;
        }
        if (is_string($value) && is_numeric($value)) {
            $n = (int)$value;
            return $n >= 0 ? $n : 0;
        }
        return null;
    }

    public static function extractExecutionOrderManual(array $subEvent): ?bool
    {
        $value = $subEvent['executionOrderManual'] ?? null;
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            return is_bool($parsed) ? $parsed : null;
        }
        return null;
    }

    public static function extractYearFromHardDate(?string $date): ?int
    {
        if (!is_string($date) || $date === '') {
            return null;
        }
        if (!preg_match('/^(\d{4})-\d{2}-\d{2}$/', $date, $m)) {
            return null;
        }
        $year = (int)$m[1];
        return $year > 0 ? $year : null;
    }

    public static function resolveLocalTimezone(): \DateTimeZone
    {
        $json = self::readEnvJson();
        $tzName = self::extractTimezoneName($json);
        if (is_string($tzName) && trim($tzName) !== '') {
            try {
                return new \DateTimeZone(trim($tzName));
            } catch (\Throwable) {
                // fall through
            }
        }

        try {
            return new \DateTimeZone(date_default_timezone_get());
        } catch (\Throwable) {
            return new \DateTimeZone('UTC');
        }
    }

    public static function loadHolidayResolver(): ?HolidayResolver
    {
        $json = self::readEnvJson();
        if (!is_array($json)) {
            return null;
        }

        $holidays = $json['holidays'] ?? ($json['rawLocale']['holidays'] ?? null);
        if (!is_array($holidays)) {
            return null;
        }

        return new HolidayResolver($holidays);
    }

    /**
     * @return array{0:?float,1:?float}
     */
    public static function loadCoordinates(): array
    {
        $json = self::readEnvJson();
        if (!is_array($json)) {
            return [null, null];
        }

        $lat = $json['latitude'] ?? ($json['settings']['Latitude'] ?? null);
        $lon = $json['longitude'] ?? ($json['settings']['Longitude'] ?? null);
        if (!is_numeric($lat) || !is_numeric($lon)) {
            return [null, null];
        }

        return [(float)$lat, (float)$lon];
    }

    public static function resolveSymbolicDisplayTime(
        string $date,
        string $symbolic,
        int $offset,
        ?float $latitude,
        ?float $longitude,
        string $timezoneName
    ): ?string {
        $symbolic = trim($symbolic);
        if ($symbolic === '') {
            return null;
        }

        if ($latitude !== null && $longitude !== null) {
            $estimated = SunTimeDisplayEstimator::estimate(
                $date,
                $symbolic,
                $latitude,
                $longitude,
                $timezoneName,
                $offset,
                30
            );
            if (is_string($estimated) && $estimated !== '') {
                return $estimated;
            }
        }

        $base = match ($symbolic) {
            'Dawn' => '06:00:00',
            'SunRise' => '07:00:00',
            'SunSet' => '18:00:00',
            'Dusk' => '18:30:00',
            default => null,
        };
        if (!is_string($base)) {
            return null;
        }

        $dt = new \DateTimeImmutable($date . ' ' . $base, new \DateTimeZone('UTC'));
        $dt = $dt->modify(($offset >= 0 ? '+' : '') . (string)$offset . ' minutes');
        return $dt->format('H:i:s');
    }

    public static function isManagedColorEnforced(): bool
    {
        $prefs = self::readUiPrefs();
        $value = $prefs['enforce_managed_colors'] ?? false;
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    public static function managedGoogleColorId(string $type, bool $enabled): string
    {
        return match (self::managedStyleToken($type, $enabled)) {
            'disabled' => '8',
            'sequence' => '10',
            'command' => '6',
            default => '9',
        };
    }

    /**
     * @return array<int,string>
     */
    public static function managedOutlookCategories(string $type, bool $enabled): array
    {
        return [match (self::managedStyleToken($type, $enabled)) {
            'disabled' => 'Gray category',
            'sequence' => 'Green category',
            'command' => 'Red category',
            default => 'Blue category',
        }];
    }

    /**
     * @return array<string,string> displayName => graph color enum
     */
    public static function managedOutlookMasterCategoryColors(): array
    {
        return [
            'Blue category' => 'preset7',
            'Green category' => 'preset4',
            'Red category' => 'preset0',
            'Gray category' => 'preset12',
        ];
    }

    public static function managedStyleToken(string $type, bool $enabled): string
    {
        if (!$enabled) {
            return 'disabled';
        }

        return match (strtolower(trim($type))) {
            'sequence' => 'sequence',
            'command' => 'command',
            default => 'playlist',
        };
    }

    public static function googleColorIdToStyleToken(?string $colorId): ?string
    {
        if (!is_string($colorId) || trim($colorId) === '') {
            return null;
        }

        $normalized = trim($colorId);
        return match ($normalized) {
            '8' => 'disabled',
            '10' => 'sequence',
            '6' => 'command',
            '9' => 'playlist',
            default => 'custom:google:' . strtolower($normalized),
        };
    }

    /**
     * @param array<int,string> $categories
     */
    public static function outlookCategoriesToStyleToken(array $categories): ?string
    {
        foreach ($categories as $category) {
            if (!is_string($category) || trim($category) === '') {
                continue;
            }
            $normalized = strtolower(trim($category));
            return match ($normalized) {
                'gray category' => 'disabled',
                'green category' => 'sequence',
                'red category' => 'command',
                'blue category' => 'playlist',
                'cs disabled' => 'disabled',
                'cs sequence' => 'sequence',
                'cs command' => 'command',
                'cs playlist' => 'playlist',
                default => 'custom:outlook:' . $normalized,
            };
        }

        return null;
    }

    public static function composeManagedDescription(
        string $existingDescription,
        string $type,
        bool $enabled,
        string $repeat,
        string $stopType,
        ?array $startTime,
        ?array $endTime
    ): string {
        $startSym = is_string($startTime['symbolic'] ?? null) ? trim((string)$startTime['symbolic']) : '';
        $endSym = is_string($endTime['symbolic'] ?? null) ? trim((string)$endTime['symbolic']) : '';
        $startOffset = isset($startTime['offset']) ? (int)($startTime['offset']) : 0;
        $endOffset = isset($endTime['offset']) ? (int)($endTime['offset']) : 0;

        $settings = [];
        $settings[] = '# Managed by Calendar Scheduler';
        $settings[] = '# Edit values below. Free-form notes can be added at the bottom.';
        $settings[] = '';
        $settings[] = '[settings]';
        $settings[] = '# Edit FPP Scheduler Settings';
        $settings[] = '# Schedule Type: Playlist | Sequence | Command';
        $settings[] = '# Enabled: True | False';
        $settings[] = '# Repeat: None | Immediate | 5 | 10 | 15 | 20 | 30 | 60 (Min.)';
        $settings[] = '# Stop Type: Graceful | Graceful Loop | Hard Stop';
        $settings[] = '';
        $settings[] = 'type = ' . self::formatTypeForDescription($type);
        $settings[] = 'enabled = ' . ($enabled ? 'True' : 'False');
        $settings[] = 'repeat = ' . self::formatRepeatForDescription($repeat);
        $settings[] = 'stopType = ' . self::formatStopTypeForDescription($stopType);
        $settings[] = '';
        $settings[] = '[symbolic_time]';
        $settings[] = '# Edit Symbolic Time Settings';
        $settings[] = '# Start Time/End Time: Dawn | SunRise | SunSet | Dusk';
        $settings[] = '# Start Time/End Time Offset Min: (Enter +/- minutes)';
        $settings[] = '# Leave values blank to use hard clock time from event start/end.';
        $settings[] = '';
        $settings[] = 'start = ' . $startSym;
        $settings[] = 'start_offset = ' . (string)$startOffset;
        $settings[] = 'end = ' . $endSym;
        $settings[] = 'end_offset = ' . (string)$endOffset;
        $settings[] = '';
        $settings[] = '# Notes:';
        $settings[] = '# - Calendar Event Title should match Playlist/Sequence/Command name.';
        $settings[] = '';
        $settings[] = '# -------------------- USER NOTES BELOW --------------------';

        $sections = [implode("\n", $settings)];

        $existingDescription = trim(self::stripManagedSections($existingDescription));
        if ($existingDescription !== '') {
            $sections[] = $existingDescription;
        }

        return implode("\n\n", $sections);
    }

    public static function stripManagedSections(string $description): string
    {
        $divider = '# -------------------- USER NOTES BELOW --------------------';
        $pos = strpos($description, $divider);
        if ($pos !== false) {
            $notes = substr($description, $pos + strlen($divider));
            return trim((string)$notes);
        }

        $lines = preg_split('/\r\n|\r|\n/', $description);
        if (!is_array($lines) || $lines === []) {
            return trim($description);
        }

        $out = [];
        $inManaged = false;
        $seenMarker = false;

        foreach ($lines as $line) {
            $trim = trim((string)$line);
            $lower = strtolower($trim);

            if (!$seenMarker && $lower === '# managed by calendar scheduler') {
                $seenMarker = true;
                continue;
            }
            if ($seenMarker && $lower === '# edit values below. free-form notes can be added at the bottom.') {
                continue;
            }

            if (!$inManaged && ($lower === '[settings]' || $lower === '[symbolic_time]')) {
                $inManaged = true;
                continue;
            }

            if ($inManaged) {
                if ($trim === '') {
                    $inManaged = false;
                }
                continue;
            }

            $out[] = (string)$line;
        }

        return trim(implode("\n", $out));
    }

    private static function formatTypeForDescription(string $type): string
    {
        return match (strtolower(trim($type))) {
            'sequence' => 'Sequence',
            'command' => 'Command',
            default => 'Playlist',
        };
    }

    private static function formatRepeatForDescription(string $repeat): string
    {
        $r = strtolower(trim($repeat));
        if ($r === 'immediate') {
            return 'Immediate';
        }
        if ($r === 'none' || $r === '') {
            return 'None';
        }
        if (preg_match('/^(\d+)min$/', $r, $m) === 1) {
            return $m[1];
        }
        if (ctype_digit($r)) {
            $n = (int)$r;
            if ($n > 0) {
                return (string)$n;
            }
        }

        return 'None';
    }

    private static function formatStopTypeForDescription(string $stopType): string
    {
        $v = strtolower(trim($stopType));
        return match ($v) {
            'hard', 'hard_stop', 'hard stop' => 'Hard Stop',
            'graceful_loop', 'graceful loop' => 'Graceful Loop',
            default => 'Graceful',
        };
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function readEnvJson(): ?array
    {
        if (!is_file(self::FPP_RUNTIME_PATH)) {
            return null;
        }

        $raw = @file_get_contents(self::FPP_RUNTIME_PATH);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $json = @json_decode($raw, true);
        return is_array($json) ? $json : null;
    }

    private static function extractTimezoneName(?array $json): ?string
    {
        if (!is_array($json)) {
            return null;
        }

        $candidates = [
            $json['timezone'] ?? null,
            $json['settings']['TimeZone'] ?? null,
            $json['settings']['TimeZoneName'] ?? null,
            $json['settings']['timezone'] ?? null,
        ];
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private static function readUiPrefs(): array
    {
        if (!is_file(self::UI_PREFS_PATH)) {
            return [];
        }

        $raw = @file_get_contents(self::UI_PREFS_PATH);
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $json = @json_decode($raw, true);
        return is_array($json) ? $json : [];
    }
}
