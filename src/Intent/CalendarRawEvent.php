<?php
declare(strict_types=1);

namespace GoogleCalendarScheduler\Intent;

/**
 * CalendarRawEvent
 *
 * Provider-agnostic, unresolved calendar event data.
 *
 * PURPOSE
 * -------
 * Represents the factual truth of a calendar entry exactly as expressed
 * by the source calendar system (e.g. ICS, CalDAV, Google Calendar).
 *
 * This is a RAW boundary object.
 *
 * It intentionally does NOT:
 * - express scheduling intent
 * - infer identity
 * - normalize semantics
 * - expand recurrence
 * - apply policy or defaults
 * - resemble the manifest or resolved shape
 *
 * Any transformation beyond factual capture MUST occur downstream
 * in IntentNormalizer.
 *
 *
 * GUARANTEES
 * ----------
 * The following guarantees are enforced by construction:
 *
 * - summary
 *   Human-visible event title exactly as supplied by the calendar.
 *
 * - dtstart / dtend
 *   ISO-8601 timestamps with timezone offset preserved.
 *   These reflect the DTSTART / DTEND values of the VEVENT
 *   after calendar-provider parsing and timezone conversion.
 *
 * - isAllDay
 *   True if the calendar entry is an all-day event (VALUE=DATE semantics).
 *
 * - rrule
 *   Raw recurrence rule as key/value pairs (RFC5545),
 *   or null if the event is non-recurring.
 *   Recurrence is NOT expanded here.
 *
 * - description
 *   Raw description text from the calendar entry.
 *   This may include embedded YAML or other metadata,
 *   but is NOT interpreted at this layer.
 *
 * - provenance
 *   Calendar-supplied metadata used strictly for traceability
 *   (e.g. UID, imported_at timestamp, provider identifiers).
 *
 *
 * HARD NON-GUARANTEES
 * ------------------
 * CalendarRawEvent explicitly does NOT guarantee:
 *
 * - Correctness relative to FPP semantics
 * - Stable identity or hashing
 * - Alignment with scheduler expectations
 * - Human intent
 * - One-to-one correspondence with manifest events
 *
 *
 * ARCHITECTURAL ROLE
 * ------------------
 * CalendarRawEvent exists to:
 * - Preserve calendar truth
 * - Prevent premature normalization
 * - Act as the sole input to IntentNormalizer::fromCalendar()
 *
 * If you find yourself wanting to:
 * - add identity fields
 * - add timing semantics (days, symbolic times, offsets)
 * - infer type (playlist / sequence)
 * - match against manifest entries
 *
 * you are in the wrong layer.
 */
final class CalendarRawEvent
{
    public string $summary;
    public string $dtstart;
    public string $dtend;
    public bool $isAllDay;

    /** @var array<string,string>|null */
    public ?array $rrule;

    public ?string $description;

    /** @var array */
    public array $provenance;

    public function __construct(
        string $summary,
        string $dtstart,
        string $dtend,
        bool $isAllDay,
        ?array $rrule,
        ?string $description,
        array $provenance
    ) {
        $this->summary     = $summary;
        $this->dtstart     = $dtstart;
        $this->dtend       = $dtend;
        $this->isAllDay    = $isAllDay;
        $this->rrule       = $rrule;
        $this->description = $description;
        $this->provenance  = $provenance;
    }

    public static function fromArray(array $raw): self
    {
        $summary = $raw['summary'] ?? '';
        $dtstart = $raw['start']['dateTime'] ?? $raw['start']['date'] ?? '';
        $dtend = $raw['end']['dateTime'] ?? $raw['end']['date'] ?? '';
        $isAllDay = isset($raw['start']['date']);

        $rrule = null;
        if (!empty($raw['recurrence']) && is_array($raw['recurrence'])) {
            foreach ($raw['recurrence'] as $recurrence) {
                if (strpos($recurrence, 'RRULE:') === 0) {
                    $ruleString = substr($recurrence, 6);
                    $parts = explode(';', $ruleString);
                    $rrule = [];
                    foreach ($parts as $part) {
                        $kv = explode('=', $part, 2);
                        if (count($kv) === 2) {
                            $rrule[$kv[0]] = $kv[1];
                        }
                    }
                    break;
                }
            }
        }

        $description = $raw['description'] ?? null;

        $provenance = [
            'calendar_id' => $raw['id'] ?? null,
            'raw' => $raw,
        ];

        return new self(
            $summary,
            $dtstart,
            $dtend,
            $isAllDay,
            $rrule,
            $description,
            $provenance
        );
    }
}