#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Phase 1: Minimal, authoritative FPP environment exporter.
 *
 * GOAL:
 * - Prove we can write a fresh fpp-env.json from CLI
 * - Using real FPP globals + LocaleHolder
 * - No web, no plugin routing, no namespaces
 */

// -----------------------------------------------------------------------------
// Hard dependency: must be running on FPP
// -----------------------------------------------------------------------------

require_once '/opt/fpp/www/config.php';

// -----------------------------------------------------------------------------
// Output path
// -----------------------------------------------------------------------------

$outputPath = '/home/fpp/media/config/calendar-scheduler/runtime/fpp-env.json';

// -----------------------------------------------------------------------------
// Base structure
// -----------------------------------------------------------------------------

$result = [
    'schemaVersion' => 1,
    'source'        => 'phase1-cli',
    'ok'            => false,
    'errors'        => [],
];

try {
    // -------------------------------------------------------------------------
    // Required FPP globals
    // -------------------------------------------------------------------------

    if (!function_exists('GetSettingValue')) {
        throw new RuntimeException('GetSettingValue() not available');
    }

    if (!class_exists('LocaleHolder')) {
        throw new RuntimeException('LocaleHolder not available');
    }

    // -------------------------------------------------------------------------
    // Core environment
    // -------------------------------------------------------------------------

    $result['latitude']  = (float) GetSettingValue('Latitude');
    $result['longitude'] = (float) GetSettingValue('Longitude');
    $result['timezone']  = GetSettingValue('TimeZone');

    // -------------------------------------------------------------------------
    // Locale + holidays (authoritative source)
    // -------------------------------------------------------------------------

    $locale = LocaleHolder::GetLocale();

    // Convert Json::Value → PHP array safely
    $result['rawLocale'] = json_decode(
        json_encode($locale, JSON_THROW_ON_ERROR),
        true,
        512,
        JSON_THROW_ON_ERROR
    );

    $result['ok'] = true;

} catch (Throwable $e) {
    $result['errors'][] = $e->getMessage();
}

// -----------------------------------------------------------------------------
// Ensure directory exists
// -----------------------------------------------------------------------------

$dir = dirname($outputPath);
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}

// -----------------------------------------------------------------------------
// Write file (this is the ONLY side effect we care about)
// -----------------------------------------------------------------------------

file_put_contents(
    $outputPath,
    json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
);

exit($result['ok'] ? 0 : 1);