<?php

declare(strict_types=1);

/**
 * Calendar Scheduler — Source Component
 *
 * File: Planner/PlannedEntry.php
 * Purpose: Represent one planned FPP scheduler entry with stable identity,
 * normalized timing payload, and deterministic ordering metadata.
 */

namespace CalendarScheduler\Planner;

/**
 * PlannedEntry represents one FPP scheduler entry derived from:
 *  - one Manifest Event
 *  - one SubEvent
 *
 * Pure value object: construction + minimal validation only.
 */
final class PlannedEntry
{
    // Manifest and subevent identity fields.
    private string $eventId;
    private string $subEventId;
    private string $identityHash;

    // Target and timing payloads consumed by schedule translators/writers.
    /** @var array<string,mixed> */
    private array $target;

    /** @var array<string,mixed> */
    private array $timing;

    // Precomputed deterministic ordering key for stable sorting.
    private OrderingKey $orderingKey;

    /**
     * @param array<string,mixed> $target
     * @param array<string,mixed> $timing
     */
    public function __construct(
        string $eventId,
        string $subEventId,
        string $identityHash,
        array $target,
        array $timing,
        OrderingKey $orderingKey
    ) {
        // Normalize scalar identifiers before validation.
        $eventId = trim($eventId);
        $subEventId = trim($subEventId);
        $identityHash = trim($identityHash);

        // Validate minimal construction invariants.
        if ($eventId === '') {
            throw new \InvalidArgumentException('eventId must be a non-empty string');
        }
        if ($subEventId === '') {
            throw new \InvalidArgumentException('subEventId must be a non-empty string');
        }
        if ($identityHash === '') {
            throw new \InvalidArgumentException('identityHash must be a non-empty string');
        }
        if (empty($target)) {
            throw new \InvalidArgumentException('target must be a non-empty array');
        }
        if (empty($timing)) {
            throw new \InvalidArgumentException('timing must be a non-empty array');
        }

        // Persist immutable planned entry state.
        $this->eventId = $eventId;
        $this->subEventId = $subEventId;
        $this->identityHash = $identityHash;
        $this->target = $target;
        $this->timing = $timing;
        $this->orderingKey = $orderingKey;
    }

    public function eventId(): string { return $this->eventId; }
    public function subEventId(): string { return $this->subEventId; }
    public function identityHash(): string { return $this->identityHash; }

    /** @return array<string,mixed> */
    public function target(): array { return $this->target; }

    /** @return array<string,mixed> */
    public function timing(): array { return $this->timing; }

    public function orderingKey(): OrderingKey { return $this->orderingKey; }

    public function stableKey(): string
    {
        // Compose a deterministic tie-break key for cross-run comparisons.
        return $this->orderingKey->toScalar()
            . '|' . $this->identityHash
            . '|' . $this->eventId
            . '|' . $this->subEventId;
    }
}
