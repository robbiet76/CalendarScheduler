<?php
declare(strict_types=1);

namespace CalendarScheduler\Apply;

use CalendarScheduler\Diff\ReconciliationAction;

/**
 * FppScheduleMutator
 *
 * Pure in-memory mutation of an FPP schedule array.
 *
 * Responsibilities:
 * - Apply create / update / delete actions to a schedule array
 * - Enforce deterministic identity matching
 *
 * Non-responsibilities:
 * - No file I/O
 * - No locking
 * - No JSON encoding
 * - No validation
 */
final class FppScheduleMutator
{
    private string $schedulePath;

    public function __construct(string $schedulePath)
    {
        $this->schedulePath = $schedulePath;
    }

    public function load(): array
    {
        if (!is_file($this->schedulePath)) {
            return [];
        }

        $data = json_decode(
            file_get_contents($this->schedulePath),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        return is_array($data) ? $data : [];
    }

    public function upsert(array $schedule, array $entry): array
    {
        if (!isset($entry['identityHash'])) {
            throw new \RuntimeException('FppScheduleMutator: missing identityHash on upsert');
        }

        $index = [];
        foreach ($schedule as $row) {
            if (isset($row['identityHash'])) {
                $index[$row['identityHash']] = $row;
            }
        }

        $index[$entry['identityHash']] = $entry;

        ksort($index, SORT_STRING);

        return array_values($index);
    }

    public function delete(array $schedule, string $identityHash): array
    {
        $index = [];
        foreach ($schedule as $row) {
            if (isset($row['identityHash'])) {
                $index[$row['identityHash']] = $row;
            }
        }

        unset($index[$identityHash]);

        ksort($index, SORT_STRING);

        return array_values($index);
    }
}