<?php

declare(strict_types=1);

namespace CalendarScheduler\Planner;

/**
 * PlannerResult is a simple container for already-sorted PlannedEntry objects.
 */
final class PlannerResult
{
    /** @var PlannedEntry[] */
    private array $entries;

    /**
     * @param PlannedEntry[] $entries Already sorted.
     */
    public function __construct(array $entries)
    {
        foreach ($entries as $entry) {
            if (!$entry instanceof PlannedEntry) {
                throw new \InvalidArgumentException('PlannerResult entries must be PlannedEntry instances');
            }
        }
        $this->entries = array_values($entries);
    }

    /** @return PlannedEntry[] */
    public function entries(): array { return $this->entries; }

    public function count(): int { return count($this->entries); }

    public function isEmpty(): bool { return $this->count() === 0; }
}