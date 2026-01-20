<?php
declare(strict_types=1);

namespace GCS\Planner;

/**
 * PlannerResult
 *
 * Immutable container for ordered PlannedEntries.
 */
final class PlannerResult
{
    /** @var PlannedEntry[] */
    private array $entries;

    /**
     * @param PlannedEntry[] $entries Ordered planned entries
     */
    public function __construct(array $entries)
    {
        $this->entries = $entries;
    }

    /**
     * @return PlannedEntry[]
     */
    public function entries(): array
    {
        return $this->entries;
    }

    /**
     * Convenience: return entries keyed by sub-event identity hash.
     *
     * This is the identity used for diffing and apply.
     *
     * @return array<string, PlannedEntry>
     */
    public function byIdentity(): array
    {
        $map = [];

        foreach ($this->entries as $entry) {
            $map[$entry->subEventIdentityHash()] = $entry;
        }

        return $map;
    }
}