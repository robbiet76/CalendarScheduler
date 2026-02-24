<?php
declare(strict_types=1);

/**
 * Calendar Scheduler — FPP Runtime Export Endpoint
 *
 * File: fpp-runtime-export.php
 * Purpose: Export FPP runtime catalog/state to
 * `runtime/fpp-runtime.json` for validation and diagnostics.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/src/Platform/FppRuntimeExporter.php';

use CalendarScheduler\Platform;

Platform\exportFppRuntime(
    '/home/fpp/media/config/calendar-scheduler/runtime/fpp-runtime.json'
);

echo "OK\n";

