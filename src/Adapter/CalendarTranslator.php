<?php
declare(strict_types=1);

namespace CalendarScheduler\Adapter;

use CalendarScheduler\Platform\IcsFetcher;
use CalendarScheduler\Platform\IcsParser;
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
}