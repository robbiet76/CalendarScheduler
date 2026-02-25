<?php
declare(strict_types=1);

/**
 * Calendar Scheduler — Source Component
 *
 * File: Adapter/Calendar/Outlook/OutlookCalendarTranslator.php
 * Purpose: Structural translation of Outlook events into provider-neutral rows.
 */

namespace CalendarScheduler\Adapter\Calendar\Outlook;

use CalendarScheduler\Adapter\Calendar\MapperShared;
use CalendarScheduler\Adapter\Calendar\TranslatorShared;

final class OutlookCalendarTranslator
{
    private const MANAGED_FORMAT_VERSION = '2';

    private \DateTimeZone $localTimezone;

    public function __construct()
    {
        $this->localTimezone = $this->resolveLocalTimezone();
    }

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
        /** @var array<string,int> $managedIndexByKey */
        $managedIndexByKey = [];

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

            $decodedMetadata = OutlookEventMetadataSchema::decodeFromOutlookEvent($ev);
            $observedStyleToken = MapperShared::outlookCategoriesToStyleToken(
                is_array($ev['categories'] ?? null) ? $ev['categories'] : []
            );
            if (is_string($observedStyleToken) && $observedStyleToken !== '') {
                $decodedSettings = is_array($decodedMetadata['settings'] ?? null) ? $decodedMetadata['settings'] : [];
                $decodedSettings['styleToken'] = $observedStyleToken;
                $decodedMetadata['settings'] = $decodedSettings;
            }

            $schedulerMetadata = $this->reconcileSchedulerMetadata(
                $decodedMetadata,
                $subject,
                $description
            );

            $metadataTimeZone = is_string($schedulerMetadata['timezone'] ?? null)
                ? trim((string)$schedulerMetadata['timezone'])
                : '';
            if ($metadataTimeZone !== '') {
                $effectiveSourceTimeZone = is_string($timeZone) && $timeZone !== '' ? $timeZone : 'UTC';
                $startDateTime = $this->convertDateTimeTimezone($startDateTime, $effectiveSourceTimeZone, $metadataTimeZone);
                $endDateTime = $this->convertDateTimeTimezone($endDateTime, $effectiveSourceTimeZone, $metadataTimeZone);
                $timeZone = $metadataTimeZone;
                if (is_array($start)) {
                    $start['dateTime'] = $startDateTime;
                    $start['timeZone'] = $metadataTimeZone;
                }
                if (is_array($end)) {
                    $end['dateTime'] = $endDateTime;
                    $end['timeZone'] = $metadataTimeZone;
                }
            }

            [$rrule, $exDates] = $this->translateRecurrence($ev, $type, $timeZone);
            $isOverride = ($type === 'exception' || $type === 'occurrence') && is_string($seriesMasterId) && $seriesMasterId !== '';
            $parentUid = $isOverride ? $seriesMasterId : null;
            $originalStartTime = $this->extractOriginalStartTime($ev, $timeZone);
            $uid = $id;

            $row = [
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

            $managedKey = $this->managedDedupeKey($row);
            if ($managedKey === null) {
                $out[] = $row;
                continue;
            }

            $existingIndex = $managedIndexByKey[$managedKey] ?? null;
            if (!is_int($existingIndex) || !isset($out[$existingIndex]) || !is_array($out[$existingIndex])) {
                $out[] = $row;
                $managedIndexByKey[$managedKey] = array_key_last($out);
                continue;
            }

            $existing = $out[$existingIndex];
            if ($this->preferManagedRow($existing, $row)) {
                $out[$existingIndex] = $row;
            }
        }

        return $out;
    }

    /**
     * Managed row dedupe key:
     * - Prefer manifestEventId + subEventHash when present (authoritative subevent identity).
     * - Fallback to manifest identity + execution window when subEventHash is unavailable.
     */
    private function managedDedupeKey(array $row): ?string
    {
        $payload = is_array($row['payload'] ?? null) ? $row['payload'] : [];
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        $manifestEventId = is_string($metadata['manifestEventId'] ?? null) ? trim((string)$metadata['manifestEventId']) : '';
        if ($manifestEventId === '') {
            return null;
        }
        $subEventHash = is_string($metadata['subEventHash'] ?? null) ? trim((string)$metadata['subEventHash']) : '';
        if ($subEventHash !== '') {
            return $manifestEventId . '::' . $subEventHash;
        }

        $dtstart = is_string($row['dtstart'] ?? null) ? trim((string)$row['dtstart']) : '';
        $dtend = is_string($row['dtend'] ?? null) ? trim((string)$row['dtend']) : '';
        $timezone = is_string($row['timezone'] ?? null) ? trim((string)$row['timezone']) : '';
        $settings = is_array($metadata['settings'] ?? null) ? $metadata['settings'] : [];
        $type = is_string($settings['type'] ?? null) ? trim((string)$settings['type']) : '';
        $repeat = is_string($settings['repeat'] ?? null) ? trim((string)$settings['repeat']) : '';
        $stopType = is_string($settings['stopType'] ?? null) ? trim((string)$settings['stopType']) : '';
        $enabled = array_key_exists('enabled', $settings) ? (($settings['enabled'] ?? false) ? '1' : '0') : '';

        return implode('::', [$manifestEventId, $dtstart, $dtend, $timezone, $type, $repeat, $stopType, $enabled]);
    }

    private function preferManagedRow(array $current, array $candidate): bool
    {
        $currentScore = $this->managedRowScore($current);
        $candidateScore = $this->managedRowScore($candidate);
        if ($candidateScore !== $currentScore) {
            return $candidateScore > $currentScore;
        }

        $currentUpdated = (int)($current['provenance']['updatedAtEpoch'] ?? 0);
        $candidateUpdated = (int)($candidate['provenance']['updatedAtEpoch'] ?? 0);
        return $candidateUpdated > $currentUpdated;
    }

    private function managedRowScore(array $row): int
    {
        $score = 0;
        $payload = is_array($row['payload'] ?? null) ? $row['payload'] : [];
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        $executionOrder = $metadata['executionOrder'] ?? null;
        $executionOrderManual = $metadata['executionOrderManual'] ?? null;
        $subEventHash = is_string($metadata['subEventHash'] ?? null) ? trim((string)$metadata['subEventHash']) : '';
        if (is_int($executionOrder)) {
            $score += 4;
        }
        if (is_bool($executionOrderManual)) {
            $score += 2;
        }
        if ($subEventHash !== '') {
            $score += 1;
        }
        $metadataTz = is_string($metadata['timezone'] ?? null) ? trim((string)$metadata['timezone']) : '';
        if ($metadataTz !== '') {
            $score += 2;
        }
        $rowTz = is_string($row['timezone'] ?? null) ? trim((string)$row['timezone']) : '';
        if ($metadataTz !== '' && $rowTz === $metadataTz) {
            $score += 1;
        }

        return $score;
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
    private function translateRecurrence(array $ev, string $eventType, ?string $fallbackTimeZone): array
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
        if ($freq === 'MONTHLY') {
            $dayOfMonth = (int)($pattern['dayOfMonth'] ?? 0);
            if ($dayOfMonth >= 1 && $dayOfMonth <= 31) {
                $rrule['bymonthday'] = [$dayOfMonth];
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
                $rangeTimeZone = is_string($range['recurrenceTimeZone'] ?? null)
                    ? trim((string)$range['recurrenceTimeZone'])
                    : null;
                $until = $this->buildUtcUntilFromRangeEndDate($endDate, $fallbackTimeZone, $rangeTimeZone);
                $rrule['until'] = $until ?? str_replace('-', '', $endDate);
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
        return TranslatorShared::isoToEpoch($value);
    }

    private function convertDateTimeTimezone(string $dateTime, string $fromTimeZone, string $toTimeZone): string
    {
        $dateTime = trim($dateTime);
        if ($dateTime === '' || $fromTimeZone === '' || $toTimeZone === '' || $fromTimeZone === $toTimeZone) {
            return $dateTime;
        }

        try {
            $from = new \DateTimeZone($fromTimeZone);
            $to = new \DateTimeZone($toTimeZone);
            $dt = new \DateTimeImmutable($dateTime, $from);
            return $dt->setTimezone($to)->format('Y-m-d\\TH:i:s');
        } catch (\Throwable) {
            return $dateTime;
        }
    }

    private function buildUtcUntilFromRangeEndDate(
        string $endDate,
        ?string $fallbackTimeZone,
        ?string $rangeTimeZone
    ): ?string {
        $zones = [];
        if (is_string($fallbackTimeZone) && trim($fallbackTimeZone) !== '') {
            $zones[] = trim($fallbackTimeZone);
        }
        if (is_string($rangeTimeZone) && trim($rangeTimeZone) !== '') {
            $zones[] = trim($rangeTimeZone);
        }
        $zones[] = 'UTC';

        foreach ($zones as $zone) {
            try {
                $tz = new \DateTimeZone($zone);
                $untilLocal = new \DateTimeImmutable($endDate . ' 23:59:59', $tz);
                return $untilLocal
                    ->setTimezone(new \DateTimeZone('UTC'))
                    ->format('Ymd\THis\Z');
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    /**
     * Description is treated as user input and overrides per-key metadata.
     *
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    private function reconcileSchedulerMetadata(array $metadata, string $summary, ?string $description): array
    {
        return TranslatorShared::reconcileSchedulerMetadata(
            $metadata,
            $description,
            'outlook',
            OutlookEventMetadataSchema::VERSION,
            self::MANAGED_FORMAT_VERSION,
            true
        );
    }

    /**
     * @param array<string,mixed> $settings
     * @return array<string,mixed>
     */
    private function normalizeSettings(array $settings): array
    {
        return TranslatorShared::normalizeSettings($settings);
    }

    /**
     * @param array<string,mixed> $symbolics
     * @return array<string,mixed>
     */
    private function normalizeSymbolicSettings(array $symbolics): array
    {
        return TranslatorShared::normalizeSymbolicSettings($symbolics);
    }

    private function normalizeTypeValue(string $value): string
    {
        return TranslatorShared::normalizeTypeValue($value);
    }

    private function normalizeRepeatValue(string $value): string
    {
        return TranslatorShared::normalizeRepeatValue($value);
    }

    private function normalizeStopTypeValue(string $value): string
    {
        return TranslatorShared::normalizeStopTypeValue($value);
    }

    private function normalizeExecutionOrder(mixed $value): ?int
    {
        return TranslatorShared::normalizeExecutionOrder($value);
    }

    private function normalizeExecutionOrderManual(mixed $value): ?bool
    {
        return TranslatorShared::normalizeExecutionOrderManual($value);
    }

    private function resolveLocalTimezone(): \DateTimeZone
    {
        return TranslatorShared::resolveLocalTimezone();
    }
}
