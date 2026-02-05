<?php
declare(strict_types=1);

namespace CalendarScheduler\Adapter\Calendar\Google;

use DateTimeImmutable;
use DateTimeZone;

/**
 * GoogleCalendarTranslator
 *
 * Translates Google Calendar API Event resources into provider-neutral CalendarEvent records.
 * MUST be structural-only: no intent reconstruction, no semantic interpretation, no recurrence expansion.
 */
final class GoogleCalendarTranslator
{
    /**
     * Canonical entrypoint for Calendar I/O ingestion.
     *
     * @param array<int,array<string,mixed>> $googleEvents Raw Google "Event" resources (decoded JSON arrays)
     * @param string $calendarId
     * @return array<int,array<string,mixed>> Provider-neutral CalendarEvent records
     */
    public function ingest(array $googleEvents, string $calendarId): array
    {
        return $this->translateGoogleEvents($googleEvents, $calendarId);
    }
    /**
     * @param array<int,array<string,mixed>> $googleEvents Raw Google "Event" resources (decoded JSON arrays)
     * @return array<int,array<string,mixed>> Provider-neutral CalendarEvent records
     */
    public function translateGoogleEvents(array $googleEvents, string $calendarId): array
    {
        $out = [];

        foreach ($googleEvents as $ev) {
            if (!is_array($ev)) {
                continue;
            }

            $source = [
                'provider'    => 'google',
                'calendar_id' => $calendarId,
            ];

            $provenance = $this->buildProvenance($ev);

            // Summary / description (opaque; do not parse metadata here)
            $summary     = is_string($ev['summary'] ?? null) ? $ev['summary'] : '';
            $description = array_key_exists('description', $ev) ? ($ev['description'] ?? null) : null;
            if (!is_string($description) && $description !== null) {
                $description = null;
            }

            // Start / end
            [$dtstart, $dtend, $isAllDay] = $this->translateStartEnd($ev);

            // Recurrence + EXDATE (preserve, do not expand)
            [$rrule, $exDates] = $this->translateRecurrence($ev);

            // Overrides
            $recurringEventId = $ev['recurringEventId'] ?? null;
            $isOverride       = is_string($recurringEventId) && $recurringEventId !== '';
            $recurrenceId     = $isOverride ? $recurringEventId : null;

            $out[] = [
                'source'       => $source,

                'summary'      => $summary,
                'description'  => $description,

                // ISO string (timed) or YYYY-MM-DD (all-day)
                'dtstart'      => $dtstart,
                'dtend'        => $dtend,

                'rrule'        => $rrule,
                'exDates'      => $exDates,

                'isAllDay'     => $isAllDay,
                'recurrenceId' => $recurrenceId,
                'isOverride'   => $isOverride,

                // Convenience alias for historic callers (if any)
                'uid'          => $provenance['uid'] ?? null,
                'provenance'   => $provenance,
            ];
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $ev
     * @return array<string,mixed>
     */
    private function buildProvenance(array $ev): array
    {
        $uid      = is_string($ev['id'] ?? null) ? $ev['id'] : null;
        $etag     = is_string($ev['etag'] ?? null) ? $ev['etag'] : null;
        $sequence = is_int($ev['sequence'] ?? null) ? $ev['sequence'] : null;

        $createdEpoch = $this->isoToEpoch($ev['created'] ?? null);
        $updatedEpoch = $this->isoToEpoch($ev['updated'] ?? null);

        return [
            'uid'            => $uid,
            'etag'           => $etag,
            'sequence'       => $sequence,
            'createdAtEpoch' => $createdEpoch,
            'updatedAtEpoch' => $updatedEpoch,
        ];
    }

    /**
     * @param array<string,mixed> $ev
     * @return array{0:string,1:string,2:bool} [dtstart, dtend, isAllDay]
     */
    private function translateStartEnd(array $ev): array
    {
        $start = is_array($ev['start'] ?? null) ? $ev['start'] : [];
        $end   = is_array($ev['end'] ?? null) ? $ev['end'] : [];

        // All-day: start.date + end.date (exclusive end per Google semantics)
        $startDate = $start['date'] ?? null;
        $endDate   = $end['date'] ?? null;

        if (is_string($startDate) && $startDate !== '' && is_string($endDate) && $endDate !== '') {
            return [$startDate, $endDate, true];
        }

        // Timed: start.dateTime + end.dateTime
        $startDT = $start['dateTime'] ?? null;
        $endDT   = $end['dateTime'] ?? null;

        $startTZ = is_string($start['timeZone'] ?? null) ? $start['timeZone'] : null;
        $endTZ   = is_string($end['timeZone'] ?? null) ? $end['timeZone'] : null;

        $dtstart = $this->normalizeIsoToLocalTz($startDT, $startTZ);
        $dtend   = $this->normalizeIsoToLocalTz($endDT, $endTZ);

        // Fall back to empty strings rather than throwing (I/O layer is tolerant of partial records)
        return [$dtstart ?? '', $dtend ?? '', false];
    }

    /**
     * @param array<string,mixed> $ev
     * @return array{0:array<string,mixed>|null,1:array<int,string>} [rrule, exDates]
     */
    private function translateRecurrence(array $ev): array
    {
        $rrule = null;
        $ex    = [];

        $recurrence = $ev['recurrence'] ?? null;
        if (!is_array($recurrence)) {
            return [null, []];
        }

        foreach ($recurrence as $line) {
            if (!is_string($line)) {
                continue;
            }

            // Google returns strings like "RRULE:FREQ=WEEKLY;BYDAY=MO,WE"
            if (str_starts_with($line, 'RRULE:')) {
                $raw  = substr($line, 6);
                $rrule = $this->parseRrule($raw);
                continue;
            }

            // EXDATE line(s) may appear; keep as raw-ish strings in an array
            if (str_starts_with($line, 'EXDATE')) {
                foreach ($this->parseExDateLine($line) as $d) {
                    $ex[] = $d;
                }
                continue;
            }
        }

        return [$rrule, $ex];
    }

    /**
     * Parse RRULE into a small structured map + preserve raw.
     * DO NOT expand recurrence or interpret UNTIL semantics here.
     *
     * @return array<string,mixed>
     */
    private function parseRrule(string $raw): array
    {
        $out = ['raw' => $raw];

        // naive parse: KEY=VALUE;KEY=VALUE...
        $parts = explode(';', $raw);
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '' || !str_contains($p, '=')) {
                continue;
            }

            [$k, $v] = explode('=', $p, 2);
            $k = strtoupper(trim($k));
            $v = trim($v);

            switch ($k) {
                case 'FREQ':
                    $out['freq'] = strtoupper($v);
                    break;
                case 'BYDAY':
                    $days = array_values(array_filter(array_map('trim', explode(',', $v))));
                    $out['byday'] = $days;
                    break;
                case 'INTERVAL':
                    $out['interval'] = ctype_digit($v) ? (int)$v : $v;
                    break;
                case 'UNTIL':
                    // keep raw; downstream handles boundary semantics
                    $out['until'] = $v;
                    break;
                default:
                    // preserve other keys without interpretation
                    $out[strtolower($k)] = $v;
                    break;
            }
        }

        return $out;
    }

    /**
     * Parses EXDATE lines. Returns raw date/time strings as-is (structural only).
     *
     * Examples:
     *   EXDATE:20250101T010000Z
     *   EXDATE;TZID=America/Los_Angeles:20250101T010000,20250108T010000
     *
     * @return array<int,string>
     */
    private function parseExDateLine(string $line): array
    {
        $pos = strpos($line, ':');
        if ($pos === false) {
            return [];
        }
        $payload = substr($line, $pos + 1);
        $vals = array_values(array_filter(array_map('trim', explode(',', $payload))));
        return $vals;
    }

    private function isoToEpoch(mixed $iso): ?int
    {
        if (!is_string($iso) || $iso === '') {
            return null;
        }

        try {
            $dt = new DateTimeImmutable($iso);
            return $dt->getTimestamp();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Normalize incoming ISO timestamps to the FPP local timezone at the boundary.
     * Keeps ISO8601 output including offset.
     */
    private function normalizeIsoToLocalTz(mixed $iso, ?string $tzHint): ?string
    {
        if (!is_string($iso) || $iso === '') {
            return null;
        }

        try {
            $dt = new DateTimeImmutable($iso);

            // If Google provided a timezone hint but ISO is floating-ish, we can re-anchor.
            // (Most Google dateTime values already include an offset; this is defensive.)
            if ($tzHint !== null && $tzHint !== '') {
                try {
                    $hint = new DateTimeZone($tzHint);
                    $dt   = $dt->setTimezone($hint);
                } catch (\Throwable) {
                    // ignore invalid hint
                }
            }

            $local = new DateTimeZone(date_default_timezone_get());
            return $dt->setTimezone($local)->format(DATE_ATOM);
        } catch (\Throwable) {
            return null;
        }
    }
}