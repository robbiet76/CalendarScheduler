<?php
declare(strict_types=1);

namespace CalendarScheduler\Adapter\Calendar;

use CalendarScheduler\Adapter\Calendar\CalendarTranslator;

/**
 * CalendarSnapshot
 *
 * Inbound snapshot ingestion from a calendar provider (provider-agnostic).
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

    private CalendarTranslator $translator;

    public function __construct(
        CalendarTranslator $translator
    ) {
        $this->translator = $translator;
    }

    /**
     * Snapshot already-translated calendar provider events into the manifest.
     *
     * @param array $providerEvents Raw calendar provider events (post-translation)
     */
    public function snapshot(array $providerEvents): void
    {
        self::write($providerEvents);
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
}
