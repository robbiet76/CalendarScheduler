<?php
declare(strict_types=1);

/**
 * Calendar Scheduler — Source Component
 *
 * File: Platform/FppEventTimestampStore.php
 * Purpose: Defines the FppEventTimestampStore component used by the Calendar Scheduler Platform layer.
 */

namespace CalendarScheduler\Platform;

use CalendarScheduler\Adapter\FppScheduleAdapter;
use CalendarScheduler\Intent\IntentNormalizer;
use CalendarScheduler\Intent\NormalizationContext;

/**
 * FppEventTimestampStore
 *
 * Maintains per-identity timestamp metadata for FPP schedule saves.
 *
 * Data lives outside schedule.json and is keyed by identityHash.
 */
final class FppEventTimestampStore
{
    /**
     * Recompute and persist per-identity timestamps from the current schedule.json.
     *
     * @return array<string,mixed> Written document.
     */
    public function rebuild(
        string $schedulePath,
        string $outputPath
    ): array {
        if (!is_file($schedulePath)) {
            throw new \RuntimeException("FPP schedule not found: {$schedulePath}");
        }

        $scheduleMtime = filemtime($schedulePath);
        $nowEpoch = is_int($scheduleMtime) ? $scheduleMtime : time();

        $previous = $this->load($outputPath);
        $previousEvents = is_array($previous['events'] ?? null) ? $previous['events'] : [];
        $previousUpdatedAtByStateHash = [];
        foreach ($previousEvents as $row) {
            if (!is_array($row)) {
                continue;
            }
            $prevState = is_string($row['stateHash'] ?? null) ? $row['stateHash'] : '';
            $prevUpdated = is_numeric($row['updatedAtEpoch'] ?? null) ? (int)$row['updatedAtEpoch'] : 0;
            if ($prevState === '' || $prevUpdated <= 0) {
                continue;
            }
            if (!isset($previousUpdatedAtByStateHash[$prevState])) {
                $previousUpdatedAtByStateHash[$prevState] = $prevUpdated;
            }
        }

        $context = $this->buildNormalizationContext();
        $adapter = new FppScheduleAdapter();
        $normalizer = new IntentNormalizer();

        $fppEvents = $adapter->loadManifestEvents($context, $schedulePath);

        $events = [];
        foreach ($fppEvents as $event) {
            if (!is_array($event)) {
                continue;
            }

            $intent = $normalizer->fromManifestEvent($event, $context);
            $id = $intent->identityHash;
            $stateHash = $intent->eventStateHash;

            $prev = is_array($previousEvents[$id] ?? null) ? $previousEvents[$id] : null;
            $prevState = is_string($prev['stateHash'] ?? null) ? $prev['stateHash'] : '';
            $prevUpdated = is_numeric($prev['updatedAtEpoch'] ?? null) ? (int)$prev['updatedAtEpoch'] : 0;

            if ($prev !== null && $prevState !== '' && $prevState === $stateHash && $prevUpdated > 0) {
                $updatedAt = $prevUpdated;
            } elseif ($prev === null && isset($previousUpdatedAtByStateHash[$stateHash])) {
                // Preserve authority continuity only for newly observed identities.
                // If the same identity changed state (for example execution-order
                // edits), treat it as a fresh update and use nowEpoch below.
                $updatedAt = (int)$previousUpdatedAtByStateHash[$stateHash];
            } else {
                $updatedAt = $nowEpoch;
            }

            $events[$id] = [
                'updatedAtEpoch' => $updatedAt,
                'lastSeenEpoch'  => $nowEpoch,
                'stateHash'      => $stateHash,
            ];
        }

        ksort($events, SORT_STRING);

        $doc = [
            'version'            => 1,
            'source'             => 'fpp-save-hook',
            'generatedAtEpoch'   => time(),
            'scheduleMtimeEpoch' => $nowEpoch,
            'events'             => $events,
        ];

        $this->writeAtomically($outputPath, $doc);

        return $doc;
    }

    /**
     * @return array<string,mixed>
     */
    public function load(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
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
     * @return array<string,int>
     */
    public function loadUpdatedAtByIdentity(string $path): array
    {
        $doc = $this->load($path);
        $events = is_array($doc['events'] ?? null) ? $doc['events'] : [];

        $out = [];
        foreach ($events as $id => $row) {
            if (!is_string($id) || $id === '') {
                continue;
            }
            if (!is_array($row)) {
                continue;
            }
            $ts = $row['updatedAtEpoch'] ?? null;
            if (!is_numeric($ts)) {
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
     * Load updatedAtEpoch indexed by event stateHash.
     *
     * This is used as a compatibility fallback when identity-hash contracts change
     * but event executable state remains unchanged.
     *
     * @return array<string,int>
     */
    public function loadUpdatedAtByStateHash(string $path): array
    {
        $doc = $this->load($path);
        $events = is_array($doc['events'] ?? null) ? $doc['events'] : [];

        $out = [];
        foreach ($events as $row) {
            if (!is_array($row)) {
                continue;
            }
            $stateHash = $row['stateHash'] ?? null;
            $ts = $row['updatedAtEpoch'] ?? null;
            if (!is_string($stateHash) || $stateHash === '' || !is_numeric($ts)) {
                continue;
            }
            $n = (int)$ts;
            if ($n <= 0) {
                continue;
            }
            // Keep first-seen timestamp for identical state hashes (stable deterministic choice).
            if (!isset($out[$stateHash])) {
                $out[$stateHash] = $n;
            }
        }

        return $out;
    }

    private function buildNormalizationContext(): NormalizationContext
    {
        $fppEnvPath = '/home/fpp/media/config/calendar-scheduler/runtime/fpp-env.json';
        $holidays = [];

        if (is_file($fppEnvPath)) {
            try {
                $fppEnvRaw = json_decode(
                    (string)file_get_contents($fppEnvPath),
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
                if (is_array($fppEnvRaw)
                    && isset($fppEnvRaw['rawLocale']['holidays'])
                    && is_array($fppEnvRaw['rawLocale']['holidays'])
                ) {
                    $holidays = $fppEnvRaw['rawLocale']['holidays'];
                }
            } catch (\Throwable) {
                $holidays = [];
            }
        }

        return new NormalizationContext(
            new \DateTimeZone('UTC'),
            new FPPSemantics(),
            new HolidayResolver($holidays)
        );
    }

    /**
     * @param array<string,mixed> $doc
     */
    private function writeAtomically(string $path, array $doc): void
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
}
