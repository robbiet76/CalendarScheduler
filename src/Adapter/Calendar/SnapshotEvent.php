<?php

namespace CalendarScheduler\Adapter\Calendar;

final class SnapshotEvent
{
    // Identity
    public string $sourceEventUid;   // canonical calendar UID
    public string $parentUid;        // stable bundle anchor
    public string $provider;         // google, outlook, etc.

    // Recurrence / timing
    public array $start;             // date or dateTime
    public array $end;
    public ?string $rrule = null;
    public ?string $timezone = null;
    public bool $isAllDay = false;

    // Execution payload (opaque to snapshot)
    public array $payload = [];

    // Exceptions
    /** @var array<array> */
    public array $cancelledDates = [];     // originalStartTime values

    /** @var OverrideIntent[] */
    public array $overrides = [];

    // Optional: retained only for debugging / diffing
    public array $sourceRows = [];

    public function addCancelledDate(string|array $originalStartTime): void
    {
        $this->cancelledDates[] = $originalStartTime;
    }

    public function addOverride(OverrideIntent $override): void
    {
        $this->overrides[] = $override;
    }

    public function addSourceRow(array $row): void
    {
        $this->sourceRows[] = $row;
    }
}
