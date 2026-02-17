<?php
declare(strict_types=1);

/**
 * Calendar Scheduler — Plugin Lifecycle Entry
 *
 * File: plugin.php
 * Purpose: Execute lightweight plugin startup tasks in FPP web context,
 * primarily exporting runtime environment metadata for scheduler components.
 */

// ---------------------------------------------------------------------
// HARD GUARD: prevent execution under CLI
// ---------------------------------------------------------------------
if (PHP_SAPI === 'cli') {
    return;
}

$pluginName = 'CalendarScheduler';
$pluginRoot = __DIR__;

$exporter   = $pluginRoot . '/bin/gcs-export';
$runtimeDir = $pluginRoot . '/runtime';
$envFile    = $runtimeDir . '/fpp-env.json';

// ---------------------------------------------------------------------
// Ensure runtime directory exists
// ---------------------------------------------------------------------
if (!is_dir($runtimeDir)) {
    if (!@mkdir($runtimeDir, 0755, true) && !is_dir($runtimeDir)) {
        error_log('[CS] Failed to create runtime directory: ' . $runtimeDir);
        return;
    }
}

// ---------------------------------------------------------------------
// Run exporter (web-context only)
// ---------------------------------------------------------------------
if (is_executable($exporter)) {
    /*
     * IMPORTANT:
     * - Set working directory so libfpp does not attempt to write
     *   media_root.txt in unexpected locations.
     * - Silence stdout/stderr; rely on exit code + log on failure.
     */
    chdir($pluginRoot);

    exec(
        escapeshellcmd($exporter) . ' >/dev/null 2>&1',
        $out,
        $rc
    );

    if ($rc !== 0) {
        error_log("[CS] gcs-export failed with exit code {$rc}");
    }
} else {
    error_log('[CS] gcs-export binary missing or not executable');
}
