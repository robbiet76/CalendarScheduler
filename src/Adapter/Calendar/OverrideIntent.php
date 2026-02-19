<?php
declare(strict_types=1);

/**
 * Calendar Scheduler — Source Component
 *
 * File: Adapter/Calendar/OverrideIntent.php
 * Purpose: Represent one translated override window from a provider event so
 * the resolver can layer override behavior over a base schedule segment.
 */

namespace CalendarScheduler\Adapter\Calendar;

final class OverrideIntent
{
    // Original and resolved time windows for the override instance.
    public array $originalStartTime;
    public array $start;
    public array $end;

    // Scheduling behavior payload and lifecycle flags.
    public array $payload = [];
    public bool $enabled = true;
    public ?string $stopType = null;

    /**
     * @param array $row Translated calendar provider row
     */
    public function __construct(array $row)
    {
        $this->originalStartTime = $row['originalStartTime'] ?? [];
        $this->start             = $row['start'] ?? [];
        $this->end               = $row['end'] ?? [];
        $this->payload           = $row['payload'] ?? [];
        $this->enabled           = $row['enabled'] ?? true;
        $this->stopType          = $row['stopType'] ?? null;
    }
}
