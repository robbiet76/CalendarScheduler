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
    /**
     * Structured RRULE (lossless, provider-neutral)
     * @var array<string,mixed>|null
     */
    public ?array $rrule = null;
    public ?string $timezone = null;
    public bool $isAllDay = false;

    // Execution payload (opaque to snapshot)
    public array $payload = [];

    // Exceptions
    /** @var array<array|string> */
    public array $cancelledDates = [];     // originalStartTime values

    /** @var OverrideIntent[] */
    public array $overrides = [];

    // Optional: retained only for debugging / diffing
    public array $sourceRows = [];

    public function __construct(array $row)
    {
        $this->sourceEventUid = $row['uid'];
        $this->parentUid = $row['uid'];
        $this->provider = $row['provider'] ?? 'unknown';
        $this->start = $row['start'];
        $this->end = $row['end'];
        $this->rrule = $row['rrule'] ?? null;
        $this->timezone = $row['timezone'] ?? null;
        $this->isAllDay = $row['isAllDay'] ?? false;
        $this->payload = $row['payload'] ?? [];
        $this->sourceRows[] = $row;
    }

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
