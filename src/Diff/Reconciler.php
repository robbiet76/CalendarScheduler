<?php

declare(strict_types=1);

/**
 * Calendar Scheduler — Source Component
 *
 * File: Diff/Reconciler.php
 * Purpose: Defines the Reconciler component used by the Calendar Scheduler Diff layer.
 */

namespace CalendarScheduler\Diff;

/**
 * Phase 4 — Reconciler
 *
 * Reconciles two *source manifests* (Calendar + FPP) into a single target manifest
 * using authoritative timestamp rules:
 *
 * 1) Later timestamp wins
 * 2) Tie => FPP wins
 *
 * Deletes are symmetric and expressed as directional actions against the losing side.
 *
 * IMPORTANT:
 * - This layer is still "Diff": operates only on manifest events + timestamps.
 * - No raw schedule.json entries, no calendar provider objects.
 */
final class Reconciler
{
    public const MODE_BOTH = 'both';
    public const MODE_CALENDAR = 'calendar';
    public const MODE_FPP = 'fpp';

    /**
     * Reconcile two candidate manifests into a target manifest and an action plan.
     *
     * @param array<string,mixed> $calendarManifest
     * @param array<string,mixed> $fppManifest
     * @param array<string,mixed> $currentManifest The last applied manifest (for managed/unmanaged/locked rules)
     * @param array<string,int>   $calendarUpdatedAtById identityHash => epoch seconds (event updatedAt)
     * @param array<string,int>   $fppUpdatedAtById      identityHash => epoch seconds (event updatedAt; may be schedule.json mtime)
     * @param array{calendar:array<string,int>,fpp:array<string,int>} $tombstonesBySource
     * @param int $calendarSnapshotEpoch epoch seconds when calendar snapshot was taken (absence timestamp proxy)
     * @param int $fppSnapshotEpoch      epoch seconds when fpp snapshot was taken (absence timestamp proxy)
     */
    public function reconcile(
        array $calendarManifest,
        array $fppManifest,
        array $currentManifest,
        array $calendarUpdatedAtById,
        array $fppUpdatedAtById,
        array $tombstonesBySource,
        int $calendarSnapshotEpoch,
        int $fppSnapshotEpoch,
        string $syncMode = self::MODE_BOTH,
        string $calendarScope = 'default'
    ): ReconciliationResult {
        $syncMode = $this->normalizeMode($syncMode);
        $calendarScope = trim($calendarScope) !== '' ? trim($calendarScope) : 'default';
        $cal = $this->indexEventsByIdentity($calendarManifest);
        $fpp = $this->indexEventsByIdentity($fppManifest);
        $cur = $this->indexEventsByIdentity($currentManifest);

        // Safety guard applies only to two-way mode. In one-way mirror modes,
        // zero overlap is a normal state (for example after calendar switching).
        if ($syncMode === self::MODE_BOTH && $cal !== [] && $fpp !== []) {
            $sharedIds = array_intersect(array_keys($cal), array_keys($fpp));
            if ($sharedIds === []) {
                throw new \RuntimeException(
                    'Reconciler safety stop: calendar and FPP manifests have zero shared identity hashes; refusing destructive convergence plan'
                );
            }
        }

        $allIds = array_unique(array_merge(array_keys($cal), array_keys($fpp), array_keys($cur)));
        sort($allIds);

        if ($syncMode === self::MODE_BOTH) {
            $tombstonesBySource = $this->inferCrossIdentityTombstones(
                $allIds,
                $cal,
                $fpp,
                $calendarUpdatedAtById,
                $fppUpdatedAtById,
                $tombstonesBySource,
                $calendarSnapshotEpoch,
                $fppSnapshotEpoch
            );
        }

        $targetEvents = [];
        $actions = [];

        foreach ($allIds as $id) {
            $calEvent = $cal[$id] ?? null;
            $fppEvent = $fpp[$id] ?? null;
            $curEvent = $cur[$id] ?? null;

            // Preserve unmanaged/locked invariants based on CURRENT manifest
            if ($curEvent !== null) {
                if ($this->isLockedEvent($curEvent)) {
                    // locked: never mutate; keep current in target
                    $targetEvents[$id] = $curEvent;
                    $actions[] = new ReconciliationAction(
                        ReconciliationAction::TYPE_BLOCK,
                        ReconciliationAction::TARGET_FPP,
                        ReconciliationAction::AUTHORITY_FPP,
                        $id,
                        'locked: preserved current manifest event',
                        $curEvent
                    );
                    continue;
                }
                if (!$this->isManagedEvent($curEvent)) {
                    // unmanaged: never mutate; keep current in target
                    $targetEvents[$id] = $curEvent;
                    $actions[] = new ReconciliationAction(
                        ReconciliationAction::TYPE_NOOP,
                        ReconciliationAction::TARGET_FPP,
                        ReconciliationAction::AUTHORITY_FPP,
                        $id,
                        'unmanaged: preserved current manifest event',
                        $curEvent
                    );
                    continue;
                }
            }

            // If both sources have no opinion and current doesn't exist, skip.
            if ($calEvent === null && $fppEvent === null && $curEvent === null) {
                continue;
            }

            if ($syncMode === self::MODE_CALENDAR) {
                // One-way mirror: calendar is authoritative regardless of tombstones/timestamps.
                $winner = 'calendar';
                $winningEvent = $calEvent;
                $reason = 'sync mode calendar->fpp: mirror calendar into fpp';
            } elseif ($syncMode === self::MODE_FPP) {
                // One-way mirror: FPP is authoritative regardless of tombstones/timestamps.
                $winner = 'fpp';
                $winningEvent = $fppEvent;
                $reason = 'sync mode fpp->calendar: mirror fpp into calendar';
            } else {
                // Two-way mode: full authority arbitration.
                $decision = $this->decideWinner(
                    $id,
                    $calEvent,
                    $fppEvent,
                    $curEvent,
                    $calendarUpdatedAtById,
                    $fppUpdatedAtById,
                    $tombstonesBySource,
                    $calendarSnapshotEpoch,
                    $fppSnapshotEpoch,
                    $calendarScope
                );

                $winner = $decision['winner']; // 'calendar'|'fpp'
                $winningEvent = $decision['event']; // array|null
                $reason = $decision['reason'];
            }

            if (is_array($winningEvent)) {
                $winningEvent = $this->carryCurrentProviderCorrelation($winningEvent, $curEvent);
                $winningEvent = $this->carryCalendarProviderCorrelation($winningEvent, $calEvent);
                $winningEvent = $this->assignActiveCalendarScope($winningEvent, $calendarScope);
            }

            // Build target manifest events
            if ($winningEvent !== null) {
                $targetEvents[$id] = $winningEvent;
            }

            // Generate directional actions to converge losing side to winning side
            // (No-ops are emitted when already converged.)
            $actions = array_merge($actions, $this->planActionsForId(
                $id,
                $winner,
                $winningEvent,
                $calEvent,
                $fppEvent,
                $reason
            ));
        }

        $targetManifest = [
            'events' => $targetEvents,
        ];

        return new ReconciliationResult($targetManifest, $actions);
    }

    private function normalizeMode(string $syncMode): string
    {
        $syncMode = strtolower(trim($syncMode));
        if (
            $syncMode === self::MODE_BOTH
            || $syncMode === self::MODE_CALENDAR
            || $syncMode === self::MODE_FPP
        ) {
            return $syncMode;
        }

        return self::MODE_BOTH;
    }

    /**
     * Infer tombstones for "replacement" conflicts where each side has a present event
     * under different identity hashes but equivalent execution signature.
     *
     * This prevents mirrored creates (calendar->fpp and fpp->calendar) for the same
     * logical event after one side reshapes recurrence/override identity.
     *
     * @param array<int,string> $allIds
     * @param array<string,array<string,mixed>> $cal
     * @param array<string,array<string,mixed>> $fpp
     * @param array<string,int> $calUpdatedAtById
     * @param array<string,int> $fppUpdatedAtById
     * @param array{calendar:array<string,int>,fpp:array<string,int>} $tombstonesBySource
     * @return array{calendar:array<string,int>,fpp:array<string,int>}
     */
    private function inferCrossIdentityTombstones(
        array $allIds,
        array $cal,
        array $fpp,
        array $calUpdatedAtById,
        array $fppUpdatedAtById,
        array $tombstonesBySource,
        int $calSnapshotEpoch,
        int $fppSnapshotEpoch
    ): array {
        $calOnly = [];
        $fppOnly = [];
        foreach ($allIds as $id) {
            $calEvent = $cal[$id] ?? null;
            $fppEvent = $fpp[$id] ?? null;
            if ($calEvent !== null && $fppEvent === null) {
                $calOnly[$id] = $calEvent;
                continue;
            }
            if ($fppEvent !== null && $calEvent === null) {
                $fppOnly[$id] = $fppEvent;
            }
        }

        if ($calOnly === [] || $fppOnly === []) {
            return $tombstonesBySource;
        }

        $fppBySignature = [];
        foreach ($fppOnly as $id => $event) {
            $sig = $this->replacementSignature($event);
            if ($sig === null) {
                continue;
            }
            if (!isset($fppBySignature[$sig])) {
                $fppBySignature[$sig] = [];
            }
            $fppBySignature[$sig][] = $id;
        }

        $usedFppIds = [];
        foreach ($calOnly as $calId => $calEvent) {
            $sig = $this->replacementSignature($calEvent);
            if ($sig === null || !isset($fppBySignature[$sig])) {
                continue;
            }

            $fppId = null;
            foreach ($fppBySignature[$sig] as $candidateId) {
                if (!isset($usedFppIds[$candidateId])) {
                    $fppId = $candidateId;
                    break;
                }
            }
            if (!is_string($fppId)) {
                continue;
            }
            $usedFppIds[$fppId] = true;

            $calTs = $this->timestampForPresenceOrAbsence(
                $calId,
                $calEvent,
                $calUpdatedAtById,
                $calSnapshotEpoch
            );
            $fppTs = $this->timestampForPresenceOrAbsence(
                $fppId,
                $fppOnly[$fppId] ?? null,
                $fppUpdatedAtById,
                $fppSnapshotEpoch
            );

            if ($calTs >= $fppTs) {
                if (!isset($tombstonesBySource['calendar'][$fppId])) {
                    $tombstonesBySource['calendar'][$fppId] = $calTs;
                }
                continue;
            }

            if (!isset($tombstonesBySource['fpp'][$calId])) {
                $tombstonesBySource['fpp'][$calId] = $fppTs;
            }
        }

        return $tombstonesBySource;
    }

    /**
     * Build a signature for pairing cross-identity replacements.
     *
     * Deliberately excludes start_date/end_date so date-anchoring changes (e.g. holiday
     * symbolic vs override hard date) can still be matched as the same logical stream.
     *
     * @param array<string,mixed> $event
     */
    private function replacementSignature(array $event): ?string
    {
        $identity = is_array($event['identity'] ?? null) ? $event['identity'] : [];
        $firstSub = (is_array($event['subEvents'] ?? null) && isset($event['subEvents'][0]) && is_array($event['subEvents'][0]))
            ? $event['subEvents'][0]
            : [];
        $identityTiming = is_array($identity['timing'] ?? null) ? $identity['timing'] : [];
        $subTiming = is_array($firstSub['timing'] ?? null) ? $firstSub['timing'] : [];
        $timing = $identityTiming !== [] ? $identityTiming : $subTiming;

        $target = $identity['target'] ?? ($event['target'] ?? null);
        $type = $identity['type'] ?? ($event['type'] ?? null);
        if (!is_string($target) || trim($target) === '' || !is_string($type) || trim($type) === '') {
            return null;
        }

        $startTime = is_array($timing['start_time'] ?? null) ? $timing['start_time'] : [];
        $endTime = is_array($timing['end_time'] ?? null) ? $timing['end_time'] : [];
        if ($endTime === []) {
            $endTime = $startTime;
        }
        $days = is_array($timing['days'] ?? null) ? $timing['days'] : null;

        $payload = [
            'type' => strtolower(trim($type)),
            'target' => trim($target),
            'all_day' => (bool)($timing['all_day'] ?? false),
            'start_time_hard' => is_string($startTime['hard'] ?? null) ? trim((string)$startTime['hard']) : null,
            'start_time_symbolic' => is_string($startTime['symbolic'] ?? null) ? trim((string)$startTime['symbolic']) : null,
            'start_time_offset' => (int)($startTime['offset'] ?? 0),
            'end_time_hard' => is_string($endTime['hard'] ?? null) ? trim((string)$endTime['hard']) : null,
            'end_time_symbolic' => is_string($endTime['symbolic'] ?? null) ? trim((string)$endTime['symbolic']) : null,
            'end_time_offset' => (int)($endTime['offset'] ?? 0),
            'days' => $days,
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    /**
     * Preserve provider linkage from current manifest when the reconciled winner
     * does not carry it (common when FPP is authoritative for state changes).
     *
     * @param array<string,mixed> $winningEvent
     * @param array<string,mixed>|null $currentEvent
     * @return array<string,mixed>
     */
    private function carryCurrentProviderCorrelation(array $winningEvent, ?array $currentEvent): array
    {
        if (!is_array($currentEvent)) {
            return $winningEvent;
        }

        $winningCorrelation = is_array($winningEvent['correlation'] ?? null) ? $winningEvent['correlation'] : [];
        $currentCorrelation = is_array($currentEvent['correlation'] ?? null) ? $currentEvent['correlation'] : [];

        $winningSourceUid = $winningCorrelation['sourceEventUid'] ?? null;
        $currentSourceUid = $currentCorrelation['sourceEventUid'] ?? null;
        if ((!is_string($winningSourceUid) || $winningSourceUid === '') && is_string($currentSourceUid) && $currentSourceUid !== '') {
            $winningCorrelation['sourceEventUid'] = $currentSourceUid;
        }

        $winningGoogleIds = $winningCorrelation['googleEventIds'] ?? null;
        $currentGoogleIds = $currentCorrelation['googleEventIds'] ?? null;
        if (!is_array($winningGoogleIds) && is_array($currentGoogleIds) && $currentGoogleIds !== []) {
            $winningCorrelation['googleEventIds'] = $currentGoogleIds;
        }

        $winningCalendarId = $winningCorrelation['sourceCalendarId'] ?? null;
        $currentCalendarId = $currentCorrelation['sourceCalendarId'] ?? null;
        if ((!is_string($winningCalendarId) || $winningCalendarId === '') && is_string($currentCalendarId) && $currentCalendarId !== '') {
            $winningCorrelation['sourceCalendarId'] = $currentCalendarId;
        }

        if ($winningCorrelation !== []) {
            $winningEvent['correlation'] = $winningCorrelation;
        }

        return $winningEvent;
    }

    /**
     * Preserve provider linkage from current calendar manifest when winner does
     * not carry it (common when FPP is authoritative for state changes).
     *
     * @param array<string,mixed> $winningEvent
     * @param array<string,mixed>|null $calendarEvent
     * @return array<string,mixed>
     */
    private function carryCalendarProviderCorrelation(array $winningEvent, ?array $calendarEvent): array
    {
        if (!is_array($calendarEvent)) {
            return $winningEvent;
        }

        $winningCorrelation = is_array($winningEvent['correlation'] ?? null) ? $winningEvent['correlation'] : [];
        $calendarCorrelation = is_array($calendarEvent['correlation'] ?? null) ? $calendarEvent['correlation'] : [];

        $winningSourceUid = $winningCorrelation['sourceEventUid'] ?? null;
        $calendarSourceUid = $calendarCorrelation['sourceEventUid'] ?? null;
        if ((!is_string($winningSourceUid) || $winningSourceUid === '') && is_string($calendarSourceUid) && $calendarSourceUid !== '') {
            $winningCorrelation['sourceEventUid'] = $calendarSourceUid;
        }

        $winningGoogleIds = $winningCorrelation['googleEventIds'] ?? null;
        $calendarGoogleIds = $calendarCorrelation['googleEventIds'] ?? null;
        if (!is_array($winningGoogleIds) && is_array($calendarGoogleIds) && $calendarGoogleIds !== []) {
            $winningCorrelation['googleEventIds'] = $calendarGoogleIds;
        }

        $winningCalendarId = $winningCorrelation['sourceCalendarId'] ?? null;
        $calendarCalendarId = $calendarCorrelation['sourceCalendarId'] ?? null;
        if ((!is_string($winningCalendarId) || $winningCalendarId === '') && is_string($calendarCalendarId) && $calendarCalendarId !== '') {
            $winningCorrelation['sourceCalendarId'] = $calendarCalendarId;
        }

        if ($winningCorrelation !== []) {
            $winningEvent['correlation'] = $winningCorrelation;
        }

        return $winningEvent;
    }

    /**
     * @param array<string,mixed>|null $calEvent
     * @param array<string,mixed>|null $fppEvent
     * @param array<string,mixed>|null $curEvent
     * @param array<string,int> $calUpdatedAtById
     * @param array<string,int> $fppUpdatedAtById
     * @param array{calendar:array<string,int>,fpp:array<string,int>} $tombstonesBySource
     * @return array{winner:string,event:array<string,mixed>|null,reason:string}
     */
    private function decideWinner(
        string $id,
        ?array $calEvent,
        ?array $fppEvent,
        ?array $curEvent,
        array $calUpdatedAtById,
        array $fppUpdatedAtById,
        array $tombstonesBySource,
        int $calSnapshotEpoch,
        int $fppSnapshotEpoch,
        string $calendarScope
    ): array {
        $orderOnlyDrift = $this->eventsDifferOnlyByExecutionOrder($calEvent, $fppEvent);

        // If both exist and state hashes match -> choose either (prefer calendar) with noop reason.
        if ($calEvent !== null && $fppEvent !== null) {
            $calState = $this->readEventStateHashOrEmpty($calEvent);
            $fppState = $this->readEventStateHashOrEmpty($fppEvent);
            if ($calState !== '' && $calState === $fppState) {
                return [
                    'winner' => 'calendar',
                    'event' => $calEvent,
                    'reason' => 'stateHash equal: already converged',
                ];
            }
        }

        // Presence-vs-absence decisions require tombstone evidence for deletes.
        // Without tombstones, preserve the present side to avoid destructive drift.
        if (($calEvent === null) !== ($fppEvent === null)) {
            $calTs = $this->timestampForPresenceOrAbsence($id, $calEvent, $calUpdatedAtById, $calSnapshotEpoch);
            $fppTs = $this->timestampForPresenceOrAbsence($id, $fppEvent, $fppUpdatedAtById, $fppSnapshotEpoch);
            $calTombstoneTs = (int)($tombstonesBySource['calendar'][$id] ?? 0);
            $fppTombstoneTs = (int)($tombstonesBySource['fpp'][$id] ?? 0);

            if ($calEvent === null && $fppEvent !== null) {
                if (
                    $calTombstoneTs > 0
                    && $calTombstoneTs >= $fppTs
                    && $this->currentEventSupportsCalendarDeleteIntent($curEvent, $calendarScope)
                ) {
                    return [
                        'winner' => 'calendar',
                        'event' => null,
                        'reason' => "calendar tombstone newer/equal ($calTombstoneTs >= $fppTs)",
                    ];
                }
                return [
                    'winner' => 'fpp',
                    'event' => $fppEvent,
                    'reason' => "calendar absent without tombstone (present fpp kept)",
                ];
            }

            if ($fppTombstoneTs > 0 && $fppTombstoneTs >= $calTs) {
                return [
                    'winner' => 'fpp',
                    'event' => null,
                    'reason' => "fpp tombstone newer/equal ($fppTombstoneTs >= $calTs)",
                ];
            }

            return [
                'winner' => 'calendar',
                'event' => $calEvent,
                'reason' => "fpp absent without tombstone (present calendar kept)",
            ];
        }

        // Compute timestamps for "presence" or "absence"
        $calTs = $this->timestampForPresenceOrAbsence($id, $calEvent, $calUpdatedAtById, $calSnapshotEpoch);
        $fppTs = $this->timestampForPresenceOrAbsence($id, $fppEvent, $fppUpdatedAtById, $fppSnapshotEpoch);

        // Later timestamp wins; tie => FPP
        if ($calTs > $fppTs) {
            return [
                'winner' => 'calendar',
                'event' => $calEvent, // may be null => calendar deleted it
                'reason' => $orderOnlyDrift
                    ? "calendar newer ($calTs > $fppTs); order changed"
                    : "calendar newer ($calTs > $fppTs)",
            ];
        }

        if ($fppTs > $calTs) {
            return [
                'winner' => 'fpp',
                'event' => $fppEvent, // may be null => fpp deleted it
                'reason' => $orderOnlyDrift
                    ? "fpp newer ($fppTs > $calTs); order changed"
                    : "fpp newer ($fppTs > $calTs)",
            ];
        }

        // tie-break
        return [
            'winner' => 'fpp',
            'event' => $fppEvent,
            'reason' => $orderOnlyDrift
                ? "tie ($calTs == $fppTs): fpp wins; order changed"
                : "tie ($calTs == $fppTs): fpp wins",
        ];
    }

    /**
     * Determine whether two present events diverge only by execution order metadata.
     *
     * @param array<string,mixed>|null $calendarEvent
     * @param array<string,mixed>|null $fppEvent
     */
    private function eventsDifferOnlyByExecutionOrder(?array $calendarEvent, ?array $fppEvent): bool
    {
        if (!is_array($calendarEvent) || !is_array($fppEvent)) {
            return false;
        }

        $calState = $this->readEventStateHashOrEmpty($calendarEvent);
        $fppState = $this->readEventStateHashOrEmpty($fppEvent);
        if ($calState === '' || $fppState === '' || $calState === $fppState) {
            return false;
        }

        $calComparable = $this->stripExecutionOrderFromEvent($calendarEvent);
        $fppComparable = $this->stripExecutionOrderFromEvent($fppEvent);

        return $calComparable === $fppComparable;
    }

    /**
     * Remove execution-order specific metadata so logical content can be compared.
     *
     * @param array<string,mixed> $event
     * @return array<string,mixed>
     */
    private function stripExecutionOrderFromEvent(array $event): array
    {
        $out = $event;
        $subEvents = $out['subEvents'] ?? null;
        if (!is_array($subEvents)) {
            return $out;
        }

        foreach ($subEvents as $idx => $subEvent) {
            if (!is_array($subEvent)) {
                continue;
            }

            unset($subEvent['executionOrder'], $subEvent['executionOrderManual']);

            $payload = is_array($subEvent['payload'] ?? null) ? $subEvent['payload'] : [];
            $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
            unset(
                $metadata['executionOrder'],
                $metadata['execution_order'],
                $metadata['executionOrderManual'],
                $metadata['execution_order_manual'],
                $metadata['cs.executionOrder'],
                $metadata['cs.executionOrderManual']
            );
            if ($metadata === []) {
                unset($payload['metadata']);
            } else {
                $payload['metadata'] = $metadata;
            }
            if ($payload === []) {
                unset($subEvent['payload']);
            } else {
                $subEvent['payload'] = $payload;
            }

            $subEvents[$idx] = $subEvent;
        }

        $out['subEvents'] = $subEvents;
        $eventStateHash = $out['stateHash'] ?? null;
        if (is_string($eventStateHash)) {
            unset($out['stateHash']);
        }

        return $out;
    }

    /**
     * Calendar tombstones are only trusted when current manifest provenance
     * ties the identity to the active calendar scope.
     *
     * @param array<string,mixed>|null $currentEvent
     */
    private function currentEventSupportsCalendarDeleteIntent(?array $currentEvent, string $calendarScope): bool
    {
        if (!is_array($currentEvent)) {
            return false;
        }

        $source = $currentEvent['source'] ?? null;
        if (!is_string($source) || strtolower(trim($source)) !== 'calendar') {
            return false;
        }

        $correlation = is_array($currentEvent['correlation'] ?? null) ? $currentEvent['correlation'] : [];
        $sourceCalendarId = $correlation['sourceCalendarId'] ?? null;
        if (!is_string($sourceCalendarId) || trim($sourceCalendarId) === '') {
            return false;
        }

        return trim($sourceCalendarId) === $calendarScope;
    }

    /**
     * Ensure reconciled manifest events are associated with the active calendar scope.
     *
     * @param array<string,mixed> $event
     * @return array<string,mixed>
     */
    private function assignActiveCalendarScope(array $event, string $calendarScope): array
    {
        $correlation = is_array($event['correlation'] ?? null) ? $event['correlation'] : [];
        $correlation['sourceCalendarId'] = $calendarScope;
        $event['correlation'] = $correlation;

        return $event;
    }

    /**
     * timestamp semantics:
     * - if event exists: use its updatedAtEpoch if provided, else snapshot epoch
     * - if event missing: absence is observed at snapshot epoch
     *
     * @param array<string,mixed>|null $event
     * @param array<string,int> $updatedAtById
     */
    private function timestampForPresenceOrAbsence(
        string $id,
        ?array $event,
        array $updatedAtById,
        int $snapshotEpoch
    ): int {
        if ($event === null) {
            return $snapshotEpoch;
        }
        $ts = $updatedAtById[$id] ?? 0;
        if ($ts > 0) {
            return $ts;
        }
        return $snapshotEpoch;
    }

    /**
     * Plan convergence actions for one identity.
     *
     * @param array<string,mixed>|null $winningEvent
     * @param array<string,mixed>|null $calEvent
     * @param array<string,mixed>|null $fppEvent
     * @return array<int,ReconciliationAction>
     */
    private function planActionsForId(
        string $id,
        string $winner, // 'calendar'|'fpp'
        ?array $winningEvent,
        ?array $calEvent,
        ?array $fppEvent,
        string $reason
    ): array {
        $actions = [];

        $authority = $winner === 'calendar'
            ? ReconciliationAction::AUTHORITY_CALENDAR
            : ReconciliationAction::AUTHORITY_FPP;

        // If winner is calendar -> we converge FPP to calendar's winningEvent
        if ($winner === 'calendar') {
            $actions[] = $this->actionToConverge(
                $id,
                ReconciliationAction::TARGET_FPP,
                $authority,
                $fppEvent,
                $winningEvent,
                $reason
            );
            // Calendar side is already the winner; noop unless calendar differs from winningEvent (rare)
            $actions[] = new ReconciliationAction(
                ReconciliationAction::TYPE_NOOP,
                ReconciliationAction::TARGET_CALENDAR,
                $authority,
                $id,
                'calendar authoritative: no-op',
                $winningEvent
            );
            return array_values(array_filter($actions));
        }

        // Winner is FPP -> converge calendar to FPP winningEvent
        $actions[] = $this->actionToConverge(
            $id,
            ReconciliationAction::TARGET_CALENDAR,
            $authority,
            $calEvent,
            $winningEvent,
            $reason
        );
        $actions[] = new ReconciliationAction(
            ReconciliationAction::TYPE_NOOP,
            ReconciliationAction::TARGET_FPP,
            $authority,
            $id,
            'fpp authoritative: no-op',
            $winningEvent
        );

        return array_values(array_filter($actions));
    }

    /**
     * Create a single convergence action (create/update/delete/noop).
     *
     * @param array<string,mixed>|null $existing
     * @param array<string,mixed>|null $desired
     */
    private function actionToConverge(
        string $id,
        string $target,
        string $authority,
        ?array $existing,
        ?array $desired,
        string $reason,
        bool $forceUpdate = false
    ): ?ReconciliationAction {
        // desired is null => delete
        if ($desired === null) {
            if ($existing === null) {
                return new ReconciliationAction(
                    ReconciliationAction::TYPE_NOOP,
                    $target,
                    $authority,
                    $id,
                    $reason . '; already absent',
                    null
                );
            }
            return new ReconciliationAction(
                ReconciliationAction::TYPE_DELETE,
                $target,
                $authority,
                $id,
                $reason . '; delete',
                $existing
            );
        }

        // desired exists
        if ($existing === null) {
            return new ReconciliationAction(
                ReconciliationAction::TYPE_CREATE,
                $target,
                $authority,
                $id,
                $reason . '; create',
                $desired
            );
        }

        // both exist: compare stateHash
        $exState = $this->readEventStateHashOrEmpty($existing);
        $deState = $this->readEventStateHashOrEmpty($desired);

        if (!$forceUpdate && $exState !== '' && $deState !== '' && $exState === $deState) {
            return new ReconciliationAction(
                ReconciliationAction::TYPE_NOOP,
                $target,
                $authority,
                $id,
                $reason . '; already matches',
                $desired
            );
        }

        return new ReconciliationAction(
            ReconciliationAction::TYPE_UPDATE,
            $target,
            $authority,
            $id,
            $reason . ($forceUpdate ? '; force format refresh update' : '; update'),
            $desired
        );
    }

    // ---------------------------------------------------------------------
    // Manifest helpers (local to reconciler; avoids importing Diff.php)
    // ---------------------------------------------------------------------

    /**
     * @param array<string,mixed> $manifest
     * @return array<string,array<string,mixed>>
     */
    private function indexEventsByIdentity(array $manifest): array
    {
        $map = [];
        $events = $manifest['events'] ?? [];
        if (!is_array($events)) {
            return $map;
        }

        $isList = array_keys($events) === range(0, count($events) - 1);
        if ($isList) {
            foreach ($events as $i => $event) {
                if (!is_array($event)) {
                    continue;
                }
                $id = $this->readEventIdentityKey($event, null);
                if ($id === '') {
                    throw new \RuntimeException('Manifest event missing identity key at events[' . $i . ']');
                }
                if (isset($map[$id])) {
                    throw new \RuntimeException('Duplicate manifest identity detected: ' . $id);
                }
                $map[$id] = $event;
            }
            return $map;
        }

        foreach ($events as $eventKey => $event) {
            if (!is_array($event)) {
                continue;
            }
            $id = $this->readEventIdentityKey($event, is_string($eventKey) ? $eventKey : null);
            if ($id === '') {
                throw new \RuntimeException('Manifest event missing identity key at events[' . (string)$eventKey . ']');
            }
            if (isset($map[$id])) {
                throw new \RuntimeException('Duplicate manifest identity detected: ' . $id);
            }
            $map[$id] = $event;
        }

        return $map;
    }

    /**
     * @param array<string,mixed> $event
     */
    private function readEventIdentityKey(array $event, ?string $fallbackKey): string
    {
        if (is_string($fallbackKey) && trim($fallbackKey) !== '') {
            return trim($fallbackKey);
        }
        $id = $event['id'] ?? null;
        if (is_string($id) && trim($id) !== '') {
            return trim($id);
        }
        $id2 = $event['identityHash'] ?? null;
        if (is_string($id2) && trim($id2) !== '') {
            return trim($id2);
        }
        return '';
    }

    /**
     * @param array<string,mixed> $event
     */
    private function isManagedEvent(array $event): bool
    {
        $ownership = $event['ownership'] ?? null;
        if (!is_array($ownership)) {
            return false;
        }
        return (bool)($ownership['managed'] ?? false);
    }

    /**
     * Locked is enforced only from CURRENT manifest.
     *
     * @param array<string,mixed> $event
     */
    private function isLockedEvent(array $event): bool
    {
        $ownership = $event['ownership'] ?? null;
        if (!is_array($ownership)) {
            return false;
        }
        return (bool)($ownership['locked'] ?? false);
    }

    /**
     * @param array<string,mixed> $event
     */
    private function readEventStateHashOrEmpty(array $event): string
    {
        $v = $event['stateHash'] ?? null;
        if (is_string($v) && trim($v) !== '') {
            return trim($v);
        }
        return '';
    }
}
