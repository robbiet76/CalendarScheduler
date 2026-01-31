<?php
declare(strict_types=1);

// --- FPP runtime globals (static analysis only) ---
/** @noinspection PhpUndefinedFunctionInspection */
if (false) {
    function GetSettingValue(string $key): string {
        return '';
    }
}

namespace CalendarScheduler\Platform;

use RuntimeException;

function exportFppEnv(string $outputPath): void
{
    $result = [
        'schemaVersion' => 1,
        'source'        => 'fpp-env-export-php',
        'ok'            => false,
        'errors'        => [],
    ];

    try {
        // These globals are only available in FPP web context
        if (!function_exists('GetSettingValue')) {
            throw new RuntimeException('Not running in FPP web context');
        }

        $result['latitude']  = (float)\GetSettingValue('Latitude');
        $result['longitude'] = (float)\GetSettingValue('Longitude');
        $result['timezone']  = \GetSettingValue('TimeZone');

        // Locale (holidays)
        if (class_exists('LocaleHolder')) {
            $locale = \LocaleHolder::GetLocale();
            $result['rawLocale'] = json_decode(
                json_encode($locale, JSON_THROW_ON_ERROR),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
            $result['ok'] = true;
        } else {
            throw new RuntimeException('FPPLocale unavailable');
        }

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