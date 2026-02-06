<?php
declare(strict_types=1);

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
    private DateTimeImmutable $start;
    private DateTimeImmutable $end;

    public function __construct(DateTimeImmutable $start, DateTimeImmutable $end)
    {
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
