<?php

declare(strict_types=1);

namespace GoogleCalendarScheduler\Outbound;

use GoogleCalendarScheduler\Diff\DiffResult;

/**
 * Phase 2.4 — ApplyEngine (PURE, Outbound)
 *
 * Applies DiffResult (creates/updates/deletes) to an existing schedule array.
 *
 * Invariants:
 * - Unmanaged entries are NEVER deleted, updated, or reordered.
 * - Managed entries:
 *   - deleted if in DiffResult.deletes
 *   - updated in place if in DiffResult.updates
 *   - created entries are appended (after existing schedule)
 *
 * Determinism:
 * - Existing entries keep relative order.
 * - Updates replace in place.
 * - Creates are appended in a deterministic order.
 *
 * Zero Platform dependencies. No I/O.
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
                    $updated = $updatesById[$id];
                    $updated['managed'] = true; // canonicalize
                    $out[] = $updated;
                    unset($updatesById[$id]);
                    continue;
                }

                // Unchanged managed
                $entry['managed'] = true; // canonicalize
                $out[] = $entry;
                continue;
            }

            // Unmanaged entries pass through untouched
            $out[] = $entry;
        }

        // Any remaining updates reference missing managed entries → invariant violation
        if (!empty($updatesById)) {
            $ids = implode(', ', array_keys($updatesById));
            throw new \RuntimeException("Updates reference missing managed entries: {$ids}");
        }

        // Append creates deterministically (do not disturb existing order)
        $creates = array_values($createsById);
        usort($creates, static function (array $a, array $b): int {
            // Prefer ordering_key if present; fall back to identity_hash
            $ak = (string)($a['ordering_key'] ?? '');
            $bk = (string)($b['ordering_key'] ?? '');
            $c = $ak <=> $bk;
            if ($c !== 0) return $c;
            return (string)$a['identity_hash'] <=> (string)$b['identity_hash'];
        });

        foreach ($creates as $c) {
            $c['managed'] = true;
            $out[] = $c;
        }

        return new ApplyResult(
            $out,
            $diff->createCount(),
            $diff->updateCount(),
            $diff->deleteCount()
        );
    }

    /**
     * @param array<int,array<string,mixed>> $entries
     * @return array<string,bool>
     */
    private function indexIdentitySet(array $entries, string $label): array
    {
        $set = [];
        foreach ($entries as $i => $e) {
            if (!is_array($e)) {
                throw new \RuntimeException("DiffResult {$label}[{$i}] must be an array");
            }
            $id = $this->readIdentityHash($e);
            if ($id === '') {
                throw new \RuntimeException("DiffResult {$label}[{$i}] missing identity_hash");
            }
            if (isset($set[$id])) {
                throw new \RuntimeException("Duplicate identity_hash in DiffResult {$label}: {$id}");
            }
            $set[$id] = true;
        }
        return $set;
    }

    /**
     * @param array<int,array<string,mixed>> $entries
     * @return array<string,array<string,mixed>>
     */
    private function indexByIdentity(array $entries, string $label): array
    {
        $map = [];
        foreach ($entries as $i => $e) {
            if (!is_array($e)) {
                throw new \RuntimeException("DiffResult {$label}[{$i}] must be an array");
            }
            $id = $this->readIdentityHash($e);
            if ($id === '') {
                throw new \RuntimeException("DiffResult {$label}[{$i}] missing identity_hash");
            }
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