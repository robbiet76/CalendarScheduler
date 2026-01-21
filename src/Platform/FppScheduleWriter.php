<?php

declare(strict_types=1);

namespace GoogleCalendarScheduler\Platform;

/**
 * FppScheduleWriter
 *
 * Responsible for serializing and writing the final FPP schedule.
 *
 * This is the ONLY class allowed to write schedule.json.
 *
 * INPUT CONTRACT:
 * - Schedule array is fully reconciled (ApplyEngine output)
 * - Entries are already in FPP-compatible shape
 * - No further transformation is required
 *
 * OUTPUT:
 * - Writes schedule.json atomically
 *
 * NON-GOALS:
 * - No validation
 * - No planning or diffing
 * - No logging policy
 * - No backups (can be added later)
 */
final class FppScheduleWriter
{
    private string $schedulePath;

    /**
     * @param string $schedulePath Absolute path to FPP schedule.json
     */
    public function __construct(string $schedulePath)
    {
        if ($schedulePath === '') {
            throw new \InvalidArgumentException('schedulePath must not be empty');
        }

        $this->schedulePath = $schedulePath;
    }

    /**
     * Write the schedule to disk.
     *
     * @param array<int,array<string,mixed>> $schedule
     */
    public function write(array $schedule): void
    {
        $json = json_encode(
            $schedule,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        $tmpPath = $this->schedulePath . '.tmp';

        // Write atomically: temp file then rename
        if (file_put_contents($tmpPath, $json) === false) {
            throw new \RuntimeException("Failed to write temporary schedule file: {$tmpPath}");
        }

        if (!rename($tmpPath, $this->schedulePath)) {
            @unlink($tmpPath);
            throw new \RuntimeException("Failed to replace schedule file: {$this->schedulePath}");
        }
    }
}