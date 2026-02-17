<?php

/**
 * Calendar Scheduler — Source Component
 *
 * File: Adapter/Calendar/SnapshotEvent.php
 * Purpose: Defines the SnapshotEvent component used by the Calendar Scheduler Adapter/Calendar layer.
 */

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
        $this->start = is_array($row['start'] ?? null) ? $row['start'] : [];
        $this->end = is_array($row['end'] ?? null) ? $row['end'] : [];
        $this->rrule = $row['rrule'] ?? null;
        $this->timezone = $row['timezone'] ?? null;
        $this->isAllDay = $row['isAllDay'] ?? false;
        $this->payload = $row['payload'] ?? [];
        $this->sourceRows[] = $row;

        // -----------------------------------------------------------------
        // Normalize start/end into a stable provider-agnostic shape.
        //
        // SnapshotEvent MUST preserve time-of-day when available.
        // If the incoming row has dtstart/dtend strings, use them to fill
        // missing time info in start/end.
        // -----------------------------------------------------------------

        $dtstart = is_string($row['dtstart'] ?? null) ? trim($row['dtstart']) : '';
        $dtend   = is_string($row['dtend'] ?? null) ? trim($row['dtend']) : '';

        // If start/end are empty or missing time, but dtstart/dtend exist, hydrate them.
        if ($dtstart !== '' && $this->needsTimeHydration($this->start)) {
            $this->start = $this->dateTimeArrayFromString($dtstart, $this->isAllDay);
        } else {
            $this->start = $this->normalizeDateTimeArray($this->start, $this->isAllDay);
        }

        if ($dtend !== '' && $this->needsTimeHydration($this->end)) {
            $this->end = $this->dateTimeArrayFromString($dtend, $this->isAllDay);
        } else {
            $this->end = $this->normalizeDateTimeArray($this->end, $this->isAllDay);
        }
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

    /**
     * Returns true if start/end should be hydrated from dtstart/dtend.
     *
     * We hydrate when:
     * - array is empty, OR
     * - has date but missing time/dateTime (common failure mode that yields midnight)
     */
    private function needsTimeHydration(array $dt): bool
    {
        if ($dt === []) {
            return true;
        }

        if (isset($dt['dateTime']) && is_string($dt['dateTime']) && $dt['dateTime'] !== '') {
            return false;
        }

        if (isset($dt['date']) && is_string($dt['date']) && $dt['date'] !== '') {
            // if time is missing, hydrate
            $time = $dt['time'] ?? null;
            return !is_string($time) || $time === '';
        }

        return true;
    }

    /**
     * Normalize a date/dateTime array into:
     *   all-day: ['date' => 'YYYY-MM-DD', 'allDay' => true]
     *   timed:   ['date' => 'YYYY-MM-DD', 'time' => 'HH:MM:SS', 'allDay' => false]
     */
    private function normalizeDateTimeArray(array $dt, bool $isAllDay): array
    {
        // Accept provider-style start/end blocks: ['dateTime' => '...'] or ['date' => '...']
        $dateTime = $dt['dateTime'] ?? null;
        if (is_string($dateTime) && $dateTime !== '') {
            // Best-effort parse: YYYY-MM-DDTHH:MM:SS...
            $parts = preg_split('/[T ]/', $dateTime);
            $date = is_array($parts) && isset($parts[0]) ? (string) $parts[0] : '';
            $time = is_array($parts) && isset($parts[1]) ? (string) $parts[1] : '00:00:00';
            $time = substr($time, 0, 8);

            if ($isAllDay) {
                return ['date' => $date, 'allDay' => true];
            }
            return ['date' => $date, 'time' => $time, 'allDay' => false];
        }

        $date = $dt['date'] ?? null;
        $time = $dt['time'] ?? null;

        if (!is_string($date) || $date === '') {
            $date = '1970-01-01';
        }

        if ($isAllDay) {
            return ['date' => $date, 'allDay' => true];
        }

        if (!is_string($time) || $time === '') {
            $time = '00:00:00';
        }

        return ['date' => $date, 'time' => $time, 'allDay' => false];
    }

    /**
     * Parse dtstart/dtend strings like "YYYY-MM-DD HH:MM:SS" into normalized arrays.
     */
    private function dateTimeArrayFromString(string $s, bool $isAllDay): array
    {
        // Expect "YYYY-MM-DD HH:MM:SS" (your exporter uses this)
        $parts = preg_split('/\s+/', trim($s));
        $date = is_array($parts) && isset($parts[0]) ? (string) $parts[0] : '';
        $time = is_array($parts) && isset($parts[1]) ? (string) $parts[1] : '00:00:00';
        $time = substr($time, 0, 8);

        if ($isAllDay) {
            return ['date' => $date, 'allDay' => true];
        }

        return ['date' => $date, 'time' => $time, 'allDay' => false];
    }
}
