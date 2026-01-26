<?php
declare(strict_types=1);

namespace GoogleCalendarScheduler\Platform;

use GoogleCalendarScheduler\Platform\IcsFetcher;
use GoogleCalendarScheduler\Platform\IcsParser;
use DateTimeImmutable;

/**
 * CalendarTranslator
 *
 * CalendarTranslator adapts provider calendar data into raw, provider-neutral calendar event records.
 * It MUST NOT resolve recurrence, symbolic dates, sun times, or emit manifest-shaped data.
 * This class emits raw calendar data only (no intent, no metadata parsing).
 */
final class CalendarTranslator
{
    /**
     * Canonical calendar snapshot path on FPP.
     *
     * This file is the provider-neutral, raw calendar event cache
     * consumed by IntentNormalizer::fromCalendar().
     */
    private const SNAPSHOT_PATH = '/home/fpp/media/config/plugin.googleCalendarScheduler.calendarSnapshot.json';

    /**
     * Translate an ICS source (file path or URL) into provider-neutral calendar event records.
     *
     * @param string $icsSource Path or URL to ICS
     * @return array<int,array<string,mixed>> Provider-neutral calendar event records
     */
    public function translateIcsSourceToCalendarEvents(string $icsSource): array
    {
        $fetcher = new IcsFetcher();
        $parser  = new IcsParser();

        $raw = $fetcher->fetch($icsSource);
        if ($raw === '') {
            return [];
        }

        $records = $parser->parse($raw);

        return $this->translateRecords($records);
    }

    /**
     * Translate provider-neutral records into calendar event records.
     *
     * @param array<int,array<string,mixed>> $records
     * @return array<int,array<string,mixed>>
     */
    private function translateRecords(array $records): array
    {
        $events = [];

        foreach ($records as $rec) {
            $events[] = [
                'summary'  => $rec['summary'] ?? '',

                'dtstart'  => $rec['start'], // provider-timezone timestamp as produced by IcsParser
                'dtend'    => $rec['end'],   // provider-timezone timestamp as produced by IcsParser

                'rrule'    => $rec['rrule'] ?? null,

                'description' => $rec['description'] ?? null,

                'uid'          => $rec['uid'] ?? null,
                'isAllDay'     => $rec['isAllDay'] ?? false,
                'exDates'      => $rec['exDates'] ?? [],
                'recurrenceId' => $rec['recurrenceId'] ?? null,
                'isOverride'   => $rec['isOverride'] ?? false,
            ];
        }

        return $events;
    }

    /**
     * Persist provider-neutral calendar events to the canonical snapshot file.
     *
     * @param array<int,array<string,mixed>> $events
     */
    public function writeSnapshot(array $events): void
    {
        $payload = [
            'generatedAt' => (new DateTimeImmutable())->format(DateTimeImmutable::ATOM),
            'calendar_events' => $events,
        ];

        file_put_contents(
            self::SNAPSHOT_PATH,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Load provider-neutral calendar events from the canonical snapshot file.
     *
     * @return array<int,array<string,mixed>>
     */
    public function loadSnapshot(): array
    {
        if (!is_file(self::SNAPSHOT_PATH)) {
            return [];
        }

        $raw = json_decode(
            file_get_contents(self::SNAPSHOT_PATH),
            true
        );

        if (!is_array($raw) || !isset($raw['calendar_events']) || !is_array($raw['calendar_events'])) {
            return [];
        }

        return $raw['calendar_events'];
    }
}