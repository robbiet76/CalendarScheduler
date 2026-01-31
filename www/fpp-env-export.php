<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/Platform/FppEnvExporter.php';

use CalendarScheduler\Platform;

Platform\exportFppEnv(
    '/home/fpp/media/config/calendar-scheduler/runtime/fpp-env.json'
);

echo "OK\n";