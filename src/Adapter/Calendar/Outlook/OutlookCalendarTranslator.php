<?php
declare(strict_types=1);

/**
 * Calendar Scheduler — Source Component
 *
 * File: Adapter/Calendar/Outlook/OutlookCalendarTranslator.php
 * Purpose: Structural translation of Outlook events into provider-neutral rows.
 */

namespace CalendarScheduler\Adapter\Calendar\Outlook;

use CalendarScheduler\Platform\FPPSemantics;
use CalendarScheduler\Platform\IniMetadata;

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

            $schedulerMetadata = $this->reconcileSchedulerMetadata(
                OutlookEventMetadataSchema::decodeFromOutlookEvent($ev),
                $subject,
                $description
            );
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

    /**
     * Description is treated as user input and overrides per-key metadata.
     *
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    private function reconcileSchedulerMetadata(array $metadata, string $summary, ?string $description): array
    {
        $settings = $this->normalizeSettings(
            is_array($metadata['settings'] ?? null) ? $metadata['settings'] : []
        );

        $descIni = IniMetadata::fromDescription($description);
        $descSettings = $this->normalizeSettings(
            is_array($descIni['settings'] ?? null) ? $descIni['settings'] : []
        );
        $descSymbolics = $this->normalizeSymbolicSettings(
            is_array($descIni['symbolic_time'] ?? null) ? $descIni['symbolic_time'] : []
        );

        $settings = array_merge($settings, $descSettings, $descSymbolics);

        if (!isset($settings['type']) || !is_string($settings['type']) || trim($settings['type']) === '') {
            $settings['type'] = FPPSemantics::TYPE_PLAYLIST;
        } else {
            $settings['type'] = $this->normalizeTypeValue((string)$settings['type']);
        }
        unset($settings['target']);

        if (!array_key_exists('enabled', $settings)) {
            $settings['enabled'] = FPPSemantics::defaultBehavior()['enabled'];
        } else {
            $settings['enabled'] = FPPSemantics::normalizeEnabled($settings['enabled']);
        }

        if (!isset($settings['repeat']) || !is_string($settings['repeat']) || trim($settings['repeat']) === '') {
            $settings['repeat'] = FPPSemantics::repeatToSemantic(FPPSemantics::defaultBehavior()['repeat']);
        } else {
            $settings['repeat'] = $this->normalizeRepeatValue((string)$settings['repeat']);
        }

        if (!isset($settings['stopType']) || !is_string($settings['stopType']) || trim($settings['stopType']) === '') {
            $settings['stopType'] = FPPSemantics::stopTypeToSemantic(FPPSemantics::defaultBehavior()['stopType']);
        } else {
            $settings['stopType'] = $this->normalizeStopTypeValue((string)$settings['stopType']);
        }

        if (isset($settings['start']) && is_string($settings['start'])) {
            $settings['start'] = FPPSemantics::normalizeSymbolicTimeToken(trim($settings['start']));
            if ($settings['start'] === '' || $settings['start'] === null) {
                unset($settings['start'], $settings['start_offset']);
            } else {
                $settings['start_offset'] = FPPSemantics::normalizeTimeOffset($settings['start_offset'] ?? 0);
            }
        } else {
            unset($settings['start'], $settings['start_offset']);
        }

        if (isset($settings['end']) && is_string($settings['end'])) {
            $settings['end'] = FPPSemantics::normalizeSymbolicTimeToken(trim($settings['end']));
            if ($settings['end'] === '' || $settings['end'] === null) {
                unset($settings['end'], $settings['end_offset']);
            } else {
                $settings['end_offset'] = FPPSemantics::normalizeTimeOffset($settings['end_offset'] ?? 0);
            }
        } else {
            unset($settings['end'], $settings['end_offset']);
        }

        ksort($settings);

        $currentFormatVersion = is_string($metadata['formatVersion'] ?? null)
            ? trim((string)$metadata['formatVersion'])
            : '';

        return [
            'manifestEventId' => is_string($metadata['manifestEventId'] ?? null)
                ? $metadata['manifestEventId']
                : null,
            'subEventHash' => is_string($metadata['subEventHash'] ?? null)
                ? $metadata['subEventHash']
                : null,
            'provider' => is_string($metadata['provider'] ?? null)
                ? $metadata['provider']
                : 'outlook',
            'schemaVersion' => OutlookEventMetadataSchema::VERSION,
            'formatVersion' => self::MANAGED_FORMAT_VERSION,
            'needsFormatRefresh' => ($currentFormatVersion !== self::MANAGED_FORMAT_VERSION),
            'executionOrder' => $this->normalizeExecutionOrder($metadata['executionOrder'] ?? null),
            'executionOrderManual' => $this->normalizeExecutionOrderManual($metadata['executionOrderManual'] ?? null),
            'settings' => $settings,
        ];
    }

    /**
     * @param array<string,mixed> $settings
     * @return array<string,mixed>
     */
    private function normalizeSettings(array $settings): array
    {
        $out = [];
        foreach ($settings as $k => $v) {
            if (!is_string($k) || trim($k) === '') {
                continue;
            }

            $key = strtolower(trim($k));
            $compact = preg_replace('/[^a-z0-9]/', '', $key);
            if (!is_string($compact)) {
                $compact = $key;
            }

            $key = match ($compact) {
                'scheduletype' => 'type',
                'stoptype' => 'stopType',
                'enabled' => 'enabled',
                'repeat' => 'repeat',
                'type' => 'type',
                default => $key,
            };

            $out[$key] = $v;
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $symbolics
     * @return array<string,mixed>
     */
    private function normalizeSymbolicSettings(array $symbolics): array
    {
        $out = [];
        foreach ($symbolics as $k => $v) {
            if (!is_string($k) || trim($k) === '') {
                continue;
            }

            $key = strtolower(trim($k));
            $compact = preg_replace('/[^a-z0-9]/', '', $key);
            if (!is_string($compact)) {
                $compact = $key;
            }

            $normalized = match ($compact) {
                'start', 'starttime' => 'start',
                'end', 'endtime' => 'end',
                'startoffset', 'startoffsetmin', 'starttimeoffset', 'starttimeoffsetmin' => 'start_offset',
                'endoffset', 'endoffsetmin', 'endtimeoffset', 'endtimeoffsetmin' => 'end_offset',
                default => null,
            };

            if (is_string($normalized)) {
                $out[$normalized] = $v;
            }
        }

        return $out;
    }

    private function normalizeTypeValue(string $value): string
    {
        $v = strtolower(trim($value));
        return match ($v) {
            'sequence' => FPPSemantics::TYPE_SEQUENCE,
            'command' => FPPSemantics::TYPE_COMMAND,
            default => FPPSemantics::TYPE_PLAYLIST,
        };
    }

    private function normalizeRepeatValue(string $value): string
    {
        $v = strtolower(trim($value));
        $v = str_replace(['.', ' '], '', $v);

        if ($v === '' || $v === 'none') {
            return 'none';
        }
        if ($v === 'immediate') {
            return 'immediate';
        }

        if (preg_match('/^(\d+)min$/', $v, $m) === 1) {
            return $m[1] . 'min';
        }
        if (ctype_digit($v)) {
            $n = (int)$v;
            return $n > 0 ? (string)$n . 'min' : 'none';
        }

        return 'none';
    }

    private function normalizeStopTypeValue(string $value): string
    {
        $v = strtolower(trim($value));
        $v = str_replace(['-', '_'], ' ', $v);
        $v = preg_replace('/\s+/', ' ', $v);
        if (!is_string($v)) {
            return 'graceful';
        }

        return match ($v) {
            'hard', 'hard stop' => 'hard',
            'graceful loop' => 'graceful_loop',
            default => 'graceful',
        };
    }

    private function normalizeExecutionOrder(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : 0;
        }
        if (is_string($value) && is_numeric($value)) {
            $n = (int)$value;
            return $n >= 0 ? $n : 0;
        }
        return null;
    }

    private function normalizeExecutionOrderManual(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            return is_bool($parsed) ? $parsed : null;
        }
        return null;
    }

    private function resolveLocalTimezone(): \DateTimeZone
    {
        $envPath = '/home/fpp/media/config/calendar-scheduler/runtime/fpp-env.json';
        if (is_file($envPath)) {
            $raw = @file_get_contents($envPath);
            if (is_string($raw) && $raw !== '') {
                $json = @json_decode($raw, true);
                $tzName = is_array($json) ? ($json['timezone'] ?? null) : null;
                if (is_string($tzName) && trim($tzName) !== '') {
                    try {
                        return new \DateTimeZone(trim($tzName));
                    } catch (\Throwable) {
                        // fall through
                    }
                }
            }
        }

        try {
            return new \DateTimeZone(date_default_timezone_get());
        } catch (\Throwable) {
            return new \DateTimeZone('UTC');
        }
    }
}
