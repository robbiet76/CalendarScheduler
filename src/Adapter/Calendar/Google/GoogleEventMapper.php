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
 *
 * ProviderState invariants:
 * - providerState.googleEvents MUST be keyed by subEventHash
 * - each entry MUST contain a valid Google event ID
 * - missing or mismatched state triggers destructive replace
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
            if (!isset($subEvent['identityHash']) || !is_string($subEvent['identityHash'])) {
                throw new RuntimeException('SubEvent missing valid identityHash');
            }

            $mutations[] = new GoogleMutation(
                op: GoogleMutation::OP_CREATE,
                calendarId: $calendarId,
                googleEventId: null,
                payload: $this->buildPayload($action, $subEvent),
                manifestEventId: $action->identityHash,
                subEventHash: $subEvent['identityHash']
            );
        }

        return $mutations;
    }

    /**
     * UPDATE semantics
     *
     * Updates are conservative:
     * - If cardinality or identity does not match, fall back to delete + create.
     *
     * @param ReconciliationAction $action
     * @param array<int, array<string,mixed>> $subEvents
     * @param string $calendarId
     * @return list<GoogleMutation>
     */
    private function mapUpdate(ReconciliationAction $action, array $subEvents, string $calendarId): array
    {
        $existing = $action->providerState['googleEvents'] ?? null;
        if (!is_array($existing)) {
            throw new RuntimeException('Update action missing providerState.googleEvents');
        }

        // Cardinality mismatch → destructive replace
        if (count($existing) !== count($subEvents)) {
            return [
                ...$this->mapDelete($action, $calendarId),
                ...$this->mapCreate($action, $subEvents, $calendarId),
            ];
        }

        $mutations = [];

        foreach ($subEvents as $subEvent) {
            if (!isset($subEvent['identityHash']) || !is_string($subEvent['identityHash'])) {
                throw new RuntimeException('SubEvent missing valid identityHash');
            }
            $hash = $subEvent['identityHash'];
            $existingEvent = $existing[$hash] ?? null;

            if (!is_array($existingEvent) || empty($existingEvent['id'])) {
                // Identity mismatch → destructive replace
                return [
                    ...$this->mapDelete($action, $calendarId),
                    ...$this->mapCreate($action, $subEvents, $calendarId),
                ];
            }

            $mutations[] = new GoogleMutation(
                op: GoogleMutation::OP_UPDATE,
                calendarId: $calendarId,
                googleEventId: $existingEvent['id'],
                payload: $this->buildPayload($action, $subEvent),
                manifestEventId: $action->identityHash,
                subEventHash: $hash
            );
        }

        return $mutations;
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
        $existing = $action->providerState['googleEvents'] ?? null;
        if (!is_array($existing) || $existing === []) {
            return [];
        }

        $mutations = [];

        foreach ($existing as $hash => $event) {
            if (empty($event['id'])) {
                continue;
            }

            $mutations[] = new GoogleMutation(
                op: GoogleMutation::OP_DELETE,
                calendarId: $calendarId,
                googleEventId: $event['id'],
                payload: [],
                manifestEventId: $action->identityHash,
                subEventHash: (string)$hash
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
        $start = $subEvent['start'] ?? null;
        $end   = $subEvent['end'] ?? null;
        $tz    = $subEvent['timezone'] ?? null;

        if (!is_array($start) || !is_array($end) || !is_string($tz)) {
            throw new RuntimeException('Invalid SubEvent timing data');
        }

        $payload = [
            'summary'     => (string)($action->summary ?? ''),
            'description' => (string)($action->description ?? ''),
            'start'       => $this->mapDateTime($start, $tz),
            'end'         => $this->mapDateTime($end, $tz),
            'extendedProperties' => [
                'private' => [
                    'cs.manifestEventId' => $action->identityHash,
                    'cs.subEventHash'    => $subEvent['identityHash'],
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
}