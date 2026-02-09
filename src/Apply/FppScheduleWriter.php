<?php

declare(strict_types=1);

namespace CalendarScheduler\Apply;

/**
 * FppScheduleWriter
 *
 * Writes the final schedule.json safely.
 *
 * Hardening guarantees:
 * - Pre-validate JSON serialization before taking locks or touching files
 * - Exclusive flock() to prevent concurrent writers
 * - Backup existing schedule.json to schedule.json.bak
 * - Atomic replace via temp file + rename
 * - Cleanup temp file on failure
 */
final class FppScheduleWriter
{
    private string $schedulePath;

    public function __construct(string $schedulePath)
    {
        $schedulePath = trim($schedulePath);
        if ($schedulePath === '') {
            throw new \InvalidArgumentException('schedulePath must not be empty');
        }

        $this->schedulePath = $schedulePath;
    }

    /**
     * @param array<int,array<string,mixed>> $schedule
     */
    public function write(array $schedule): void
    {
        // ---------------------------------------------------------------------
        // 1) Pre-validate serialization (no I/O yet)
        // ---------------------------------------------------------------------

        $json = json_encode(
            $schedule,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        $json = (string) $json;
        if (trim($json) === '') {
            throw new \RuntimeException('Refusing to write empty schedule JSON');
        }

        if (!is_array($schedule)) {
            // Defensive: signature guarantees array, but keep invariant explicit.
            throw new \RuntimeException('Schedule must be an array');
        }

        // ---------------------------------------------------------------------
        // 2) Lock the target schedule file (exclusive)
        // ---------------------------------------------------------------------

        $hadExisting = file_exists($this->schedulePath);

        $lockHandle = @fopen($this->schedulePath, 'c+');
        if ($lockHandle === false) {
            throw new \RuntimeException('Unable to open schedule file for locking: ' . $this->schedulePath);
        }

        try {
            if (!flock($lockHandle, LOCK_EX)) {
                throw new \RuntimeException('Unable to acquire schedule lock: ' . $this->schedulePath);
            }

            // -----------------------------------------------------------------
            // 3) Backup (only if schedule existed before we opened it)
            // -----------------------------------------------------------------

            if ($hadExisting) {
                $bakPath = $this->schedulePath . '.bak';

                if (!@copy($this->schedulePath, $bakPath)) {
                    throw new \RuntimeException('Failed to create schedule backup: ' . $bakPath);
                }
            }

            // -----------------------------------------------------------------
            // 4) Atomic write: temp file then rename
            // -----------------------------------------------------------------

            $tmpPath = $this->schedulePath . '.tmp';

            // Ensure no stale temp file from previous crash.
            if (file_exists($tmpPath)) {
                @unlink($tmpPath);
            }

            $bytes = @file_put_contents($tmpPath, $json);
            if ($bytes === false) {
                @unlink($tmpPath);
                throw new \RuntimeException('Failed to write temporary schedule file: ' . $tmpPath);
            }

            if (!@rename($tmpPath, $this->schedulePath)) {
                @unlink($tmpPath);
                throw new \RuntimeException('Failed to replace schedule file: ' . $this->schedulePath);
            }
        } finally {
            // Always release lock and close.
            @flock($lockHandle, LOCK_UN);
            @fclose($lockHandle);
        }
    }
}