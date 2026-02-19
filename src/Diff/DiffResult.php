<?php

declare(strict_types=1);

/**
 * Calendar Scheduler — Source Component
 *
 * File: Diff/DiffResult.php
 * Purpose: Defines the DiffResult component used by the Calendar Scheduler Diff layer.
 */

namespace CalendarScheduler\Diff;

/**
 * Spec v2.3.1 — DiffResult
 *
 * DiffResult {
 *   creates: FppScheduleEntry[]
 *   updates: FppScheduleEntry[]
 *   deletes: FppScheduleEntry[]
 * }
 *
 * We intentionally keep "FppScheduleEntry" as a plain associative array for now.
 * Apply (Phase 2.4) will translate these into real FPP schedule mutations.
 */
final class DiffResult
{
    /** @var array<int,array<string,mixed>> */
    private array $creates;

    /** @var array<int,array<string,mixed>> */
    private array $updates;

    /** @var array<int,array<string,mixed>> */
    private array $deletes;

    /**
     * @param array<int,array<string,mixed>> $creates
     * @param array<int,array<string,mixed>> $updates
     * @param array<int,array<string,mixed>> $deletes
     */
    public function __construct(array $creates, array $updates, array $deletes)
    {
        $this->assertEntryList($creates, 'creates');
        $this->assertEntryList($updates, 'updates');
        $this->assertEntryList($deletes, 'deletes');

        $this->creates = array_values($creates);
        $this->updates = array_values($updates);
        $this->deletes = array_values($deletes);
    }

    /** @return array<int,array<string,mixed>> */
    public function creates(): array { return $this->creates; }

    /** @return array<int,array<string,mixed>> */
    public function updates(): array { return $this->updates; }

    /** @return array<int,array<string,mixed>> */
    public function deletes(): array { return $this->deletes; }

    public function createCount(): int { return count($this->creates); }
    public function updateCount(): int { return count($this->updates); }
    public function deleteCount(): int { return count($this->deletes); }

    public function isNoop(): bool
    {
        return $this->createCount() === 0
            && $this->updateCount() === 0
            && $this->deleteCount() === 0;
    }

    /**
     * @param array<int,mixed> $entries
     */
    private function assertEntryList(array $entries, string $label): void
    {
        foreach ($entries as $i => $entry) {
            if (!is_array($entry)) {
                throw new \InvalidArgumentException("$label[$i] must be an array entry");
            }
            if (
                !array_key_exists('identityHash', $entry)
                || !is_string($entry['identityHash'])
                || trim($entry['identityHash']) === ''
            ) {
                throw new \InvalidArgumentException("$label[$i] missing required identityHash");
            }
        }
    }
}