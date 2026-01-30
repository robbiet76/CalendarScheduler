<?php

declare(strict_types=1);

namespace GoogleCalendarScheduler\Apply;

/**
 * ApplyResult
 *
 * Holds the reconciled schedule plus counts.
 * Pure value object. No I/O.
 */
final class ApplyResult
{
    /** @var array<int,array<string,mixed>> */
    private array $schedule;

    private int $creates;
    private int $updates;
    private int $deletes;

    /**
     * @param array<int,array<string,mixed>> $schedule
     */
    public function __construct(array $schedule, int $creates, int $updates, int $deletes)
    {
        $this->schedule = array_values($schedule);
        $this->creates = $creates;
        $this->updates = $updates;
        $this->deletes = $deletes;
    }

    /** @return array<int,array<string,mixed>> */
    public function schedule(): array { return $this->schedule; }

    public function createCount(): int { return $this->creates; }
    public function updateCount(): int { return $this->updates; }
    public function deleteCount(): int { return $this->deletes; }

    public function isNoop(): bool
    {
        return $this->creates === 0 && $this->updates === 0 && $this->deletes === 0;
    }
}