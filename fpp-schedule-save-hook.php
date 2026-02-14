<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use CalendarScheduler\Platform\FppEventTimestampStore;

$schedulePath = '/home/fpp/media/config/schedule.json';
$outputPath = '/home/fpp/media/config/calendar-scheduler/fpp/event-timestamps.json';

header('Content-Type: application/json');

try {
    $store = new FppEventTimestampStore();
    $doc = $store->rebuild($schedulePath, $outputPath);
    $count = is_array($doc['events'] ?? null) ? count($doc['events']) : 0;

    echo json_encode(
        [
            'ok' => true,
            'path' => $outputPath,
            'events' => $count,
            'scheduleMtimeEpoch' => $doc['scheduleMtimeEpoch'] ?? null,
        ],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) . "\n";
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(
        [
            'ok' => false,
            'error' => $e->getMessage(),
        ],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    ) . "\n";
}
