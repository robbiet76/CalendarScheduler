<?php

declare(strict_types=1);

namespace GoogleCalendarScheduler\Diff;

use GoogleCalendarScheduler\Planner\PlannedEntry;
use GoogleCalendarScheduler\Planner\PlannerResult;

/**
 * Phase 2.3 — Diff (spec-compliant)
 *
 * Inputs:
 *  - Desired state: PlannerResult (PlannedEntry[])
 *  - Current state: existing scheduler entries (arrays/objects)
 *
 * Identity:
 *  - Canonical reconciliation key is identity_hash (proved by FileManifestStore)
 *
 * Managed/unmanaged rules:
 *  - Planned entries are managed intent.
 *  - Existing unmanaged entries are never deleted/updated/reordered.
 *  - If an unmanaged existing entry collides with a planned identity_hash, fail hard.
 *
 * Update rules:
 *  - Ordering differences alone do NOT trigger updates.
 *  - Equality for updates compares only target + timing (normalized).
 *
 * Output:
 *  - DiffResult { creates, updates, deletes } only.
 *
 * No I/O. No schedule.json. No FPP validation here.
 */
final class Diff
{
    /**
     * @param array<int,mixed> $existingEntries Arrays or objects representing current scheduler entries.
     */
    public function diff(PlannerResult $planned, array $existingEntries): DiffResult
    {
        $plannedById = $this->indexPlannedByIdentity($planned);

        [$managedExistingById, $unmanagedExistingIds] = $this->indexExistingByIdentity($existingEntries);

        // Collision check: planned must never attempt to take over unmanaged identities.
        foreach ($unmanagedExistingIds as $identityHash => $_true) {
            if (isset($plannedById[$identityHash])) {
                throw new \RuntimeException(
                    'Planned identity_hash collides with unmanaged existing entry: ' . $identityHash
                );
            }
        }

        $creates = [];
        $updates = [];
        $deletes = [];

        // Creates & Updates
        foreach ($plannedById as $identityHash => $plannedEntry) {
            $existing = $managedExistingById[$identityHash] ?? null;

            if ($existing === null) {
                $creates[] = $this->plannedToScheduleEntry($plannedEntry);
                continue;
            }

            if (!$this->equivalentIgnoringOrder($plannedEntry, $existing)) {
                $updates[] = $this->plannedToScheduleEntry($plannedEntry);
            }
        }

        // Deletes (managed only)
        foreach ($managedExistingById as $identityHash => $existing) {
            if (!isset($plannedById[$identityHash])) {
                $deletes[] = $this->existingToScheduleEntry($existing, $identityHash);
            }
        }

        // Deterministic ordering of result sets
        $sort = static function (array $a, array $b): int {
            $ak = (string)($a['identity_hash'] ?? '');
            $bk = (string)($b['identity_hash'] ?? '');
            return $ak <=> $bk;
        };

        usort($creates, $sort);
        usort($updates, $sort);
        usort($deletes, $sort);

        return new DiffResult($creates, $updates, $deletes);
    }

    /**
     * @return array<string,PlannedEntry>
     */
    private function indexPlannedByIdentity(PlannerResult $planned): array
    {
        $map = [];
        foreach ($planned->entries() as $entry) {
            $id = $entry->identityHash();
            if (isset($map[$id])) {
                throw new \RuntimeException('Duplicate planned identity_hash detected: ' . $id);
            }
            $map[$id] = $entry;
        }
        return $map;
    }

    /**
     * Index existing entries into (managed map, unmanaged identity set).
     *
     * Spec: unmanaged are never deleted/updated and are ignored unless referenced (collision).
     *
     * @param array<int,mixed> $existingEntries
     * @return array{0: array<string,mixed>, 1: array<string,bool>}
     */
    private function indexExistingByIdentity(array $existingEntries): array
    {
        $managedById = [];
        $unmanagedIds = [];

        foreach ($existingEntries as $i => $existing) {
            $isManaged = $this->readManagedFlag($existing);

            $identityHash = $this->readIdentityHash($existing);

            if ($isManaged) {
                // Managed entries MUST have identity_hash.
                if ($identityHash === '') {
                    throw new \RuntimeException('Managed existing entry missing identity_hash at index ' . $i);
                }

                // Managed entries MUST have comparable fields.
                $target = $this->readAssoc($existing, ['target']);
                $timing = $this->readAssoc($existing, ['timing', 'time', 'timings']);
                if (!is_array($target) || !is_array($timing)) {
                    throw new \RuntimeException(
                        'Managed existing entry missing target/timing at identity_hash ' . $identityHash
                    );
                }

                if (isset($managedById[$identityHash])) {
                    throw new \RuntimeException('Duplicate managed existing identity_hash detected: ' . $identityHash);
                }

                $managedById[$identityHash] = $existing;
            } else {
                // Unmanaged entries are ignored for mutation.
                // If they have an identity_hash, record it to prevent collisions.
                if ($identityHash !== '') {
                    $unmanagedIds[$identityHash] = true;
                }
            }
        }

        return [$managedById, $unmanagedIds];
    }

    private function equivalentIgnoringOrder(PlannedEntry $planned, mixed $existing): bool
    {
        $existingTarget = $this->readAssoc($existing, ['target']);
        $existingTiming = $this->readAssoc($existing, ['timing', 'time', 'timings']);

        // For managed entries, these were already validated in indexExistingByIdentity().
        if (!is_array($existingTarget) || !is_array($existingTiming)) {
            return false;
        }

        return $this->normalizedJson($planned->target()) === $this->normalizedJson($existingTarget)
            && $this->normalizedJson($planned->timing()) === $this->normalizedJson($existingTiming);
    }

    /**
     * Convert planned entry into a schedule-entry-shaped array for downstream Apply.
     * This is the "desired state" representation.
     *
     * NOTE: ordering_key is carried for Apply to order entries, but Diff ignores it for updates.
     *
     * @return array<string,mixed>
     */
    private function plannedToScheduleEntry(PlannedEntry $planned): array
    {
        return [
            'identity_hash' => $planned->identityHash(),
            'managed' => true,

            // For traceability/debugging only (Apply may ignore these):
            'event_id' => $planned->eventId(),
            'sub_event_id' => $planned->subEventId(),

            // The only content Diff uses for equivalence:
            'target' => $planned->target(),
            'timing' => $planned->timing(),

            // Carry ordering for Apply, but never compare for updates:
            'ordering_key' => $planned->orderingKey()->toScalar(),
        ];
    }

    /**
     * Convert existing entry into a schedule-entry-shaped array for deletes.
     * We preserve as much as we can if it's already an array.
     *
     * @return array<string,mixed>
     */
    private function existingToScheduleEntry(mixed $existing, string $identityHash): array
    {
        if (is_array($existing)) {
            // Ensure identity_hash is present and canonical.
            $existing['identity_hash'] = $identityHash;
            return $existing;
        }

        // Fallback for object-like existing entries.
        return [
            'identity_hash' => $identityHash,
            'managed' => true,
        ];
    }

    private function readManagedFlag(mixed $existing): bool
    {
        $v = $this->readScalar($existing, ['managed', 'isManaged']);
        return (bool)$v;
    }

    private function readIdentityHash(mixed $existing): string
    {
        $v = $this->readScalar($existing, ['identity_hash', 'identityHash']);
        return is_string($v) ? trim($v) : '';
    }

    /**
     * Stable JSON string for deep comparison (key-sorted).
     *
     * @param array<string,mixed> $value
     */
    private function normalizedJson(array $value): string
    {
        $sorted = $this->deepKeySort($value);
        return json_encode($sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function deepKeySort(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $isList = array_keys($value) === range(0, count($value) - 1);

        if ($isList) {
            $out = [];
            foreach ($value as $v) {
                $out[] = $this->deepKeySort($v);
            }
            return $out;
        }

        $out = [];
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        foreach ($keys as $k) {
            $out[$k] = $this->deepKeySort($value[$k]);
        }
        return $out;
    }

    /**
     * Reads a value from array/object by common key/method names.
     *
     * @param array<int,string> $keys
     */
    private function readScalar(mixed $obj, array $keys): mixed
    {
        if (is_array($obj)) {
            foreach ($keys as $k) {
                if (array_key_exists($k, $obj)) {
                    return $obj[$k];
                }
            }
            return null;
        }

        if (is_object($obj)) {
            foreach ($keys as $k) {
                if (method_exists($obj, $k)) {
                    return $obj->{$k}();
                }
                $uc = ucfirst($k);
                foreach (['get' . $uc, 'is' . $uc, $k] as $m) {
                    if (method_exists($obj, $m)) {
                        return $obj->{$m}();
                    }
                }
            }
        }

        return null;
    }

    /**
     * Reads an associative array field from array/object by common key/method names.
     *
     * @param array<int,string> $keys
     * @return array<string,mixed>|null
     */
    private function readAssoc(mixed $obj, array $keys): ?array
    {
        $v = $this->readScalar($obj, $keys);
        return is_array($v) ? $v : null;
    }
}