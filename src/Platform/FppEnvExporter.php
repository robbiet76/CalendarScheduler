<?php
declare(strict_types=1);

namespace CalendarScheduler\Platform;

use RuntimeException;

function exportFppEnv(string $outputPath): void
{
    $result = [
        'schemaVersion' => 1,
        'source'        => 'fpp-env-export-php',
        'generatedAt'   => gmdate('c'),
        'generatedAtEpoch' => time(),
        'ok'            => false,
        'errors'        => [],
    ];

    try {
        // Prefer $settings from FPP's web config (most reliable in plugin.php context)
        $settings = null;
        if (isset($GLOBALS['settings']) && is_array($GLOBALS['settings'])) {
            $settings = $GLOBALS['settings'];
        }

        // Fallback: GetSettingValue() if present (some contexts)
        $get = static function (string $key) use ($settings): ?string {
            if (is_array($settings) && array_key_exists($key, $settings)) {
                $v = $settings[$key];
                if (is_string($v) || is_numeric($v)) {
                    return (string) $v;
                }
            }
            if (function_exists('GetSettingValue')) {
                /** @var callable $fn */
                $fn = 'GetSettingValue';
                $v = $fn($key);
                if (is_string($v) || is_numeric($v)) {
                    return (string) $v;
                }
            }
            return null;
        };

        // Optional FPP scheduling year bounds (exposed by FPP web UI)
        if (isset($GLOBALS['MINYEAR']) && is_numeric($GLOBALS['MINYEAR'])) {
            $result['minYear'] = (int) $GLOBALS['MINYEAR'];
        }
        if (isset($GLOBALS['MAXYEAR']) && is_numeric($GLOBALS['MAXYEAR'])) {
            $result['maxYear'] = (int) $GLOBALS['MAXYEAR'];
        }

        $lat = $get('Latitude');
        $lon = $get('Longitude');
        $tz  = $get('TimeZone') ?? $get('TimeZoneName') ?? $get('timezone');
        $loc = $get('Locale');

        if ($lat !== null) $result['latitude']  = (float) $lat;
        if ($lon !== null) $result['longitude'] = (float) $lon;
        if ($tz  !== null) $result['timezone']  = $tz;

        if ($loc === null || $loc === '') {
            throw new RuntimeException('Locale setting missing');
        }

        // Load locale JSON directly (this avoids LocaleHolder dependency)
        $localePath = "/opt/fpp/etc/locale/{$loc}.json";
        if (!is_file($localePath)) {
            throw new RuntimeException("Locale file missing: {$localePath}");
        }

        $rawLocale = json_decode(
            file_get_contents($localePath),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        if (!is_array($rawLocale)) {
            throw new RuntimeException("Locale JSON invalid: {$localePath}");
        }

        $rawLocale['_source'] = $localePath;
        $result['rawLocale']  = $rawLocale;
        $result['ok']         = true;

    } catch (\Throwable $e) {
        $result['errors'][] = $e->getMessage();
    }

    $dir = dirname($outputPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    file_put_contents(
        $outputPath,
        json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
    );
}
