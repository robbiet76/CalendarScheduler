<?php
declare(strict_types=1);

/**
 * Calendar Scheduler — Plugin Lifecycle Entry
 *
 * File: plugin.php
 * Purpose: Run lightweight plugin startup tasks in FPP web context.
 */

use CalendarScheduler\Platform;

if (PHP_SAPI === 'cli') {
    return;
}

$pluginRoot = __DIR__;
$runtimeDir = $pluginRoot . '/runtime';
$envFile = $runtimeDir . '/fpp-env.json';

if (!is_dir($runtimeDir)) {
    if (!@mkdir($runtimeDir, 0755, true) && !is_dir($runtimeDir)) {
        error_log('[CS] Failed to create runtime directory: ' . $runtimeDir);
        return;
    }
}

require_once $pluginRoot . '/bootstrap.php';
require_once $pluginRoot . '/src/Platform/FppEnvExporter.php';

// Export current FPP environment snapshot for scheduler components.
try {
    Platform\exportFppEnv($envFile);
} catch (\Throwable $e) {
    error_log('[CS] FPP environment export failed: ' . $e->getMessage());
}
