<?php
declare(strict_types=1);

namespace GCS\Planner;

/**
 * OrderingKey
 *
 * Deterministic, comparable ordering key derived from a PlannedEntry.
 */
final class OrderingKey
{
    private int $eventOrder;
    private int $subEventOrder;
    private int $startTimeSeconds;
    private string $tieBreaker;

    private function __construct(
        int $eventOrder,
        int $subEventOrder,
        int $startTimeSeconds,
        string $tieBreaker
    ) {
        $this->eventOrder        = $eventOrder;
        $this->subEventOrder     = $subEventOrder;
        $this->startTimeSeconds  = $startTimeSeconds;
        $this->tieBreaker        = $tieBreaker;
    }

    /**
     * Build an OrderingKey from a PlannedEntry.
     */
    public static function fromPlannedEntry(PlannedEntry $entry): self
    {
        $timing = $entry->timing();
        $startTime = $timing['start_time']['value'] ?? '00:00:00';

        return new self(
            $entry->eventOrder(),
            $entry->subEventOrder(),
            self::timeToSeconds($startTime),
            $entry->subEventIdentityHash()
        );
    }

    /**
     * Compare two OrderingKeys.
     */
    public static function compare(self $a, self $b): int
    {
        return
            $a->eventOrder        <=> $b->eventOrder
            ?: $a->subEventOrder  <=> $b->subEventOrder
            ?: $a->startTimeSeconds <=> $b->startTimeSeconds
            ?: strcmp($a->tieBreaker, $b->tieBreaker);
    }

    private static function timeToSeconds(string $hhmmss): int
    {
        [$h, $m, $s] = array_map('intval', explode(':', $hhmmss));
        return ($h * 3600) + ($m * 60) + $s;
    }
}