<?php
declare(strict_types=1);

/**
 * Calendar Scheduler — Source Component
 *
 * File: Adapter/Calendar/OverrideIntent.php
 * Purpose: Defines the OverrideIntent component used by the Calendar Scheduler Adapter/Calendar layer.
 */

namespace CalendarScheduler\Adapter\Calendar;

final class OverrideIntent
{
    public array $originalStartTime;

    public array $start;
    public array $end;

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