<?php

declare(strict_types=1);

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
        int $fppSnapshotEpoch
    ): ReconciliationResult {
        $cal = $this->indexEventsByIdentity($calendarManifest);
        $fpp = $this->indexEventsByIdentity($fppManifest);
        $cur = $this->indexEventsByIdentity($currentManifest);

        // Safety guard:
        // If both sources are non-empty but have zero shared identities, treat as a hard failure.
        // This indicates a normalization/identity regression and would otherwise plan destructive
        // bidirectional deletes.
        if ($cal !== [] && $fpp !== []) {
            $sharedIds = array_intersect(array_keys($cal), array_keys($fpp));
            if ($sharedIds === []) {
                throw new \RuntimeException(
                    'Reconciler safety stop: calendar and FPP manifests have zero shared identity hashes; refusing destructive convergence plan'
                );
            }
        }

        $allIds = array_unique(array_merge(array_keys($cal), array_keys($fpp), array_keys($cur)));
        sort($allIds);

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

            // Decide winning side and winning event (may be null meaning "delete everywhere")
            $decision = $this->decideWinner(
                $id,
                $calEvent,
                $fppEvent,
                $calendarUpdatedAtById,
                $fppUpdatedAtById,
                $tombstonesBySource,
                $calendarSnapshotEpoch,
                $fppSnapshotEpoch
            );

            $winner = $decision['winner']; // 'calendar'|'fpp'
            $winningEvent = $decision['event']; // array|null
            $reason = $decision['reason'];

            if (is_array($winningEvent)) {
                $winningEvent = $this->carryCurrentProviderCorrelation($winningEvent, $curEvent);
                $winningEvent = $this->carryCalendarProviderCorrelation($winningEvent, $calEvent);
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

        if ($winningCorrelation !== []) {
            $winningEvent['correlation'] = $winningCorrelation;
        }

        return $winningEvent;
    }

    /**
     * @param array<string,mixed>|null $calEvent
     * @param array<string,mixed>|null $fppEvent
     * @param array<string,int> $calUpdatedAtById
     * @param array<string,int> $fppUpdatedAtById
     * @param array{calendar:array<string,int>,fpp:array<string,int>} $tombstonesBySource
     * @return array{winner:string,event:array<string,mixed>|null,reason:string}
     */
    private function decideWinner(
        string $id,
        ?array $calEvent,
        ?array $fppEvent,
        array $calUpdatedAtById,
        array $fppUpdatedAtById,
        array $tombstonesBySource,
        int $calSnapshotEpoch,
        int $fppSnapshotEpoch
    ): array {
        // If both exist and state hashes match -> choose either (prefer calendar) with noop reason.
        if ($calEvent !== null && $fppEvent !== null) {
            $calState = $this->readEventStateHashOrEmpty($calEvent);
            $fppState = $this->readEventStateHashOrEmpty($fppEvent);
            if ($calState !== '' && $calState === $fppState) {
                if ($this->eventNeedsCalendarFormatRefresh($calEvent)) {
                    return [
                        'winner' => 'fpp',
                        'event' => $fppEvent,
                        'reason' => 'stateHash equal but calendar format refresh required',
                    ];
                }
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
                if ($calTombstoneTs > 0 && $calTombstoneTs >= $fppTs) {
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
                'reason' => "calendar newer ($calTs > $fppTs)",
            ];
        }

        if ($fppTs > $calTs) {
            return [
                'winner' => 'fpp',
                'event' => $fppEvent, // may be null => fpp deleted it
                'reason' => "fpp newer ($fppTs > $calTs)",
            ];
        }

        // tie-break
        return [
            'winner' => 'fpp',
            'event' => $fppEvent,
            'reason' => "tie ($calTs == $fppTs): fpp wins",
        ];
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
            $reason,
            $this->eventNeedsCalendarFormatRefresh($calEvent)
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

    /**
     * Detect whether a calendar event should be force-updated to refresh managed description format.
     *
     * @param array<string,mixed>|null $event
     */
    private function eventNeedsCalendarFormatRefresh(?array $event): bool
    {
        if (!is_array($event)) {
            return false;
        }

        $subEvents = $event['subEvents'] ?? null;
        if (!is_array($subEvents) || $subEvents === []) {
            return false;
        }

        $first = $subEvents[0] ?? null;
        if (!is_array($first)) {
            return false;
        }

        $payload = is_array($first['payload'] ?? null) ? $first['payload'] : [];
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
        $needs = $metadata['needsFormatRefresh'] ?? false;

        return $needs === true;
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
