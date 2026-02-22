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

            $type = is_string($ev['type'] ?? null) ? strtolower(trim((string)$ev['type'])) : 'singleinstance';
            $isCancelled = (bool)($ev['isCancelled'] ?? false);

            // Graph "occurrence" rows are generated instances and not stable source rows.
            // Keep only cancelled occurrence records (for exception/cancel semantics).
            if ($type === 'occurrence' && !$isCancelled) {
                continue;
            }

            $id = is_string($ev['id'] ?? null) ? $ev['id'] : null;
            $seriesMasterId = is_string($ev['seriesMasterId'] ?? null) ? $ev['seriesMasterId'] : null;
            $subject = is_string($ev['subject'] ?? null) ? $ev['subject'] : '';

            $bodyContent = null;
            if (is_array($ev['body'] ?? null) && is_string($ev['body']['content'] ?? null)) {
                $bodyContent = (string)$ev['body']['content'];
            }
            $preview = is_string($ev['bodyPreview'] ?? null) ? $ev['bodyPreview'] : null;
            $description = $bodyContent;
            if (!is_string($description) || trim($description) === '') {
                $description = $preview;
            }

            $start = is_array($ev['start'] ?? null) ? $ev['start'] : [];
            $end = is_array($ev['end'] ?? null) ? $ev['end'] : [];

            $startDateTime = is_string($start['dateTime'] ?? null) ? trim((string)$start['dateTime']) : '';
            $endDateTime = is_string($end['dateTime'] ?? null) ? trim((string)$end['dateTime']) : '';
            $timeZone = is_string($start['timeZone'] ?? null) && trim((string)$start['timeZone']) !== ''
                ? trim((string)$start['timeZone'])
                : (is_string($end['timeZone'] ?? null) ? trim((string)$end['timeZone']) : null);
            if ($timeZone === '') {
                $timeZone = null;
            }

            [$rrule, $exDates] = $this->translateRecurrence($ev, $type);
            $isOverride = ($type === 'exception' || $type === 'occurrence') && is_string($seriesMasterId) && $seriesMasterId !== '';
            $parentUid = $isOverride ? $seriesMasterId : null;
            $originalStartTime = $this->extractOriginalStartTime($ev, $timeZone);

            $schedulerMetadata = OutlookEventMetadataSchema::decodeFromOutlookEvent($ev);
            $uid = $id;

            $out[] = [
                'provider' => 'outlook',
                'calendar_id' => $calendarId,
                'uid' => $uid,
                'sourceEventUid' => $uid,
                'summary' => $subject,
                'description' => $description,
                'status' => $isCancelled ? 'cancelled' : (is_string($ev['showAs'] ?? null) ? $ev['showAs'] : 'busy'),
                'start' => $start,
                'end' => $end,
                'timezone' => $timeZone,
                'isAllDay' => (bool)($ev['isAllDay'] ?? false),
                'dtstart' => $startDateTime,
                'dtend' => $endDateTime,
                'rrule' => $rrule,
                'exDates' => $exDates,
                'parentUid' => $parentUid,
                'originalStartTime' => $originalStartTime,
                'isOverride' => $isOverride,
                'payload' => [
                    'summary' => $subject,
                    'description' => $description,
                    'status' => $isCancelled ? 'cancelled' : (is_string($ev['showAs'] ?? null) ? $ev['showAs'] : 'busy'),
                    'rrule' => $rrule,
                    'exDates' => $exDates,
                    'metadata' => $schedulerMetadata,
                ],
                'provenance' => $this->buildProvenance($ev, $uid),
            ];
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $ev
     * @return array<string,mixed>
     */
    private function buildProvenance(array $ev, ?string $uid): array
    {
        return [
            'uid' => $uid,
            'etag' => is_string($ev['@odata.etag'] ?? null) ? $ev['@odata.etag'] : null,
            'sequence' => null,
            'createdAtEpoch' => $this->isoToEpoch($ev['createdDateTime'] ?? null),
            'updatedAtEpoch' => $this->isoToEpoch($ev['lastModifiedDateTime'] ?? null),
        ];
    }

    /**
     * @param array<string,mixed> $ev
     * @return array{0:array<string,mixed>|null,1:array<int,string>}
     */
    private function translateRecurrence(array $ev, string $eventType): array
    {
        // Overrides/occurrences do not carry the authoritative recurring rule.
        if ($eventType === 'exception' || $eventType === 'occurrence') {
            return [null, []];
        }

        $recurrence = $ev['recurrence'] ?? null;
        if (!is_array($recurrence)) {
            return [null, []];
        }

        $pattern = is_array($recurrence['pattern'] ?? null) ? $recurrence['pattern'] : [];
        $range = is_array($recurrence['range'] ?? null) ? $recurrence['range'] : [];

        $type = is_string($pattern['type'] ?? null) ? strtolower(trim((string)$pattern['type'])) : '';
        $freq = match ($type) {
            'daily' => 'DAILY',
            'weekly' => 'WEEKLY',
            'absolutemonthly', 'relativemonthly' => 'MONTHLY',
            'absoluteyearly', 'relativeyearly' => 'YEARLY',
            default => null,
        };

        if (!is_string($freq)) {
            return [null, []];
        }

        $rrule = [
            'freq' => $freq,
            'interval' => max(1, (int)($pattern['interval'] ?? 1)),
        ];

        $days = $pattern['daysOfWeek'] ?? null;
        if ($freq === 'WEEKLY' && is_array($days) && $days !== []) {
            $byday = [];
            foreach ($days as $d) {
                if (!is_string($d)) {
                    continue;
                }
                $mapped = $this->mapOutlookWeekdayToRRule($d);
                if ($mapped !== null) {
                    $byday[] = $mapped;
                }
            }
            if ($byday !== []) {
                $rrule['byday'] = array_values(array_unique($byday));
            }
        }

        $rangeType = is_string($range['type'] ?? null) ? strtolower(trim((string)$range['type'])) : '';
        if ($rangeType === 'numbered') {
            $count = (int)($range['numberOfOccurrences'] ?? 0);
            if ($count > 0) {
                $rrule['count'] = $count;
            }
        }

        if ($rangeType === 'enddate' || $rangeType === 'numbered') {
            $endDate = is_string($range['endDate'] ?? null) ? trim((string)$range['endDate']) : '';
            if ($endDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate) === 1) {
                $rrule['until'] = str_replace('-', '', $endDate);
            }
        }

        return [$rrule, []];
    }

    /**
     * @param array<string,mixed> $ev
     * @return array<string,string>|null
     */
    private function extractOriginalStartTime(array $ev, ?string $fallbackTimeZone): ?array
    {
        $originalStart = is_string($ev['originalStart'] ?? null) ? trim((string)$ev['originalStart']) : '';
        if ($originalStart === '') {
            return null;
        }

        $tz = is_string($ev['originalStartTimeZone'] ?? null) && trim((string)$ev['originalStartTimeZone']) !== ''
            ? trim((string)$ev['originalStartTimeZone'])
            : ($fallbackTimeZone ?? 'UTC');

        return [
            'dateTime' => $originalStart,
            'timeZone' => $tz,
        ];
    }

    private function mapOutlookWeekdayToRRule(string $day): ?string
    {
        return match (strtolower(trim($day))) {
            'monday' => 'MO',
            'tuesday' => 'TU',
            'wednesday' => 'WE',
            'thursday' => 'TH',
            'friday' => 'FR',
            'saturday' => 'SA',
            'sunday' => 'SU',
            default => null,
        };
    }

    private function isoToEpoch(mixed $value): ?int
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->getTimestamp();
        } catch (\Throwable) {
            return null;
        }
    }
}
