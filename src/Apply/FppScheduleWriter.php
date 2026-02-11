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
    private string $stagingDirectory;

    public function __construct(string $schedulePath, string $stagingDirectory)
    {
        $schedulePath = trim($schedulePath);
        $stagingDirectory = rtrim(trim($stagingDirectory), '/');

        if ($schedulePath === '') {
            throw new \InvalidArgumentException('schedulePath must not be empty');
        }

        if ($stagingDirectory === '') {
            throw new \InvalidArgumentException('stagingDirectory must not be empty');
        }

        $this->schedulePath = $schedulePath;
        $this->stagingDirectory = $stagingDirectory;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function load(): array
    {
        if (!file_exists($this->schedulePath)) {
            return [];
        }

        $contents = file_get_contents($this->schedulePath);
        if ($contents === false) {
            throw new \RuntimeException('Failed to read schedule file: ' . $this->schedulePath);
        }

        $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new \RuntimeException('Decoded schedule is not an array');
        }

        return array_values($decoded);
    }

    /**
     * Always writes the staged schedule file.
     * Does NOT touch live schedule.json.
     *
     * @param array<int,array<string,mixed>> $schedule
     */
    public function writeStaged(array $schedule): void
    {
        $json = json_encode(
            $schedule,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );

        $json = (string) $json;
        if (trim($json) === '') {
            throw new \RuntimeException('Refusing to write empty staged schedule JSON');
        }

        if (!is_dir($this->stagingDirectory)) {
            if (!@mkdir($this->stagingDirectory, 0775, true) && !is_dir($this->stagingDirectory)) {
                throw new \RuntimeException('Failed to create staging directory: ' . $this->stagingDirectory);
            }
        }

        $stagedPath = $this->stagingDirectory . '/schedule.staged.json';

        if (@file_put_contents($stagedPath, $json) === false) {
            throw new \RuntimeException('Failed to write staged schedule: ' . $stagedPath);
        }
    }

    /**
     * Commits staged schedule to live schedule.json.
     *
     * Behavior:
     * - Creates a single backup: schedule.backup.json (overwritten each run)
     * - Atomically replaces live schedule.json
     */
    public function commitStaged(): void
    {
        $stagedPath = $this->stagingDirectory . '/schedule.staged.json';
        $backupPath = $this->stagingDirectory . '/schedule.backup.json';

        if (!file_exists($stagedPath)) {
            throw new \RuntimeException('No staged schedule to commit: ' . $stagedPath);
        }

        $hadExisting = file_exists($this->schedulePath);

        $lockHandle = @fopen($this->schedulePath, 'c+');
        if ($lockHandle === false) {
            throw new \RuntimeException('Unable to open schedule file for locking: ' . $this->schedulePath);
        }

        try {
            if (!flock($lockHandle, LOCK_EX)) {
                throw new \RuntimeException('Unable to acquire schedule lock: ' . $this->schedulePath);
            }

            if ($hadExisting) {
                if (!@copy($this->schedulePath, $backupPath)) {
                    throw new \RuntimeException('Failed to create schedule backup: ' . $backupPath);
                }
            }

            $tmpPath = $this->schedulePath . '.tmp';

            if (file_exists($tmpPath)) {
                @unlink($tmpPath);
            }

            $contents = file_get_contents($stagedPath);
            if ($contents === false) {
                throw new \RuntimeException('Failed to read staged schedule: ' . $stagedPath);
            }

            if (@file_put_contents($tmpPath, $contents) === false) {
                @unlink($tmpPath);
                throw new \RuntimeException('Failed to write temporary schedule file: ' . $tmpPath);
            }

            if (!@rename($tmpPath, $this->schedulePath)) {
                @unlink($tmpPath);
                throw new \RuntimeException('Failed to replace schedule file: ' . $this->schedulePath);
            }
        } finally {
            @flock($lockHandle, LOCK_UN);
            @fclose($lockHandle);
        }
    }
}