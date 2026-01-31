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
 * Must be executed on an FPP system; reads locale/holidays from FPP locale JSON on disk.
 * Replaces legacy C++ exporter.
 */


require_once '/opt/fpp/www/config.php';

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
// Locale / holidays (authoritative via FPP locale JSON)
// --------------------------------------------------
$result['rawLocale'] = null;

$localeName = (string)($settings['Locale'] ?? '');
$localeName = trim($localeName);

$localeCandidates = [];
if ($localeName !== '') {
    $localeCandidates[] = "/opt/fpp/etc/locale/{$localeName}.json";
    $localeCandidates[] = "/opt/fpp/etc/locale/{$localeName}.JSON";
    $localeCandidates[] = "/home/fpp/media/config/locale/{$localeName}.json";
    $localeCandidates[] = "/home/fpp/media/config/locale/{$localeName}.JSON";
}

// Fallbacks if the above paths don't exist
$localeCandidates[] = '/opt/fpp/etc/locale.json';
$localeCandidates[] = '/home/fpp/media/config/locale.json';

// If we have a locale directory, try to pick a JSON from it.
$localeDir = '/opt/fpp/etc/locale';
if (is_dir($localeDir)) {
    $glob = glob($localeDir . '/*.json');
    if (is_array($glob) && count($glob) > 0) {
        // Prefer the requested locale name if present, otherwise first JSON.
        $preferred = $localeName !== '' ? ($localeDir . '/' . $localeName . '.json') : null;
        if ($preferred !== null && file_exists($preferred)) {
            array_unshift($localeCandidates, $preferred);
        } else {
            array_unshift($localeCandidates, $glob[0]);
        }
    }
}

$localePathUsed = null;
foreach ($localeCandidates as $p) {
    if (is_string($p) && $p !== '' && file_exists($p)) {
        $localePathUsed = $p;
        break;
    }
}

try {
    if ($localePathUsed === null) {
        $result['errors'][] = 'Locale file missing';
    } else {
        $raw = file_get_contents($localePathUsed);
        if ($raw === false) {
            $result['errors'][] = 'Locale file unreadable: ' . $localePathUsed;
        } else {
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                $result['errors'][] = 'Locale file JSON invalid: ' . $localePathUsed;
            } else {
                // Normalize to the shape our plugin expects.
                $result['rawLocale'] = [
                    'name'     => (string)($decoded['name'] ?? ($decoded['Name'] ?? $localeName)),
                    'holidays' => $decoded['holidays'] ?? ($decoded['Holidays'] ?? []),
                ];
                if (!is_array($result['rawLocale']['holidays'])) {
                    $result['rawLocale']['holidays'] = [];
                }
                $result['rawLocale']['_sourcePath'] = $localePathUsed;
            }
        }
    }
} catch (\Throwable $e) {
    $result['errors'][] = 'Locale read failed: ' . $e->getMessage();
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