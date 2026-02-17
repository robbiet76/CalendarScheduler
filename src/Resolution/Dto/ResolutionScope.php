<?php
declare(strict_types=1);

/**
 * Calendar Scheduler — Source Component
 *
 * File: Resolution/Dto/ResolutionScope.php
 * Purpose: Model a concrete temporal scope used by resolved bundles and
 * subevents so downstream planning operates on validated time windows.
 */

namespace CalendarScheduler\Resolution\Dto;

use DateTimeImmutable;

/**
 * Scope expresses where a subevent applies.
 *
 * - Segment scope (bundle): typically a contiguous date range.
 * - Override scope: a narrower date or dateTime range inside the segment.
 */
final class ResolutionScope
{
    // Inclusive start and exclusive end boundaries for scope evaluation.
    private DateTimeImmutable $start;
    private DateTimeImmutable $end;

    public function __construct(DateTimeImmutable $start, DateTimeImmutable $end)
    {
        // Resolution scopes must always move forward in time.
        if ($end <= $start) {
            throw new \InvalidArgumentException('ResolutionScope end must be after start.');
        }
        $this->start = $start;
        $this->end = $end;
    }

    public function getStart(): DateTimeImmutable
    {
        return $this->start;
    }

    public function getEnd(): DateTimeImmutable
    {
        return $this->end;
    }
}
