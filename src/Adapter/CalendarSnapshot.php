<?php
declare(strict_types=1);

namespace GoogleCalendarScheduler\Adapter;

use GoogleCalendarScheduler\Adapter\CalendarTranslator;

/**
 * CalendarSnapshot
 *
 * Inbound snapshot ingestion from a calendar provider (currently ICS).
 *
 * HARD RULES:
 * - Snapshot only (no identity, no intent, no hashing, no normalization)
 * - Preserve raw calendar semantics exactly as provided by the calendar source
 * - Replacement semantics for calendar-sourced records only
 * - Writes only `calendar_events` as raw provider records (no other manifest data is modified)
 */
final class CalendarSnapshot
{
    private CalendarTranslator $translator;

    public function __construct(
        CalendarTranslator $translator
    ) {
        $this->translator = $translator;
    }

    /**
     * Snapshot calendar source into the draft manifest.
     *
     * Only writes raw provider calendar events under `calendar_events`.
     * No identity resolution, intent extraction, hashing, or normalization occurs here.
     *
     * @param string $icsSource URL (http/https) or local file path
     */
    public function snapshot(string $icsSource): void
    {
        $events = $this->translator->translateIcsSourceToCalendarEvents($icsSource);
        $this->translator->writeSnapshot($events);
    }
}