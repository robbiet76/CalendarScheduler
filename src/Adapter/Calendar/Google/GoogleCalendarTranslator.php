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
    private bool $debugCalendar;

    public function __construct()
    {
        $this->debugCalendar = getenv('GCS_DEBUG_CALENDAR') === '1';
    }

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
            // Support on-disk snapshot rows (exported/translated shape) in addition to raw Google API events.
            // Snapshot rows use dtstart/dtend/rrule/exDates rather than Google API start/end/recurrence.
            if (
                isset($ev['dtstart'])
                && is_string($ev['dtstart'])
                && $ev['dtstart'] !== ''
                && !isset($ev['start'])
            ) {
                $out[] = $this->translateSnapshotRow($ev, $calendarId);
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
            $status = is_string($ev['status'] ?? null) ? $ev['status'] : 'confirmed';

            // Start / end
            [$dtstart, $dtend, $isAllDay] = $this->translateStartEnd($ev);

            // Recurrence + EXDATE (preserve, do not expand)
            [$rrule, $exDates] = $this->translateRecurrence($ev);

            // Overrides
            $recurringEventId = $ev['recurringEventId'] ?? null;
            $isOverride       = is_string($recurringEventId) && $recurringEventId !== '';
            $parentUid        = $isOverride ? $recurringEventId : null;

            $originalStartTime = is_array($ev['originalStartTime'] ?? null)
                ? $ev['originalStartTime']
                : null;

            // Preserve Google start/end arrays verbatim for downstream (time may be hard or all-day).
            $startRaw = is_array($ev['start'] ?? null) ? $ev['start'] : [];
            $endRaw   = is_array($ev['end'] ?? null) ? $ev['end'] : [];

            // Best-effort timezone hint (structural only). Google may provide per-start/per-end timeZone.
            $tz = null;
            if (is_string($startRaw['timeZone'] ?? null) && $startRaw['timeZone'] !== '') {
                $tz = $startRaw['timeZone'];
            } elseif (is_string($endRaw['timeZone'] ?? null) && $endRaw['timeZone'] !== '') {
                $tz = $endRaw['timeZone'];
            }

            $uid = $provenance['uid'] ?? null;

            // Optional low-level RRULE tracing (very noisy, off by default)
            if ($this->debugCalendar) {
                if (is_array($rrule) && isset($rrule['byday'])) {
                    error_log(
                        'RAW RRULE BYDAY [calendar]: ' .
                        json_encode($rrule['byday'])
                    );
                } else {
                    error_log('RAW RRULE BYDAY [calendar]: null');
                }
            }

            $out[] = [
                // Provider + calendar identity
                'provider'        => 'google',
                'calendar_id'     => $calendarId,

                // Stable identity anchors
                'uid'             => $uid,
                'sourceEventUid'  => $uid,

                // Human fields (opaque here; do not parse)
                'summary'         => $summary,
                'description'     => $description,
                'status'          => $status,

                // Raw Google timing semantics (structural; downstream decides how to interpret)
                // - All-day uses ['date']
                // - Timed uses ['dateTime'] (+ optional ['timeZone'])
                'start'           => $startRaw,
                'end'             => $endRaw,
                'timezone'        => $tz,
                'isAllDay'        => $isAllDay,

                // Legacy / convenience fields (kept for compatibility)
                // ISO string (timed) or YYYY-MM-DD (all-day)
                'dtstart'         => $dtstart,
                'dtend'           => $dtend,

                // Recurrence + exclusions (preserve; do not expand)
                'rrule'           => $rrule,
                'exDates'         => $exDates,

                // Override linkage
                'parentUid'       => $parentUid,
                'originalStartTime' => $originalStartTime,
                'isOverride'      => $isOverride,

                // Opaque payload (used by downstream intent/normalization)
                'payload'         => [
                    'summary'     => $summary,
                    'description' => $description,
                    'status'      => $status,
                    'rrule'       => $rrule,
                    'exDates'     => $exDates,
                ],

                // Provenance / raw metadata
                'provenance'      => $provenance,
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
    /**
     * Translate an already-exported snapshot row (array entry from calendar-snapshot.json).
     * This preserves the snapshot semantics and rehydrates the provider-neutral CalendarEvent shape
     * expected by the V2 pipeline.
     *
     * @param array<string,mixed> $ev
     * @return array<string,mixed>
     */
    private function translateSnapshotRow(array $ev, string $calendarId): array
    {
        $summary     = is_string($ev['summary'] ?? null) ? $ev['summary'] : '';
        $description = array_key_exists('description', $ev) ? ($ev['description'] ?? null) : null;
        if (!is_string($description) && $description !== null) {
            $description = null;
        }
        $status = is_string($ev['status'] ?? null) ? $ev['status'] : 'confirmed';

        $isAllDay = (bool) ($ev['isAllDay'] ?? false);
        $tz = is_string($ev['timezone'] ?? null) && $ev['timezone'] !== '' ? (string) $ev['timezone'] : null;

        $dtstart = is_string($ev['dtstart'] ?? null) ? (string) $ev['dtstart'] : '';
        $dtend   = is_string($ev['dtend'] ?? null) ? (string) $ev['dtend'] : '';

        // Identity: snapshot rows use uid.
        $uid = is_string($ev['uid'] ?? null) ? (string) $ev['uid'] : null;

        // Recurrence + exclusions are already structural in snapshot rows.
        $rrule = is_array($ev['rrule'] ?? null) ? $ev['rrule'] : null;
        $exDates = is_array($ev['exDates'] ?? null) ? array_values(array_filter($ev['exDates'], 'is_string')) : [];

        // Override linkage
        $isOverride = (bool) ($ev['isOverride'] ?? false);
        $parentUid = is_string($ev['parentUid'] ?? null) && $ev['parentUid'] !== '' ? (string) $ev['parentUid'] : null;
        $originalStartTime = is_array($ev['originalStartTime'] ?? null) ? $ev['originalStartTime'] : null;

        // Rehydrate Google-like start/end arrays for downstream consumers that expect them.
        [$startRaw, $endRaw] = $this->rehydrateStartEndArrays($dtstart, $dtend, $isAllDay, $tz);

        $provenance = [];
        if (is_array($ev['provenance'] ?? null)) {
            $provenance = $ev['provenance'];
        }
        // Ensure at least uid is present in provenance.
        if (!isset($provenance['uid']) && $uid !== null) {
            $provenance['uid'] = $uid;
        }

        return [
            'provider'          => 'google',
            'calendar_id'       => $calendarId,

            'uid'               => $uid,
            'sourceEventUid'    => $uid,

            'summary'           => $summary,
            'description'       => $description,
            'status'            => $status,

            'start'             => $startRaw,
            'end'               => $endRaw,
            'timezone'          => $tz,
            'isAllDay'          => $isAllDay,

            'dtstart'           => $dtstart,
            'dtend'             => $dtend,

            'rrule'             => $rrule,
            'exDates'           => $exDates,

            'parentUid'         => $parentUid,
            'originalStartTime' => $originalStartTime,
            'isOverride'        => $isOverride,

            'payload'           => [
                'summary'     => $summary,
                'description' => $description,
                'status'      => $status,
                'rrule'       => $rrule,
                'exDates'     => $exDates,
            ],

            'provenance'        => $provenance,
        ];
    }

    /**
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    private function rehydrateStartEndArrays(string $dtstart, string $dtend, bool $isAllDay, ?string $tz): array
    {
        if ($isAllDay) {
            // Snapshot all-day values may be YYYY-MM-DD or YYYY-MM-DD HH:MM:SS. Keep only the date.
            $startDate = substr($dtstart, 0, 10);
            $endDate   = substr($dtend, 0, 10);

            return [
                ['date' => $startDate],
                ['date' => $endDate],
            ];
        }

        $tzObj = null;
        if ($tz !== null && $tz !== '') {
            try {
                $tzObj = new DateTimeZone($tz);
            } catch (\Throwable) {
                $tzObj = null;
            }
        }
        if ($tzObj === null) {
            $tzObj = new DateTimeZone(date_default_timezone_get());
        }

        $startIso = $this->formatLocalDateTimeToAtom($dtstart, $tzObj);
        $endIso   = $this->formatLocalDateTimeToAtom($dtend, $tzObj);

        $start = ['dateTime' => $startIso];
        $end   = ['dateTime' => $endIso];

        if ($tz !== null && $tz !== '') {
            $start['timeZone'] = $tz;
            $end['timeZone']   = $tz;
        }

        return [$start, $end];
    }

    private function formatLocalDateTimeToAtom(string $value, DateTimeZone $tz): string
    {
        // Accept both 'Y-m-d H:i:s' (our snapshot export) and RFC3339/ISO strings.
        $dt = null;
        try {
            $dt = new DateTimeImmutable($value);
        } catch (\Throwable) {
            $dt = null;
        }

        if ($dt === null) {
            $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, $tz);
        }

        if ($dt === false || $dt === null) {
            // Last resort: now, but keep translator tolerant.
            $dt = new DateTimeImmutable('now', $tz);
        }

        return $dt->setTimezone($tz)->format(DATE_ATOM);
    }
}
