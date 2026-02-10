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
 * - Enforce deterministic identity matching by identityHash only
 * - Entries are replaced atomically on update
 * - Ordering is deterministic by identityHash after mutation
 *
 * Non-responsibilities:
 * - No file I/O
 * - No locking
 * - No JSON encoding
 * - No validation beyond identityHash presence
 */
final class FppScheduleMutator
{
    /**
     * Index the schedule array by identityHash.
     *
     * @param array $schedule
     * @return array<string, array>
     * @throws \RuntimeException if any row is missing identityHash
     */
    private function indexByIdentityHash(array $schedule): array
    {
        $index = [];
        foreach ($schedule as $row) {
            if (!isset($row['identityHash'])) {
                throw new \RuntimeException('FppScheduleMutator: missing identityHash in schedule row');
            }
            $index[$row['identityHash']] = $row;
        }
        return $index;
    }

    public function upsert(array $schedule, array $entry): array
    {
        if (!isset($entry['identityHash'])) {
            throw new \RuntimeException('FppScheduleMutator: missing identityHash on upsert');
        }

        $index = $this->indexByIdentityHash($schedule);

        $index[$entry['identityHash']] = $entry;

        ksort($index, SORT_STRING);

        return array_values($index);
    }

    public function delete(array $schedule, string $identityHash): array
    {
        $index = $this->indexByIdentityHash($schedule);

        unset($index[$identityHash]);

        ksort($index, SORT_STRING);

        return array_values($index);
    }

    /**
     * Apply a list of reconciliation actions to an FPP schedule array.
     *
     * @param array $schedule
     * @param ReconciliationAction[] $actions
     * @return array
     * @throws \RuntimeException on unexpected action types or missing event payloads
     */
    public function apply(array $schedule, array $actions): array
    {
        foreach ($actions as $action) {
            if ($action->target !== ReconciliationAction::TARGET_FPP) {
                continue;
            }

            if ($action->type === ReconciliationAction::TYPE_NOOP) {
                continue;
            }

            if ($action->type === ReconciliationAction::TYPE_CREATE || $action->type === ReconciliationAction::TYPE_UPDATE) {
                if (!is_array($action->event)) {
                    throw new \RuntimeException(
                        'FppScheduleMutator: missing event payload for ' . $action->identityHash
                    );
                }
                $schedule = $this->upsert($schedule, $action->event);
                continue;
            }

            if ($action->type === ReconciliationAction::TYPE_DELETE) {
                $schedule = $this->delete($schedule, $action->identityHash);
                continue;
            }

            throw new \RuntimeException('FppScheduleMutator: unexpected action type ' . $action->type);
        }

        return $schedule;
    }
}