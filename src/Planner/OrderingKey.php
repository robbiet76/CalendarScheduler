<?php

declare(strict_types=1);

/**
 * Calendar Scheduler — Source Component
 *
 * File: Planner/OrderingKey.php
 * Purpose: Define the canonical deterministic ordering tuple used to sort
 * planned scheduler entries consistently across runs and environments.
 */

namespace CalendarScheduler\Planner;

/**
 * OrderingKey encodes a *total ordering* for PlannedEntry objects.
 *
 * It is immutable and lexicographically comparable via toScalar().
 *
 * Ordering model (lexicographic):
 *  1) managedPriority (lower sorts first; managed before unmanaged)
 *  2) eventOrder
 *  3) subEventOrder
 *  4) startEpochSeconds
 *  5) stableTieBreaker (string)
 */
final class OrderingKey
{
    // Ordered comparison tuple fields.
    private int $managedPriority;
    private int $eventOrder;
    private int $subEventOrder;
    private int $startEpochSeconds;
    private string $stableTieBreaker;

    public function __construct(
        int $managedPriority,
        int $eventOrder,
        int $subEventOrder,
        int $startEpochSeconds,
        string $stableTieBreaker = ''
    ) {
        // Validate ordering tuple bounds before persisting.
        if ($managedPriority < 0 || $managedPriority > 9) {
            throw new \InvalidArgumentException('managedPriority must be in range 0..9');
        }
        if ($eventOrder < -1) {
            throw new \InvalidArgumentException('eventOrder must be >= -1');
        }
        if ($subEventOrder < -1) {
            throw new \InvalidArgumentException('subEventOrder must be >= -1');
        }
        if ($startEpochSeconds < 0) {
            throw new \InvalidArgumentException('startEpochSeconds must be >= 0');
        }

        $this->managedPriority = $managedPriority;
        $this->eventOrder = $eventOrder;
        $this->subEventOrder = $subEventOrder;
        $this->startEpochSeconds = $startEpochSeconds;
        $this->stableTieBreaker = $stableTieBreaker;
    }

    public function managedPriority(): int { return $this->managedPriority; }
    public function eventOrder(): int { return $this->eventOrder; }
    public function subEventOrder(): int { return $this->subEventOrder; }
    public function startEpochSeconds(): int { return $this->startEpochSeconds; }
    public function stableTieBreaker(): string { return $this->stableTieBreaker; }

    /**
     * Lexicographically sortable scalar string (locale/collation safe).
     */
    public function toScalar(): string
    {
        // eventOrder/subEventOrder may be -1; map to 0 with an offset.
        $event = $this->eventOrder + 1;
        $sub = $this->subEventOrder + 1;

        // Encode as zero-padded lexical key for deterministic string sorting.
        return sprintf(
            '%01d-%06d-%06d-%010d-%s',
            $this->managedPriority,
            $event,
            $sub,
            $this->startEpochSeconds,
            $this->stableTieBreaker
        );
    }

    public static function compare(self $a, self $b): int
    {
        // Centralized comparator used by planner sorting code paths.
        return $a->toScalar() <=> $b->toScalar();
    }
}
