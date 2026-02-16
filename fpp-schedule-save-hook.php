<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use CalendarScheduler\Platform\FppEventTimestampStore;

$schedulePath = '/home/fpp/media/config/schedule.json';
$outputPath = '/home/fpp/media/config/calendar-scheduler/fpp/event-timestamps.json';
$tombstonesPath = '/home/fpp/media/config/calendar-scheduler/runtime/tombstones.json';

header('Content-Type: application/json');

try {
    $store = new FppEventTimestampStore();
    $previous = $store->load($outputPath);
    $previousEvents = is_array($previous['events'] ?? null) ? $previous['events'] : [];

    $doc = $store->rebuild($schedulePath, $outputPath);
    $count = is_array($doc['events'] ?? null) ? count($doc['events']) : 0;
    $currentEvents = is_array($doc['events'] ?? null) ? $doc['events'] : [];
    $eventEpoch = is_numeric($doc['scheduleMtimeEpoch'] ?? null)
        ? (int)$doc['scheduleMtimeEpoch']
        : time();

    $tombstoneStats = updateFppTombstones(
        $tombstonesPath,
        array_keys($previousEvents),
        array_keys($currentEvents),
        $eventEpoch
    );

    echo json_encode(
        [
            'ok' => true,
            'path' => $outputPath,
            'events' => $count,
            'scheduleMtimeEpoch' => $doc['scheduleMtimeEpoch'] ?? null,
            'tombstones' => $tombstoneStats,
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

/**
 * @param array<int,string> $previousIdentityIds
 * @param array<int,string> $currentIdentityIds
 * @return array<string,int>
 */
function updateFppTombstones(
    string $path,
    array $previousIdentityIds,
    array $currentIdentityIds,
    int $eventEpoch
): array {
    $doc = loadTombstonesDoc($path);
    $sources = is_array($doc['sources'] ?? null) ? $doc['sources'] : [];

    $calendar = normalizeTombstoneMap($sources['calendar'] ?? []);
    $fpp = normalizeTombstoneMap($sources['fpp'] ?? []);

    $previousSet = [];
    foreach ($previousIdentityIds as $id) {
        if (is_string($id) && $id !== '') {
            $previousSet[$id] = true;
        }
    }
    $currentSet = [];
    foreach ($currentIdentityIds as $id) {
        if (is_string($id) && $id !== '') {
            $currentSet[$id] = true;
        }
    }

    $added = 0;
    $cleared = 0;

    // Add tombstones for identities removed by the FPP save.
    foreach ($previousSet as $id => $_) {
        if (isset($currentSet[$id])) {
            continue;
        }
        if (!isset($fpp[$id]) || $fpp[$id] < $eventEpoch) {
            $fpp[$id] = $eventEpoch;
            $added++;
        }
    }

    // Clear tombstones when the identity exists again in FPP.
    foreach ($currentSet as $id => $_) {
        if (isset($fpp[$id])) {
            unset($fpp[$id]);
            $cleared++;
        }
    }

    ksort($calendar, SORT_STRING);
    ksort($fpp, SORT_STRING);

    $updated = [
        'version' => 1,
        'generatedAtEpoch' => time(),
        'sources' => [
            'calendar' => $calendar,
            'fpp' => $fpp,
        ],
    ];

    writeJsonAtomically($path, $updated);

    return [
        'added' => $added,
        'cleared' => $cleared,
        'fpp_total' => count($fpp),
    ];
}

/**
 * @return array<string,mixed>
 */
function loadTombstonesDoc(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }

    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (\Throwable) {
        return [];
    }

    return is_array($decoded) ? $decoded : [];
}

/**
 * @param mixed $value
 * @return array<string,int>
 */
function normalizeTombstoneMap(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }
    $out = [];
    foreach ($value as $id => $ts) {
        if (!is_string($id) || $id === '' || !is_numeric($ts)) {
            continue;
        }
        $n = (int)$ts;
        if ($n > 0) {
            $out[$id] = $n;
        }
    }
    return $out;
}

/**
 * @param array<string,mixed> $doc
 */
function writeJsonAtomically(string $path, array $doc): void
{
    $json = json_encode(
        $doc,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );

    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new \RuntimeException("Unable to create directory: {$dir}");
    }

    $tmp = $path . '.tmp';
    if (@file_put_contents($tmp, $json . PHP_EOL) === false) {
        throw new \RuntimeException("Unable to write temp file: {$tmp}");
    }

    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new \RuntimeException("Unable to replace file: {$path}");
    }
}
