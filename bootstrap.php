<?php
declare(strict_types=1);

/**
 * Google Calendar Scheduler — V2 Bootstrap
 *
 * Responsibilities:
 * - Define plugin runtime boundaries
 * - Register autoloading
 * - Initialize shared infrastructure (logging, config)
 *
 * Explicitly does NOT:
 * - Read calendars
 * - Load or mutate the manifest
 * - Touch FPP scheduler state
 * - Execute planner or apply logic
 */

// -----------------------------------------------------------------------------
// Autoload
// -----------------------------------------------------------------------------

$pluginRoot = __DIR__;
$srcRoot    = $pluginRoot . '/src';

if (file_exists($srcRoot . '/autoload.php')) {
    require_once $srcRoot . '/autoload.php';
} else {
    // Fallback simple PSR-4–style autoload (temporary)
    spl_autoload_register(function (string $class) use ($srcRoot) {
        $prefix = 'GCS\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        $path = $srcRoot . '/' . str_replace('\\', '/', $relative) . '.php';

        if (file_exists($path)) {
            require_once $path;
        }
    });
}

// -----------------------------------------------------------------------------
// Runtime Flags (defaults only — no logic)
// -----------------------------------------------------------------------------

define('GCS_VERSION', '2.0-dev');
define('GCS_DEBUG', false);

// -----------------------------------------------------------------------------
// Bootstrap Complete
// -----------------------------------------------------------------------------

// No side effects beyond this point.