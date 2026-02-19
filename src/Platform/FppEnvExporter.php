<?php
declare(strict_types=1);

/**
 * Calendar Scheduler — Source Component
 *
 * File: Platform/FppEnvExporter.php
 * Purpose: Export authoritative FPP runtime context (settings, locale, and
 * timing bounds) into a JSON snapshot consumed by scheduler logic.
 */

namespace CalendarScheduler\Platform;

use RuntimeException;

function exportFppEnv(string $outputPath): void
{
    // Keep generatedAtEpoch monotonic to support deterministic comparisons.
    $previousEpoch = 0;
    if (is_file($outputPath)) {
        $existing = @file_get_contents($outputPath);
        if ($existing !== false) {
            $decoded = @json_decode($existing, true);
            if (is_array($decoded) && isset($decoded['generatedAtEpoch']) && is_numeric($decoded['generatedAtEpoch'])) {
                $previousEpoch = (int)$decoded['generatedAtEpoch'];
            }
        }
    }
    $epoch = time();
    if ($epoch <= $previousEpoch) {
        $epoch = $previousEpoch + 1;
    }
    $result = [
        // Base output envelope used by downstream loaders.
        'schemaVersion' => 1,
        'source'        => 'fpp-env-export-php',
        'generatedAt'   => gmdate('c'),
        'generatedAtEpoch' => $epoch,
        'ok'            => false,
        'errors'        => [],
    ];

    try {
        // Prefer $settings from FPP web bootstrap (most reliable in plugin.php context).
        $settings = null;
        if (isset($GLOBALS['settings']) && is_array($GLOBALS['settings'])) {
            $settings = $GLOBALS['settings'];
        }

        // Fallback helper for contexts where only GetSettingValue() is exposed.
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

        // Capture year bounds when available server-side.
        if (isset($GLOBALS['MINYEAR']) && is_numeric($GLOBALS['MINYEAR'])) {
            $result['minYear'] = (int) $GLOBALS['MINYEAR'];
        }
        if (isset($GLOBALS['MAXYEAR']) && is_numeric($GLOBALS['MAXYEAR'])) {
            $result['maxYear'] = (int) $GLOBALS['MAXYEAR'];
        }

        // Pull location/timezone settings that influence symbolic time resolution.
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

        // Load locale JSON directly to avoid UI-only dependencies.
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
        // Export failures are encoded in payload for callers to inspect.
        $result['errors'][] = $e->getMessage();
    }

    // Ensure output directory exists before writing snapshot.
    $dir = dirname($outputPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    // Emit deterministic pretty JSON for troubleshooting and diffs.
    file_put_contents(
        $outputPath,
        json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
    );
}
