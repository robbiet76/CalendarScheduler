<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/Platform/FppEnvExporter.php';

use function CalendarScheduler\Platform\exportFppEnv;

exportFppEnv(
    '/home/fpp/media/config/calendar-scheduler/runtime/fpp-env.json'
);

// Keep response minimal (curl can discard output anyway)
header('Content-Type: text/plain');
echo "OK\n";
