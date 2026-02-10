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

        // Raw snapshot rows provide dtstart/dtend as strings
        if (!isset($row['dtstart'], $row['dtend'])) {
            throw new \RuntimeException('SnapshotEvent missing dtstart/dtend');
        }

        $this->isAllDay = (bool)($row['isAllDay'] ?? false);
        $this->timezone = $row['timezone'] ?? null;

        $this->start = $this->parseDateTime($row['dtstart'], $this->isAllDay);
        $this->end   = $this->parseDateTime($row['dtend'], $this->isAllDay);

        $this->rrule = $row['rrule'] ?? null;
        $this->payload = $row['payload'] ?? [];
        $this->sourceRows[] = $row;
    }

    private function parseDateTime(string $value, bool $allDay): array
    {
        if ($allDay) {
            return [
                'date' => substr($value, 0, 10),
                'allDay' => true,
            ];
        }

        [$date, $time] = explode(' ', $value, 2);

        return [
            'date' => $date,
            'time' => $time,
            'allDay' => false,
        ];
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
