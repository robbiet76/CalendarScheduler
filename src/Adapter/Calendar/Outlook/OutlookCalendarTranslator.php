<?php
declare(strict_types=1);

/**
 * Calendar Scheduler — Source Component
 *
 * File: Adapter/Calendar/Outlook/OutlookCalendarTranslator.php
 * Purpose: Structural translation of Outlook events into provider-neutral rows.
 */

namespace CalendarScheduler\Adapter\Calendar\Outlook;

final class OutlookCalendarTranslator
{
    /**
     * @param array<int,array<string,mixed>> $outlookEvents
     * @return array<int,array<string,mixed>>
     */
    public function ingest(array $outlookEvents, string $calendarId): array
    {
        return $this->translateOutlookEvents($outlookEvents, $calendarId);
    }

    /**
     * @param array<int,array<string,mixed>> $outlookEvents
     * @return array<int,array<string,mixed>>
     */
    public function translateOutlookEvents(array $outlookEvents, string $calendarId): array
    {
        $out = [];

        foreach ($outlookEvents as $ev) {
            if (!is_array($ev)) {
                continue;
            }

            $id = is_string($ev['id'] ?? null) ? $ev['id'] : null;
            $subject = is_string($ev['subject'] ?? null) ? $ev['subject'] : '';
            $preview = is_string($ev['bodyPreview'] ?? null) ? $ev['bodyPreview'] : null;
            $start = is_array($ev['start'] ?? null) ? $ev['start'] : [];
            $end = is_array($ev['end'] ?? null) ? $ev['end'] : [];

            $startDateTime = is_string($start['dateTime'] ?? null) ? $start['dateTime'] : '';
            $endDateTime = is_string($end['dateTime'] ?? null) ? $end['dateTime'] : '';
            $timeZone = is_string($start['timeZone'] ?? null) ? $start['timeZone'] : null;

            $schedulerMetadata = OutlookEventMetadataSchema::decodeFromOutlookEvent($ev);

            $out[] = [
                'provider' => 'outlook',
                'calendar_id' => $calendarId,
                'uid' => $id,
                'sourceEventUid' => $id,
                'summary' => $subject,
                'description' => $preview,
                'status' => is_string($ev['showAs'] ?? null) ? $ev['showAs'] : 'busy',
                'start' => $start,
                'end' => $end,
                'timezone' => $timeZone,
                'isAllDay' => (bool)($ev['isAllDay'] ?? false),
                'dtstart' => $startDateTime,
                'dtend' => $endDateTime,
                'rrule' => null,
                'exDates' => [],
                'parentUid' => null,
                'originalStartTime' => null,
                'isOverride' => false,
                'payload' => [
                    'summary' => $subject,
                    'description' => $preview,
                    'metadata' => $schedulerMetadata,
                ],
                'provenance' => [
                    'uid' => $id,
                    'etag' => is_string($ev['@odata.etag'] ?? null) ? $ev['@odata.etag'] : null,
                    'createdAtEpoch' => null,
                    'updatedAtEpoch' => null,
                ],
            ];
        }

        return $out;
    }
}
