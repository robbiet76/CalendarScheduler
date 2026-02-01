<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/src/Platform/FppEnvExporter.php';

use CalendarScheduler\Platform;

Platform\exportFppEnv(
    '/home/fpp/media/config/calendar-scheduler/runtime/fpp-env.json'
);

// When called via plugin.php&nopage=1 we want a tiny response body.
echo "OK\n";
