<?php

declare(strict_types=1);

namespace GoogleCalendarScheduler\Outbound;

/**
 * SchedulerRunResult
 *
 * Immutable summary of a scheduler execution.
 *
 * This object is safe for:
 * - CLI output
 * - logging
 * - metrics
 * - tests
 *
 * It intentionally does NOT expose:
 * - the final schedule array
 * - DiffResult or ApplyResult internals
 * - platform-specific details
 */
final class SchedulerRunResult
{
    private bool $noop;
    private int $creates;
    private int $updates;
    private int $deletes;

    public function __construct(
        bool $noop,
        int $creates,
        int $updates,
        int $deletes
    ) {
        $this->noop = $noop;
        $this->creates = $creates;
        $this->updates = $updates;
        $this->deletes = $deletes;
    }

    /**
     * True if no changes were required or applied.
     */
    public function isNoop(): bool
    {
        return $this->noop;
    }

    public function createCount(): int
    {
        return $this->creates;
    }

    public function updateCount(): int
    {
        return $this->updates;
    }

    public function deleteCount(): int
    {
        return $this->deletes;
    }
}