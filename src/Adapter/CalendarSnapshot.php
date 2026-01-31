<?php
declare(strict_types=1);

namespace CalendarScheduler\Adapter;

use CalendarScheduler\Adapter\CalendarTranslator;
use RuntimeException;

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
    private const SNAPSHOT_PATH =
        '/home/fpp/media/config/calendar-scheduler/calendar/calendar-snapshot.json';
    /**
     * Temporary hard-coded ICS source (until OAuth phase).
     */
    private const ICS_SOURCE =
        'https://calendar.google.com/calendar/ical/' .
        'f6f834eec6b7e004bdbc070dbd860c076c7fc3e4df36e8eb8da3e80f8e2f21c4%40group.calendar.google.com/' .
        'private-4cb0555e63adf571c353d0eb7b3c4bd3/basic.ics';
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
        self::write($events);
    }
    /**
     * Load the raw calendar snapshot from disk.
     *
     * @return array Raw calendar provider events
     */
    public static function load(): array
    {
        if (!file_exists(self::SNAPSHOT_PATH)) {
            return [];
        }

        $json = file_get_contents(self::SNAPSHOT_PATH);
        if ($json === false || $json === '') {
            return [];
        }

        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Write raw calendar events to the snapshot on disk.
     *
     * @param array $events Raw calendar provider events
     */
    public static function write(array $events): void
    {
        $dir = dirname(self::SNAPSHOT_PATH);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents(
            self::SNAPSHOT_PATH,
            json_encode($events, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Fetch calendar data from ICS source and write snapshot.
     */
    public static function refreshFromIcs(): array
    {
        $translator = new CalendarTranslator();

        $events = $translator->translateIcsSourceToCalendarEvents(
            self::ICS_SOURCE
        );

        self::write($events);

        return $events;
    }
}
