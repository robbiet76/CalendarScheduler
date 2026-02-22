<?php
declare(strict_types=1);

/**
 * Calendar Scheduler — Source Component
 *
 * File: Adapter/Calendar/Outlook/OutlookEventMapper.php
 * Purpose: Map reconciliation actions to Outlook mutations.
 */

namespace CalendarScheduler\Adapter\Calendar\Outlook;

use CalendarScheduler\Diff\ReconciliationAction;

final class OutlookEventMapper
{
    private const MANAGED_FORMAT_VERSION = '2';

    private bool $debugCalendar;

    /** @var array<string,int> */
    private array $diagnostics = [
        'unmappable_skipped' => 0,
        'delete_missing_id' => 0,
    ];

    public function __construct()
    {
        $this->debugCalendar = getenv('GCS_DEBUG_CALENDAR') === '1';
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

        if ($this->diagnostics['unmappable_skipped'] > 0 || $this->diagnostics['delete_missing_id'] > 0) {
            error_log(
                'OutlookEventMapper summary: unmappable_skipped=' . $this->diagnostics['unmappable_skipped']
                . ' delete_missing_id=' . $this->diagnostics['delete_missing_id']
            );
        }

        $this->diagnostics = [
            'unmappable_skipped' => 0,
            'delete_missing_id' => 0,
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
                $mutations[] = new OutlookMutation(
                    op: OutlookMutation::OP_CREATE,
                    calendarId: $calendarId,
                    outlookEventId: null,
                    payload: $payload,
                    manifestEventId: $action->identityHash,
                    subEventHash: $subEventHash
                );
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
        $mutations = [];

        foreach ($subEvents as $subEvent) {
            if (!is_array($subEvent)) {
                continue;
            }

            $subEventHash = $this->deriveSubEventHash($subEvent);
            $eventId = $this->resolveOutlookEventId($action, $subEvent, $subEventHash);
            if ($eventId === null) {
                $this->diagnostics['delete_missing_id']++;
                if ($this->debugCalendar) {
                    error_log(
                        'OutlookEventMapper: skipping delete with no resolvable id identityHash=' .
                        $action->identityHash . ' subEventHash=' . $subEventHash
                    );
                }
                continue;
            }

            $mutations[] = new OutlookMutation(
                op: OutlookMutation::OP_DELETE,
                calendarId: $calendarId,
                outlookEventId: $eventId,
                payload: [],
                manifestEventId: $action->identityHash,
                subEventHash: $subEventHash
            );
        }

        return $mutations;
    }

    /**
     * @param array<string,mixed> $subEvent
     * @return array<string,mixed>
     */
    private function buildPayload(ReconciliationAction $action, array $subEvent): array
    {
        $timing = is_array($subEvent['timing'] ?? null) ? $subEvent['timing'] : [];
        $allDay = (bool)($timing['all_day'] ?? false);

        $startDate = $this->readHardDate($timing, 'start_date');
        $endDate = $this->readHardDate($timing, 'end_date') ?? $startDate;

        if ($startDate === null || $endDate === null) {
            throw new \RuntimeException('Missing hard start/end date');
        }

        $startTime = $allDay ? '00:00:00' : ($this->readHardTime($timing, 'start_time') ?? null);
        $endTime = $allDay ? '00:00:00' : ($this->readHardTime($timing, 'end_time') ?? null);
        if ($startTime === null || $endTime === null) {
            throw new \RuntimeException('Missing hard start/end time for timed event');
        }

        $timezone = $this->resolveTimezone($subEvent);

        $subject = $this->buildSubject($action, $subEvent);
        $description = $this->buildDescription($action, $subEvent);

        $startDateTime = $startDate . 'T' . $startTime;
        $endDateTime = $endDate . 'T' . $endTime;

        if ($allDay) {
            $endDateTime = $this->nextDate($endDate) . 'T00:00:00';
        }

        $subEventHash = $this->deriveSubEventHash($subEvent);
        $privateMetadata = OutlookEventMetadataSchema::privateMetadata(
            manifestEventId: $action->identityHash,
            subEventHash: $subEventHash,
            provider: 'outlook',
            formatVersion: self::MANAGED_FORMAT_VERSION
        );
        $singleValueExtendedProperties = OutlookEventMetadataSchema::toSingleValueExtendedProperties($privateMetadata);

        return [
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
        $payload = is_array($subEvent['payload'] ?? null) ? $subEvent['payload'] : [];
        $tz = $payload['timezone'] ?? $payload['timeZone'] ?? null;
        if (is_string($tz) && trim($tz) !== '') {
            return trim($tz);
        }

        return 'UTC';
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
        $type = is_string($identity['type'] ?? null) ? trim((string)$identity['type']) : 'show';
        $target = is_string($identity['target'] ?? null) ? trim((string)$identity['target']) : 'event';

        return $type . ': ' . $target;
    }

    /**
     * @param array<string,mixed> $subEvent
     */
    private function buildDescription(ReconciliationAction $action, array $subEvent): string
    {
        $payload = is_array($subEvent['payload'] ?? null) ? $subEvent['payload'] : [];
        $description = $payload['description'] ?? null;
        if (is_string($description) && trim($description) !== '') {
            return trim($description);
        }

        return 'Managed by CalendarScheduler (' . $action->identityHash . ')';
    }

    private function nextDate(string $ymd): string
    {
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $ymd . ' 00:00:00', new \DateTimeZone('UTC'));
        if (!($dt instanceof \DateTimeImmutable)) {
            return $ymd;
        }

        return $dt->modify('+1 day')->format('Y-m-d');
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
        if (is_string($id) && trim($id) !== '') {
            return trim($id);
        }

        $corrIds = $action->event['correlation']['outlookEventIds'] ?? null;
        if (is_array($corrIds)) {
            $corrId = $corrIds[$subEventHash] ?? null;
            if (is_string($corrId) && trim($corrId) !== '') {
                return trim($corrId);
            }
        }

        return null;
    }
}
