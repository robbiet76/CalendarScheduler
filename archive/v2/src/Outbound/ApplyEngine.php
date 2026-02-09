<?php

declare(strict_types=1);

namespace CalendarScheduler\Apply;

use CalendarScheduler\Diff\DiffResult;
use CalendarScheduler\Platform\FppScheduleEntryAdapter;
use CalendarScheduler\Planner\PlannedEntry;

/**
 * Phase 2.4 — ApplyEngine (Outbound)
 *
 * Applies DiffResult to an existing schedule array.
 *
 * Responsibilities:
 * - Decide which entries are created, updated, or deleted
 * - Preserve unmanaged entries exactly
 * - Preserve relative order of existing entries
 * - Delegate platform-specific shaping to Platform adapters
 *
 * NON-GOALS:
 * - No FPP schema knowledge
 * - No date/time logic
 * - No I/O
 */
final class ApplyEngine
{
    /**
     * @param array<int,array<string,mixed>> $existingSchedule
     */
    public function apply(DiffResult $diff, array $existingSchedule): ApplyResult
    {
        // Index diff sets
        $deleteSet = $this->indexIdentitySet($diff->deletes(), 'deletes');
        $updatesById = $this->indexByIdentity($diff->updates(), 'updates');
        $createsById = $this->indexByIdentity($diff->creates(), 'creates');

        // Sanity: no overlap
        foreach ($deleteSet as $id => $_) {
            if (isset($updatesById[$id]) || isset($createsById[$id])) {
                throw new \RuntimeException(
                    "Invalid DiffResult: identity_hash {$id} overlaps delete with update/create"
                );
            }
        }

        $out = [];
        $seenManaged = [];

        // Walk existing schedule preserving order
        foreach ($existingSchedule as $i => $entry) {
            if (!is_array($entry)) {
                throw new \RuntimeException("Existing schedule entry at index {$i} must be an array");
            }

            $isManaged = (bool)($entry['managed'] ?? false);
            $id = $this->readIdentityHash($entry);

            if ($isManaged) {
                if ($id === '') {
                    throw new \RuntimeException("Managed existing entry missing identity_hash at index {$i}");
                }
                if (isset($seenManaged[$id])) {
                    throw new \RuntimeException("Duplicate managed identity_hash in existing schedule: {$id}");
                }
                $seenManaged[$id] = true;

                // Delete?
                if (isset($deleteSet[$id])) {
                    continue;
                }

                // Update?
                if (isset($updatesById[$id])) {
                    /** @var PlannedEntry $planned */
                    $planned = $updatesById[$id];

                    $out[] = FppScheduleEntryAdapter::adapt($planned);
                    unset($updatesById[$id]);
                    continue;
                }

                // Unchanged managed entry
                $entry['managed'] = true; // canonicalize
                $out[] = $entry;
                continue;
            }

            // Unmanaged entries pass through untouched
            $out[] = $entry;
        }

        // Remaining updates reference missing managed entries → invariant violation
        if (!empty($updatesById)) {
            $ids = implode(', ', array_keys($updatesById));
            throw new \RuntimeException("Updates reference missing managed entries: {$ids}");
        }

        // Append creates deterministically
        $creates = array_values($createsById);
        usort($creates, static function (PlannedEntry $a, PlannedEntry $b): int {
            $ak = (string)$a->orderingKey()->toScalar();
            $bk = (string)$b->orderingKey()->toScalar();
            $c = $ak <=> $bk;
            if ($c !== 0) return $c;
            return $a->identityHash() <=> $b->identityHash();
        });

        foreach ($creates as $planned) {
            $out[] = FppScheduleEntryAdapter::adapt($planned);
        }

        return new ApplyResult(
            $out,
            $diff->createCount(),
            $diff->updateCount(),
            $diff->deleteCount()
        );
    }

    /**
     * @param array<int,PlannedEntry> $entries
     * @return array<string,bool>
     */
    private function indexIdentitySet(array $entries, string $label): array
    {
        $set = [];
        foreach ($entries as $i => $e) {
            if (!$e instanceof PlannedEntry) {
                throw new \RuntimeException("DiffResult {$label}[{$i}] must be PlannedEntry");
            }
            $id = $e->identityHash();
            if (isset($set[$id])) {
                throw new \RuntimeException("Duplicate identity_hash in DiffResult {$label}: {$id}");
            }
            $set[$id] = true;
        }
        return $set;
    }

    /**
     * @param array<int,PlannedEntry> $entries
     * @return array<string,PlannedEntry>
     */
    private function indexByIdentity(array $entries, string $label): array
    {
        $map = [];
        foreach ($entries as $i => $e) {
            if (!$e instanceof PlannedEntry) {
                throw new \RuntimeException("DiffResult {$label}[{$i}] must be PlannedEntry");
            }
            $id = $e->identityHash();
            if (isset($map[$id])) {
                throw new \RuntimeException("Duplicate identity_hash in DiffResult {$label}: {$id}");
            }
            $map[$id] = $e;
        }
        return $map;
    }

    /**
     * @param array<string,mixed> $entry
     */
    private function readIdentityHash(array $entry): string
    {
        $v = $entry['identity_hash'] ?? $entry['identityHash'] ?? null;
        return is_string($v) ? trim($v) : '';
    }
}