<?php
declare(strict_types=1);

namespace CalendarScheduler\Adapter\Calendar\Google;

use CalendarScheduler\Diff\ReconciliationAction;
use RuntimeException;

/**
 * GoogleEventMapper (V2)
 *
 * Pure mapper from resolved ReconciliationAction → Google Calendar mutations.
 *
 * IMPORTANT INVARIANTS:
 * - Resolution output is authoritative.
 * - Each resolved SubEvent is an atomic execution unit.
 * - Grouping is conservative: default is 1 SubEvent → 1 Google event.
 * - No I/O. No API calls. No normalization.
 */
final class GoogleEventMapper
{
    /**
     * Map a reconciliation action into one or more Google mutations.
     *
     * @return list<GoogleMutation>
     */
    public function mapAction(ReconciliationAction $action, GoogleConfig $config): array
    {
        if ($action->target !== ReconciliationAction::TARGET_CALENDAR) {
            throw new RuntimeException('GoogleEventMapper received non-calendar action');
        }

        $event = $action->event;
        $subEvents = $event['subEvents'] ?? null;

        if (!is_array($subEvents) || $subEvents === []) {
            throw new RuntimeException('Manifest event has no resolved subEvents');
        }

        $calendarId = $config->getCalendarId();

        return match ($action->type) {
            ReconciliationAction::TYPE_CREATE =>
                $this->mapCreate($action, $subEvents, $calendarId),

            ReconciliationAction::TYPE_UPDATE =>
                $this->mapUpdate($action, $subEvents, $calendarId),

            ReconciliationAction::TYPE_DELETE =>
                $this->mapDelete($action, $calendarId),

            default =>
                throw new RuntimeException('Unsupported reconciliation action type'),
        };
    }

    /**
     * CREATE semantics
     *
     * Default: one Google event per SubEvent.
     *
     * @param ReconciliationAction $action
     * @param array<int, array<string,mixed>> $subEvents
     * @param string $calendarId
     * @return list<GoogleMutation>
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
            } catch (RuntimeException $e) {
                if (!$this->isUnmappableTimingError($e)) {
                    throw $e;
                }
                error_log(
                    'GoogleEventMapper: skipping create for unmappable timing ' .
                    'identityHash=' . $action->identityHash .
                    ' subEventHash=' . $subEventHash .
                    ' reason=' . $e->getMessage()
                );
                continue;
            }

            $mutations[] = new GoogleMutation(
                op: GoogleMutation::OP_CREATE,
                calendarId: $calendarId,
                googleEventId: null,
                payload: $payload,
                manifestEventId: $action->identityHash,
                subEventHash: $subEventHash
            );
        }

        return $mutations;
    }

    /**
     * UPDATE semantics
     *
     * Step C strategy: destructive replace.
     *
     * - Treat update as delete existing + create desired.
     * - Avoid partial patch behavior without persisted provider-state mapping.
     *
     * @param ReconciliationAction $action
     * @param array<int, array<string,mixed>> $subEvents
     * @param string $calendarId
     * @return list<GoogleMutation>
     */
    private function mapUpdate(ReconciliationAction $action, array $subEvents, string $calendarId): array
    {
        $createMutations = $this->mapCreate($action, $subEvents, $calendarId);
        if ($createMutations === []) {
            error_log(
                'GoogleEventMapper: update reduced to no-op (no mappable create payloads) ' .
                'identityHash=' . $action->identityHash
            );
            return [];
        }

        $deleteMutations = [];
        try {
            $deleteMutations = $this->mapDelete($action, $calendarId);
        } catch (RuntimeException $e) {
            // If legacy/current manifest rows do not carry resolvable Google ids,
            // degrade to create-only so apply can proceed without hard failure.
            if (strpos($e->getMessage(), 'no resolvable Google event ids') === false) {
                throw $e;
            }
            error_log(
                'GoogleEventMapper: update fallback to create-only (missing delete ids) ' .
                'identityHash=' . $action->identityHash
            );
        }

        return [
            ...$deleteMutations,
            ...$createMutations,
        ];
    }

    private function isUnmappableTimingError(RuntimeException $e): bool
    {
        $msg = $e->getMessage();
        return strpos($msg, 'requires hard start_date') !== false
            || strpos($msg, 'requires hard end_date') !== false
            || strpos($msg, 'does not support symbolic start_time') !== false
            || strpos($msg, 'does not support symbolic end_time') !== false;
    }

    /**
     * DELETE semantics
     *
     * @param ReconciliationAction $action
     * @param string $calendarId
     * @return list<GoogleMutation>
     */
    private function mapDelete(ReconciliationAction $action, string $calendarId): array
    {
        $event = $action->event ?? null;
        if (!is_array($event)) {
            throw new RuntimeException('Delete action missing manifest event payload');
        }

        $subEvents = $event['subEvents'] ?? null;
        if (!is_array($subEvents) || $subEvents === []) {
            throw new RuntimeException('Delete action missing subEvents');
        }

        $ids = [];

        // Primary correlation id (calendar-originated events)
        $sourceEventUid = $event['correlation']['sourceEventUid'] ?? null;
        if (is_string($sourceEventUid) && $sourceEventUid !== '') {
            $ids[$sourceEventUid] = true;
        }

        foreach ($subEvents as $subEvent) {
            if (!is_array($subEvent)) {
                continue;
            }
            $id = $this->extractGoogleEventId($action, $subEvent);
            if (is_string($id) && $id !== '') {
                $ids[$id] = true;
            }
        }

        if ($ids === []) {
            throw new RuntimeException(
                'Delete action has no resolvable Google event ids (missing correlation.sourceEventUid and payload googleEventId).'
            );
        }

        $mutations = [];
        foreach (array_keys($ids) as $eventId) {
            $mutations[] = new GoogleMutation(
                op: GoogleMutation::OP_DELETE,
                calendarId: $calendarId,
                googleEventId: $eventId,
                payload: [],
                manifestEventId: $action->identityHash,
                subEventHash: 'delete:' . $eventId
            );
        }

        return $mutations;
    }

    /**
     * Build Google API payload from a single resolved SubEvent.
     *
     * @param ReconciliationAction $action
     * @param array<string,mixed> $subEvent
     * @return array<string,mixed>
     */
    private function buildPayload(ReconciliationAction $action, array $subEvent): array
    {
        $timing = $subEvent['timing'] ?? null;
        $payloadIn = $subEvent['payload'] ?? null;

        if (!is_array($timing) || !is_array($payloadIn)) {
            throw new RuntimeException('Invalid SubEvent manifest shape: expected timing+payload arrays');
        }

        $tz = is_string($timing['timezone'] ?? null) && $timing['timezone'] !== ''
            ? $timing['timezone']
            : 'UTC';

        [$start, $end] = $this->buildGoogleStartEndFromTiming($timing);
        $subEventHash = $this->deriveSubEventHash($subEvent);

        $summary = is_string($payloadIn['summary'] ?? null) && trim((string)$payloadIn['summary']) !== ''
            ? (string)$payloadIn['summary']
            : (string)($action->event['identity']['target'] ?? '');

        $description = is_string($payloadIn['description'] ?? null)
            ? (string)$payloadIn['description']
            : '';

        $payload = [
            'summary'     => $summary,
            'description' => $description,
            'start'       => $this->mapDateTime($start, $tz),
            'end'         => $this->mapDateTime($end, $tz),
            'extendedProperties' => [
                'private' => [
                    'cs.manifestEventId' => $action->identityHash,
                    'cs.subEventHash'    => $subEventHash,
                    'cs.provider'        => 'google',
                    'cs.schemaVersion'   => '2',
                ],
            ],
        ];

        return $payload;
    }

    /**
     * @param array<string,mixed> $dt
     * @param string $timezone
     * @return array<string,string>
     */
    private function mapDateTime(array $dt, string $timezone): array
    {
        if (!empty($dt['allDay'])) {
            return ['date' => $dt['date']];
        }

        return [
            'dateTime' => $dt['date'] . 'T' . $dt['time'],
            'timeZone' => $timezone,
        ];
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

        return hash('sha256', json_encode([
            'timing' => $subEvent['timing'] ?? null,
            'payload' => $subEvent['payload'] ?? null,
            'behavior' => $subEvent['behavior'] ?? null,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Resolve a Google event id from manifest event/subevent metadata when available.
     *
     * @param array<string,mixed> $subEvent
     */
    private function extractGoogleEventId(ReconciliationAction $action, array $subEvent): ?string
    {
        $id = $subEvent['payload']['googleEventId'] ?? null;
        if (is_string($id) && $id !== '') {
            return $id;
        }

        $corrId = $action->event['correlation']['sourceEventUid'] ?? null;
        if (is_string($corrId) && $corrId !== '') {
            return $corrId;
        }

        return null;
    }

    /**
     * Convert manifest-v2 subEvent timing into Google start/end blocks.
     *
     * @param array<string,mixed> $timing
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    private function buildGoogleStartEndFromTiming(array $timing): array
    {
        $allDay = (bool)($timing['all_day'] ?? false);
        $startDate = $timing['start_date']['hard'] ?? null;
        $endDate = $timing['end_date']['hard'] ?? null;

        if (!is_string($startDate) || $startDate === '') {
            throw new RuntimeException('Google mapping requires hard start_date');
        }
        if (!is_string($endDate) || $endDate === '') {
            throw new RuntimeException('Google mapping requires hard end_date');
        }

        if ($allDay) {
            $endExclusive = (new \DateTimeImmutable($endDate, new \DateTimeZone('UTC')))
                ->modify('+1 day')
                ->format('Y-m-d');

            return [
                ['date' => $startDate, 'allDay' => true],
                ['date' => $endExclusive, 'allDay' => true],
            ];
        }

        $startTime = $timing['start_time']['hard'] ?? null;
        $endTime = $timing['end_time']['hard'] ?? null;
        $startSymbolic = $timing['start_time']['symbolic'] ?? null;
        $endSymbolic = $timing['end_time']['symbolic'] ?? null;

        if (is_string($startSymbolic) && $startSymbolic !== '') {
            throw new RuntimeException('Google mapping does not support symbolic start_time');
        }
        if (is_string($endSymbolic) && $endSymbolic !== '') {
            throw new RuntimeException('Google mapping does not support symbolic end_time');
        }

        if (!is_string($startTime) || $startTime === '') {
            $startTime = '00:00:00';
        }
        if (!is_string($endTime) || $endTime === '') {
            $endTime = $startTime;
        }

        return [
            ['date' => $startDate, 'time' => $startTime, 'allDay' => false],
            ['date' => $endDate, 'time' => $endTime, 'allDay' => false],
        ];
    }
}
