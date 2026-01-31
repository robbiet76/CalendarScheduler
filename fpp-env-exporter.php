<?php
declare(strict_types=1);

/**
 * Pure FPP environment exporter.
 * No UI output. Safe for CLI or web include.
 */

function calendarSchedulerExportFppEnv(): void
{
    require_once '/opt/fpp/www/config.php';

    $outPath = '/home/fpp/media/config/calendar-scheduler/runtime/fpp-env.json';
    $tmpPath = $outPath . '.tmp';

    $result = [
        'schemaVersion' => 1,
        'source'        => 'fpp-env-export-php',
        'ok'            => true,
        'errors'        => [],
    ];

    // ------------------
    // Core settings
    // ------------------
    $lat = (float)($settings['Latitude']  ?? 0);
    $lon = (float)($settings['Longitude'] ?? 0);
    $tz  = (string)($settings['TimeZone'] ?? '');

    $result['latitude']  = $lat;
    $result['longitude'] = $lon;
    $result['timezone']  = $tz;

    if ($lat === 0.0 || $lon === 0.0) {
        $result['ok'] = false;
        $result['errors'][] = 'Latitude/Longitude not present';
    }

    if ($tz === '') {
        $result['ok'] = false;
        $result['errors'][] = 'Timezone not present';
    }

    // ------------------
    // Locale / holidays
    // ------------------
    $result['rawLocale'] = null;

    $localeName = trim((string)($settings['Locale'] ?? ''));

    $candidates = [];
    if ($localeName !== '') {
        $candidates[] = "/opt/fpp/etc/locale/{$localeName}.json";
        $candidates[] = "/home/fpp/media/config/locale/{$localeName}.json";
    }

    $candidates[] = '/opt/fpp/etc/locale.json';
    $candidates[] = '/home/fpp/media/config/locale.json';

    $localePath = null;
    foreach ($candidates as $p) {
        if (is_file($p)) {
            $localePath = $p;
            break;
        }
    }

    if ($localePath === null) {
        $result['ok'] = false;
        $result['errors'][] = 'Locale file missing';
    } else {
        $decoded = json_decode(file_get_contents($localePath), true);
        if (!is_array($decoded)) {
            $result['ok'] = false;
            $result['errors'][] = 'Locale JSON invalid';
        } else {
            $result['rawLocale'] = [
                'name'     => $decoded['name'] ?? $localeName,
                'holidays' => $decoded['holidays'] ?? [],
                '_source'  => $localePath,
            ];
        }
    }

    if ($result['rawLocale'] === null) {
        $result['ok'] = false;
    }

    // ------------------
    // Atomic write
    // ------------------
    $json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return;
    }

    if (file_put_contents($tmpPath, $json) !== false) {
        rename($tmpPath, $outPath);
        chmod($outPath, 0664);
    }
}