#!/usr/bin/php
<?php
declare(strict_types=1);

// -----------------------------------------------------------------------------
// CLI safety: prevent FPP web UI assumptions and suppress output
// -----------------------------------------------------------------------------
if (php_sapi_name() === 'cli') {
    ob_start();
}

if (!isset($_SERVER['REQUEST_URI'])) {
    $_SERVER['REQUEST_URI'] = '';
}

/**
 * FPP Environment Export (PHP)
 *
 * Authoritative runtime snapshot for Calendar Scheduler.
 * Must be executed within the FPP web PHP context to expose locale and holidays.
 * Replaces legacy C++ exporter.
 */

require_once '/opt/fpp/www/common.php';
LoadLocale();

$outPath = '/home/fpp/media/config/calendar-scheduler/runtime/fpp-env.json';
$tmpPath = $outPath . '.tmp';

$result = [
    'schemaVersion' => 1,
    'source'        => 'fpp-env-export-php',
    'ok'            => true,
    'errors'        => [],
];

// --------------------------------------------------
// Settings
// --------------------------------------------------
$lat = (float)($settings['Latitude']  ?? 0);
$lon = (float)($settings['Longitude'] ?? 0);
$tz  = (string)($settings['TimeZone'] ?? '');

$result['latitude']  = $lat;
$result['longitude'] = $lon;
$result['timezone']  = $tz;

if ($lat === 0.0 || $lon === 0.0) {
    $result['ok'] = false;
    $result['errors'][] = 'Latitude/Longitude not present or zero';
}

if ($tz === '') {
    $result['ok'] = false;
    $result['errors'][] = 'Timezone not present';
}

// --------------------------------------------------
// Locale / holidays (authoritative via FPP runtime)
// --------------------------------------------------
$result['rawLocale'] = null;

try {
    if (class_exists('FPPLocale')) {
        $localeObj = \FPPLocale::GetLocale();
        if ($localeObj !== null) {
            $result['rawLocale'] = [
                'name'     => $localeObj->name ?? null,
                'holidays' => $localeObj->holidays ?? [],
            ];
        } else {
            $result['errors'][] = 'FPPLocale returned null';
        }
    } else {
        $result['errors'][] = 'FPPLocale unavailable (not running in FPP web context)';
    }
} catch (\Throwable $e) {
    $result['errors'][] = 'Locale fetch failed: ' . $e->getMessage();
}

if ($result['rawLocale'] === null) {
    $result['ok'] = false;
}

// --------------------------------------------------
// Write atomically
// --------------------------------------------------
$json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    fwrite(STDERR, "ERROR: Failed to encode JSON\n");
    exit(2);
}

if (file_put_contents($tmpPath, $json) === false) {
    fwrite(STDERR, "ERROR: Unable to write temp file\n");
    exit(2);
}

rename($tmpPath, $outPath);
chmod($outPath, 0664);

if (php_sapi_name() === 'cli') {
    ob_end_clean();
}

exit(0);