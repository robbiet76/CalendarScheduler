<?php
declare(strict_types=1);

/**
 * Calendar Scheduler — Source Component
 *
 * File: Adapter/Calendar/Outlook/OutlookEventMapper.php
 * Purpose: Map reconciliation actions to Outlook mutations.
 */

namespace CalendarScheduler\Adapter\Calendar\Outlook;

use CalendarScheduler\Adapter\Calendar\MapperShared;
use CalendarScheduler\Diff\ReconciliationAction;
use CalendarScheduler\Platform\HolidayResolver;

final class OutlookEventMapper
{
    private const MANAGED_FORMAT_VERSION = '2';

    private bool $debugCalendar;
    private ?HolidayResolver $holidayResolver = null;
    private \DateTimeZone $localTimezone;
    private ?float $latitude = null;
    private ?float $longitude = null;

    /** @var array<string,int> */
    private array $diagnostics = [
        'unmappable_skipped' => 0,
        'delete_missing_id' => 0,
        'update_missing_id_skipped' => 0,
    ];

    public function __construct()
    {
        $this->debugCalendar = getenv('CS_DEBUG_CALENDAR') === '1';
        $this->localTimezone = $this->resolveLocalTimezone();
        $this->holidayResolver = $this->loadHolidayResolver();
        [$this->latitude, $this->longitude] = $this->loadCoordinates();
    }

    /**
     * @return list<OutlookMutation>
     */
    public function mapAction(ReconciliationAction $action, OutlookConfig $config): array
    {
        if ($action->target !== ReconciliationAction::TARGET_CALENDAR) {
            throw new \RuntimeException('OutlookEventMapper received non-calendar action');
        }

        $event = $action->event;
        $subEvents = is_array($event['subEvents'] ?? null) ? $event['subEvents'] : [];
        if ($subEvents === []) {
            throw new \RuntimeException('OutlookEventMapper: action missing subEvents');
        }

        $calendarId = $config->getCalendarId();

        return match ($action->type) {
            ReconciliationAction::TYPE_CREATE => $this->mapCreate($action, $subEvents, $calendarId),
            ReconciliationAction::TYPE_UPDATE => $this->mapUpdate($action, $subEvents, $calendarId),
            ReconciliationAction::TYPE_DELETE => $this->mapDelete($action, $subEvents, $calendarId),
            default => throw new \RuntimeException('OutlookEventMapper: unsupported reconciliation action type'),
        };
    }

    public function emitDiagnosticsSummary(): void
    {
        if (!$this->debugCalendar) {
            return;
        }

        if (
            $this->diagnostics['unmappable_skipped'] > 0
            || $this->diagnostics['delete_missing_id'] > 0
            || $this->diagnostics['update_missing_id_skipped'] > 0
        ) {
            error_log(
                'OutlookEventMapper summary: unmappable_skipped=' . $this->diagnostics['unmappable_skipped']
                . ' delete_missing_id=' . $this->diagnostics['delete_missing_id']
                . ' update_missing_id_skipped=' . $this->diagnostics['update_missing_id_skipped']
            );
        }

        $this->diagnostics = [
            'unmappable_skipped' => 0,
            'delete_missing_id' => 0,
            'update_missing_id_skipped' => 0,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $subEvents
     * @return list<OutlookMutation>
     */
    private function mapCreate(ReconciliationAction $action, array $subEvents, string $calendarId): array
    {
        $mutations = [];
        foreach ($subEvents as $subEvent) {
            if (!is_array($subEvent)) {
                continue;
            }

            $subEventHash = $this->deriveSubEventHash($subEvent);

            try {
                $payload = $this->buildPayload($action, $subEvent);
            } catch (\RuntimeException $e) {
                $this->diagnostics['unmappable_skipped']++;
                if ($this->debugCalendar) {
                    error_log(
                        'OutlookEventMapper: skipping create for unmappable timing identityHash=' .
                        $action->identityHash . ' subEventHash=' . $subEventHash . ' reason=' . $e->getMessage()
                    );
                }
                continue;
            }

            $mutations[] = new OutlookMutation(
                op: OutlookMutation::OP_CREATE,
                calendarId: $calendarId,
                outlookEventId: null,
                payload: $payload,
                manifestEventId: $action->identityHash,
                subEventHash: $subEventHash
            );
        }

        return $mutations;
    }

    /**
     * @param array<int,array<string,mixed>> $subEvents
     * @return list<OutlookMutation>
     */
    private function mapUpdate(ReconciliationAction $action, array $subEvents, string $calendarId): array
    {
        $mutations = [];
        foreach ($subEvents as $subEvent) {
            if (!is_array($subEvent)) {
                continue;
            }

            $subEventHash = $this->deriveSubEventHash($subEvent);
            $eventId = $this->resolveOutlookEventId($action, $subEvent, $subEventHash);

            try {
                $payload = $this->buildPayload($action, $subEvent);
            } catch (\RuntimeException $e) {
                $this->diagnostics['unmappable_skipped']++;
                if ($this->debugCalendar) {
                    error_log(
                        'OutlookEventMapper: skipping update for unmappable timing identityHash=' .
                        $action->identityHash . ' subEventHash=' . $subEventHash . ' reason=' . $e->getMessage()
                    );
                }
                continue;
            }

            if ($eventId === null) {
                $this->diagnostics['update_missing_id_skipped']++;
                if ($this->debugCalendar) {
                    error_log(
                        'OutlookEventMapper: update skipped (missing resolvable event id) identityHash=' .
                        $action->identityHash . ' subEventHash=' . $subEventHash
                    );
                }
                continue;
            }

            $mutations[] = new OutlookMutation(
                op: OutlookMutation::OP_UPDATE,
                calendarId: $calendarId,
                outlookEventId: $eventId,
                payload: $payload,
                manifestEventId: $action->identityHash,
                subEventHash: $subEventHash
            );
        }

        return $mutations;
    }

    /**
     * @param array<int,array<string,mixed>> $subEvents
     * @return list<OutlookMutation>
     */
    private function mapDelete(ReconciliationAction $action, array $subEvents, string $calendarId): array
    {
        $resolvedIds = [];

        $sourceUid = $action->event['correlation']['sourceEventUid'] ?? null;
        if ($this->isResolvableOutlookEventId($sourceUid)) {
            $sourceUid = trim((string)$sourceUid);
            $resolvedIds[$sourceUid] = 'delete:' . $sourceUid;
        }
        $providerUid = $action->event['correlation']['sourceProviderUid'] ?? null;
        if ($this->isResolvableOutlookEventId($providerUid)) {
            $providerUid = trim((string)$providerUid);
            $resolvedIds[$providerUid] = 'delete:' . $providerUid;
        }

        $corrIds = $action->event['correlation']['outlookEventIds'] ?? null;
        if (is_array($corrIds)) {
            foreach ($corrIds as $subHash => $id) {
                if (!is_string($id) || trim($id) === '') {
                    continue;
                }
                $id = trim($id);
                if (!$this->isResolvableOutlookEventId($id)) {
                    continue;
                }
                $subHash = is_string($subHash) && trim($subHash) !== '' ? trim($subHash) : 'delete:' . $id;
                $resolvedIds[$id] = $subHash;
            }
        }

        foreach ($subEvents as $subEvent) {
            if (!is_array($subEvent)) {
                continue;
            }

            $subEventHash = $this->deriveSubEventHash($subEvent);
            $eventId = $this->resolveOutlookEventId($action, $subEvent, $subEventHash);
            if ($eventId === null) {
                continue;
            }

            $resolvedIds[$eventId] = $subEventHash;
        }

        if ($resolvedIds === []) {
            $this->diagnostics['delete_missing_id']++;
            if ($this->debugCalendar) {
                error_log(
                    'OutlookEventMapper: skipping delete with no resolvable ids identityHash=' .
                    $action->identityHash
                );
            }
            return [];
        }

        $mutations = [];
        foreach ($resolvedIds as $eventId => $subEventHash) {
            $mutations[] = new OutlookMutation(
                op: OutlookMutation::OP_DELETE,
                calendarId: $calendarId,
                outlookEventId: $eventId,
                payload: [],
                manifestEventId: $action->identityHash,
                subEventHash: $subEventHash
            );
        }

        return array_values($mutations);
    }

    /**
     * @param array<string,mixed> $subEvent
     * @return array<string,mixed>
     */
    private function buildPayload(ReconciliationAction $action, array $subEvent): array
    {
        $timing = is_array($subEvent['timing'] ?? null) ? $subEvent['timing'] : [];
        $payloadIn = is_array($subEvent['payload'] ?? null) ? $subEvent['payload'] : [];
        $allDay = (bool)($timing['all_day'] ?? false);

        $startDate = $this->readHardDate($timing, 'start_date');
        $endDate = $this->readHardDate($timing, 'end_date');
        if ($startDate === null || $endDate === null) {
            [$resolvedStart, $resolvedEnd] = $this->resolveSymbolicDateBounds($timing, $payloadIn);
            if ($startDate === null) {
                $startDate = $resolvedStart;
            }
            if ($endDate === null) {
                $endDate = $resolvedEnd;
            }
        }
        if ($endDate === null) {
            $endDate = $startDate;
        }

        if ($startDate === null || $endDate === null) {
            throw new \RuntimeException('Missing hard start/end date');
        }

        $startTime = $allDay ? '00:00:00' : ($this->readHardTime($timing, 'start_time') ?? null);
        $endTime = $allDay ? '00:00:00' : ($this->readHardTime($timing, 'end_time') ?? null);
        if (!$allDay) {
            $startSymbolic = is_string($timing['start_time']['symbolic'] ?? null)
                ? trim((string)$timing['start_time']['symbolic'])
                : '';
            $endSymbolic = is_string($timing['end_time']['symbolic'] ?? null)
                ? trim((string)$timing['end_time']['symbolic'])
                : '';
            if ($startSymbolic !== '') {
                $startTime = $this->resolveSymbolicDisplayTime(
                    $startDate,
                    $startSymbolic,
                    isset($timing['start_time']['offset']) ? (int)$timing['start_time']['offset'] : 0
                );
            }
            if ($endSymbolic !== '') {
                $endTime = $this->resolveSymbolicDisplayTime(
                    $startDate,
                    $endSymbolic,
                    isset($timing['end_time']['offset']) ? (int)$timing['end_time']['offset'] : 0
                );
            }
            if (!is_string($startTime) || $startTime === '') {
                $startTime = '00:00:00';
            }
            if (!is_string($endTime) || $endTime === '') {
                $endTime = $startTime;
            }
        }

        [$startDate, $startTime, $endDate, $endTime] = $this->normalizeDateTimes(
            $startDate,
            $startTime,
            $endDate,
            $endTime,
            $allDay
        );

        $timezone = $this->resolveTimezone($subEvent);
        $behaviorIn = is_array($subEvent['behavior'] ?? null) ? $subEvent['behavior'] : [];
        $identity = is_array($action->event['identity'] ?? null) ? $action->event['identity'] : [];
        $identityType = is_string($identity['type'] ?? null) ? trim((string)$identity['type']) : '';
        $enabled = (bool)($behaviorIn['enabled'] ?? ($payloadIn['enabled'] ?? true));
        $repeat = is_string($behaviorIn['repeat'] ?? null)
            ? trim((string)$behaviorIn['repeat'])
            : (is_string($payloadIn['repeat'] ?? null) ? trim((string)$payloadIn['repeat']) : 'none');
        $stopType = is_string($behaviorIn['stopType'] ?? null)
            ? trim((string)$behaviorIn['stopType'])
            : (is_string($payloadIn['stopType'] ?? null) ? trim((string)$payloadIn['stopType']) : 'graceful');
        $styleToken = MapperShared::managedStyleToken($identityType, $enabled);

        $subject = $this->buildSubject($action, $subEvent);
        $description = $this->buildDescription(
            $subEvent,
            $identityType,
            $enabled,
            $repeat,
            $stopType,
            $timing
        );

        $startDateTime = $startDate . 'T' . $startTime;
        $endDateTime = $endDate . 'T' . $endTime;

        $recurrence = $this->buildOutlookRecurrence($timing, $startDate, $endDate, $timezone);
        if (is_array($recurrence)) {
            [$startDateTime, $endDateTime] = $this->buildRecurringInstanceDateTimes(
                $startDate,
                $startTime,
                $endTime,
                $allDay
            );
        } elseif ($allDay) {
            $endDateTime = $this->nextDate($endDate) . 'T00:00:00';
        }

        $subEventHash = $this->deriveSubEventHash($subEvent);
        $identityTypeMeta = $identityType !== '' ? $identityType : null;
        $repeatMeta = $repeat !== '' ? $repeat : null;
        $stopTypeMeta = $stopType !== '' ? $stopType : null;
        $privateMetadata = OutlookEventMetadataSchema::privateMetadata(
            manifestEventId: $action->identityHash,
            subEventHash: $subEventHash,
            provider: 'outlook',
            formatVersion: self::MANAGED_FORMAT_VERSION,
            type: $identityTypeMeta,
            enabled: $enabled,
            repeat: $repeatMeta,
            stopType: $stopTypeMeta,
            executionOrder: $this->extractExecutionOrder($subEvent),
            executionOrderManual: $this->extractExecutionOrderManual($subEvent),
            symbolicStart: is_string($timing['start_time']['symbolic'] ?? null)
                ? trim((string)$timing['start_time']['symbolic'])
                : null,
            symbolicStartOffset: isset($timing['start_time']['offset']) ? (int)$timing['start_time']['offset'] : null,
            symbolicEnd: is_string($timing['end_time']['symbolic'] ?? null)
                ? trim((string)$timing['end_time']['symbolic'])
                : null,
            symbolicEndOffset: isset($timing['end_time']['offset']) ? (int)$timing['end_time']['offset'] : null,
            timezone: $timezone,
            styleToken: $styleToken
        );
        $singleValueExtendedProperties = OutlookEventMetadataSchema::toSingleValueExtendedProperties($privateMetadata);

        $payload = [
            'subject' => $subject,
            'body' => [
                'contentType' => 'Text',
                'content' => $description,
            ],
            'start' => [
                'dateTime' => $startDateTime,
                'timeZone' => $timezone,
            ],
            'end' => [
                'dateTime' => $endDateTime,
                'timeZone' => $timezone,
            ],
            'isAllDay' => $allDay,
            'singleValueExtendedProperties' => $singleValueExtendedProperties,
        ];

        if (is_array($recurrence)) {
            $payload['recurrence'] = $recurrence;
        }

        $applyManagedStyle = $action->type === ReconciliationAction::TYPE_CREATE
            || MapperShared::isManagedColorEnforced();
        if ($applyManagedStyle) {
            $payload['categories'] = MapperShared::managedOutlookCategories($identityType, $enabled);
        }

        return $payload;
    }

    /**
     * @param array<string,mixed> $timing
     */
    private function readHardDate(array $timing, string $key): ?string
    {
        $node = is_array($timing[$key] ?? null) ? $timing[$key] : [];
        $value = is_string($node['hard'] ?? null) ? trim($node['hard']) : '';
        if ($value === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $timing
     */
    private function readHardTime(array $timing, string $key): ?string
    {
        $node = is_array($timing[$key] ?? null) ? $timing[$key] : [];
        $value = is_string($node['hard'] ?? null) ? trim($node['hard']) : '';
        if ($value === '' || preg_match('/^\d{2}:\d{2}:\d{2}$/', $value) !== 1) {
            return null;
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $subEvent
     */
    private function resolveTimezone(array $subEvent): string
    {
        $timing = is_array($subEvent['timing'] ?? null) ? $subEvent['timing'] : [];
        $timingTz = $timing['timezone'] ?? null;
        if (is_string($timingTz) && trim($timingTz) !== '') {
            return trim($timingTz);
        }

        $payload = is_array($subEvent['payload'] ?? null) ? $subEvent['payload'] : [];
        $tz = $payload['timezone'] ?? $payload['timeZone'] ?? null;
        if (is_string($tz) && trim($tz) !== '') {
            return trim($tz);
        }

        return $this->resolveDefaultTimezone();
    }

    /**
     * @param array<string,mixed> $subEvent
     */
    private function buildSubject(ReconciliationAction $action, array $subEvent): string
    {
        $payload = is_array($subEvent['payload'] ?? null) ? $subEvent['payload'] : [];
        $summary = $payload['summary'] ?? $payload['title'] ?? null;
        if (is_string($summary) && trim($summary) !== '') {
            return trim($summary);
        }

        $identity = is_array($action->event['identity'] ?? null) ? $action->event['identity'] : [];
        $target = is_string($identity['target'] ?? null) ? trim((string)$identity['target']) : 'event';

        return $target;
    }

    /**
     * @param array<string,mixed> $subEvent
     */
    private function buildDescription(
        array $subEvent,
        string $identityType,
        bool $enabled,
        string $repeat,
        string $stopType,
        array $timing
    ): string
    {
        $payload = is_array($subEvent['payload'] ?? null) ? $subEvent['payload'] : [];
        $description = is_string($payload['description'] ?? null) ? (string)$payload['description'] : '';

        return $this->composeManagedDescription(
            $description,
            $identityType,
            $enabled,
            $repeat,
            $stopType,
            is_array($timing['start_time'] ?? null) ? $timing['start_time'] : null,
            is_array($timing['end_time'] ?? null) ? $timing['end_time'] : null
        );
    }

    private function nextDate(string $ymd): string
    {
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $ymd . ' 00:00:00', new \DateTimeZone('UTC'));
        if (!($dt instanceof \DateTimeImmutable)) {
            return $ymd;
        }

        return $dt->modify('+1 day')->format('Y-m-d');
    }

    private function resolveDefaultTimezone(): string
    {
        $tzName = $this->localTimezone->getName();
        return is_string($tzName) && trim($tzName) !== '' ? trim($tzName) : 'UTC';
    }

    /**
     * @param array<string,mixed> $timing
     * @param array<string,mixed> $payload
     * @return array{0:?string,1:?string}
     */
    private function resolveSymbolicDateBounds(array $timing, array $payload): array
    {
        if (!($this->holidayResolver instanceof HolidayResolver)) {
            return [null, null];
        }

        $startHard = is_string($timing['start_date']['hard'] ?? null) ? trim((string)$timing['start_date']['hard']) : null;
        $endHard = is_string($timing['end_date']['hard'] ?? null) ? trim((string)$timing['end_date']['hard']) : null;
        $startSym = is_string($timing['start_date']['symbolic'] ?? null) ? trim((string)$timing['start_date']['symbolic']) : '';
        $endSym = is_string($timing['end_date']['symbolic'] ?? null) ? trim((string)$timing['end_date']['symbolic']) : '';

        $hintYear = (isset($payload['date_year_hint']) && is_numeric($payload['date_year_hint']))
            ? (int)$payload['date_year_hint']
            : 0;

        $anchorYear = $hintYear > 0
            ? $hintYear
            : ($this->extractYearFromHardDate($startHard)
                ?? $this->extractYearFromHardDate($endHard)
                ?? (int)(new \DateTimeImmutable('now', $this->localTimezone))->format('Y'));

        $startDerived = false;
        $endDerived = false;

        if (($startHard === null || $startHard === '') && $startSym !== '') {
            $resolved = $this->holidayResolver->dateFromHoliday($startSym, $anchorYear);
            if ($resolved instanceof \DateTimeImmutable) {
                $startHard = $resolved->format('Y-m-d');
                $startDerived = true;
            }
        }

        if (($endHard === null || $endHard === '') && $endSym !== '') {
            $resolved = $this->holidayResolver->dateFromHoliday($endSym, $anchorYear);
            if ($resolved instanceof \DateTimeImmutable) {
                $endHard = $resolved->format('Y-m-d');
                $endDerived = true;
            }
        }

        if (is_string($startHard) && $startHard !== '' && is_string($endHard) && $endHard !== '' && strcmp($endHard, $startHard) < 0) {
            if ($endDerived && $endSym !== '') {
                $startYear = $this->extractYearFromHardDate($startHard);
                if (is_int($startYear) && $startYear > 0) {
                    $resolved = $this->holidayResolver->dateFromHoliday($endSym, $startYear + 1);
                    if ($resolved instanceof \DateTimeImmutable) {
                        $endHard = $resolved->format('Y-m-d');
                    }
                }
            } elseif ($startDerived && $startSym !== '') {
                $endYear = $this->extractYearFromHardDate($endHard);
                if (is_int($endYear) && $endYear > 0) {
                    $resolved = $this->holidayResolver->dateFromHoliday($startSym, $endYear - 1);
                    if ($resolved instanceof \DateTimeImmutable) {
                        $startHard = $resolved->format('Y-m-d');
                    }
                }
            }
        }

        return [
            (is_string($startHard) && $startHard !== '') ? $startHard : null,
            (is_string($endHard) && $endHard !== '') ? $endHard : null,
        ];
    }

    private function extractYearFromHardDate(?string $date): ?int
    {
        return MapperShared::extractYearFromHardDate($date);
    }

    private function resolveLocalTimezone(): \DateTimeZone
    {
        return MapperShared::resolveLocalTimezone();
    }

    private function loadHolidayResolver(): ?HolidayResolver
    {
        return MapperShared::loadHolidayResolver();
    }

    /**
     * @return array{0:?float,1:?float}
     */
    private function loadCoordinates(): array
    {
        return MapperShared::loadCoordinates();
    }

    private function resolveSymbolicDisplayTime(string $date, string $symbolic, int $offset): ?string
    {
        return MapperShared::resolveSymbolicDisplayTime(
            $date,
            $symbolic,
            $offset,
            $this->latitude,
            $this->longitude,
            $this->localTimezone->getName()
        );
    }

    private function composeManagedDescription(
        string $existingDescription,
        string $type,
        bool $enabled,
        string $repeat,
        string $stopType,
        ?array $startTime,
        ?array $endTime
    ): string {
        return MapperShared::composeManagedDescription(
            $existingDescription,
            $type,
            $enabled,
            $repeat,
            $stopType,
            $startTime,
            $endTime
        );
    }

    private function stripManagedSections(string $description): string
    {
        return MapperShared::stripManagedSections($description);
    }

    private function formatTypeForDescription(string $type): string
    {
        return match (strtolower(trim($type))) {
            'sequence' => 'Sequence',
            'command' => 'Command',
            default => 'Playlist',
        };
    }

    private function formatRepeatForDescription(string $repeat): string
    {
        $r = strtolower(trim($repeat));
        if ($r === 'immediate') {
            return 'Immediate';
        }
        if ($r === 'none' || $r === '') {
            return 'None';
        }
        if (preg_match('/^(\d+)min$/', $r, $m) === 1) {
            return $m[1];
        }
        if (ctype_digit($r)) {
            $n = (int)$r;
            if ($n > 0) {
                return (string)$n;
            }
        }

        return 'None';
    }

    private function formatStopTypeForDescription(string $stopType): string
    {
        $v = strtolower(trim($stopType));
        return match ($v) {
            'hard', 'hard_stop', 'hard stop' => 'Hard Stop',
            'graceful_loop', 'graceful loop' => 'Graceful Loop',
            default => 'Graceful',
        };
    }

    /**
     * @return array{0:string,1:string,2:string,3:string}
     */
    private function normalizeDateTimes(
        string $startDate,
        string $startTime,
        string $endDate,
        string $endTime,
        bool $allDay
    ): array {
        if ($allDay) {
            return [$startDate, '00:00:00', $endDate, '00:00:00'];
        }

        $start = new \DateTimeImmutable($startDate . ' ' . $startTime, new \DateTimeZone('UTC'));
        $end = new \DateTimeImmutable($endDate . ' ' . $endTime, new \DateTimeZone('UTC'));

        if ($end <= $start) {
            if ($endDate === $startDate && strcmp($endTime, $startTime) < 0) {
                $end = $end->modify('+1 day');
            } else {
                $end = $start->modify('+1 minute');
            }
        }

        return [
            $start->format('Y-m-d'),
            $start->format('H:i:s'),
            $end->format('Y-m-d'),
            $end->format('H:i:s'),
        ];
    }

    /**
     * @param array<string,mixed> $timing
     * @return array<string,mixed>|null
     */
    private function buildOutlookRecurrence(
        array $timing,
        string $startDate,
        string $endDate,
        string $timezone
    ): ?array
    {
        $weeklyDays = $this->extractWeeklyDays($timing);
        $isRange = strcmp($endDate, $startDate) > 0;
        if ($weeklyDays === [] && !$isRange) {
            return null;
        }

        $pattern = [
            'type' => $weeklyDays !== [] ? 'weekly' : 'daily',
            'interval' => 1,
        ];
        if ($weeklyDays !== []) {
            $pattern['daysOfWeek'] = $weeklyDays;
            $pattern['firstDayOfWeek'] = 'sunday';
        }

        return [
            'pattern' => $pattern,
            'range' => [
                'type' => 'endDate',
                'startDate' => $startDate,
                'endDate' => $endDate,
                'recurrenceTimeZone' => $timezone,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $timing
     * @return list<string>
     */
    private function extractWeeklyDays(array $timing): array
    {
        $days = is_array($timing['days'] ?? null) ? $timing['days'] : [];
        if (($days['type'] ?? null) !== 'weekly' || !is_array($days['value'] ?? null)) {
            return [];
        }

        $out = [];
        foreach ($days['value'] as $value) {
            if (!is_string($value)) {
                continue;
            }
            $mapped = $this->mapCanonicalWeekdayToOutlook($value);
            if ($mapped !== null) {
                $out[] = $mapped;
            }
        }

        $out = array_values(array_unique($out));
        return $out;
    }

    private function mapCanonicalWeekdayToOutlook(string $value): ?string
    {
        $key = strtoupper(trim($value));
        return match ($key) {
            'SU', 'SUNDAY' => 'sunday',
            'MO', 'MONDAY' => 'monday',
            'TU', 'TUESDAY' => 'tuesday',
            'WE', 'WEDNESDAY' => 'wednesday',
            'TH', 'THURSDAY' => 'thursday',
            'FR', 'FRIDAY' => 'friday',
            'SA', 'SATURDAY' => 'saturday',
            default => null,
        };
    }

    /**
     * @return array{0:string,1:string}
     */
    private function buildRecurringInstanceDateTimes(
        string $startDate,
        string $startTime,
        string $endTime,
        bool $allDay
    ): array {
        if ($allDay) {
            return [$startDate . 'T00:00:00', $this->nextDate($startDate) . 'T00:00:00'];
        }

        $instanceEndDate = $startDate;
        if (strcmp($endTime, $startTime) < 0) {
            $instanceEndDate = $this->nextDate($startDate);
        } elseif ($endTime === $startTime) {
            $end = new \DateTimeImmutable($startDate . ' ' . $endTime, new \DateTimeZone('UTC'));
            $end = $end->modify('+1 minute');
            $instanceEndDate = $end->format('Y-m-d');
            $endTime = $end->format('H:i:s');
        }

        return [
            $startDate . 'T' . $startTime,
            $instanceEndDate . 'T' . $endTime,
        ];
    }

    /**
     * @param array<string,mixed> $subEvent
     */
    private function extractExecutionOrder(array $subEvent): ?int
    {
        return MapperShared::extractExecutionOrder($subEvent);
    }

    /**
     * @param array<string,mixed> $subEvent
     */
    private function extractExecutionOrderManual(array $subEvent): ?bool
    {
        return MapperShared::extractExecutionOrderManual($subEvent);
    }

    /**
     * @param array<string,mixed> $subEvent
     */
    private function deriveSubEventHash(array $subEvent): string
    {
        $stateHash = $subEvent['stateHash'] ?? null;
        if (is_string($stateHash) && $stateHash !== '') {
            return $stateHash;
        }

        $encoded = json_encode($subEvent, JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            $encoded = serialize($subEvent);
        }

        return hash('sha256', $encoded);
    }

    /**
     * @param array<string,mixed> $subEvent
     */
    private function resolveOutlookEventId(
        ReconciliationAction $action,
        array $subEvent,
        string $subEventHash
    ): ?string {
        $payload = is_array($subEvent['payload'] ?? null) ? $subEvent['payload'] : [];

        $id = $payload['outlookEventId'] ?? null;
        if ($this->isResolvableOutlookEventId($id)) {
            return trim($id);
        }

        $corrIds = $action->event['correlation']['outlookEventIds'] ?? null;
        if (is_array($corrIds)) {
            $corrId = $corrIds[$subEventHash] ?? null;
            if ($this->isResolvableOutlookEventId($corrId)) {
                return trim($corrId);
            }
        }

        $sourceUid = $action->event['correlation']['sourceEventUid'] ?? null;
        if ($this->isResolvableOutlookEventId($sourceUid)) {
            return trim($sourceUid);
        }

        $providerUid = $action->event['correlation']['sourceProviderUid'] ?? null;
        if ($this->isResolvableOutlookEventId($providerUid)) {
            return trim($providerUid);
        }

        return null;
    }

    private function isResolvableOutlookEventId(mixed $value): bool
    {
        if (!is_string($value)) {
            return false;
        }
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        // Internal manifest identity hashes are sha256 and are never valid Graph event ids.
        if (preg_match('/^[a-f0-9]{64}$/i', $value) === 1) {
            return false;
        }

        return true;
    }
}
