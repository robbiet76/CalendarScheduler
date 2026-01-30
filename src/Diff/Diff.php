<?php

declare(strict_types=1);

namespace GoogleCalendarScheduler\Diff;

/**
 * Phase 4 — Diff (manifest-based, spec-compliant)
 *
 * Inputs:
 *  - Desired state: next manifest (array decoded from manifest.json)
 *  - Current state: current manifest (array decoded from manifest.json)
 *
 * Identity:
 *  - Canonical reconciliation key is the Manifest Event identity hash / id
 *  - Creates/Deletes are driven ONLY by identity key presence/absence
 *
 * Update rules:
 *  - Ordering differences alone do NOT trigger updates
 *  - Updates are driven ONLY by stateHash equality (event-level)
 *
 * Managed/unmanaged rules:
 *  - unmanaged events are never mutated or deleted
 *  - if next manifest attempts to manage an identity that is currently unmanaged => hard fail
 *
 * Output:
 *  - DiffResult { creates, updates, deletes }
 *    Each element is a Manifest Event array (not schedule.json entries)
 *
 * No I/O. No FPP schedule.json concerns. This is pure semantic diff.
 */
final class Diff
{
    /**
     * Diff two manifest documents.
     *
     * @param array<string,mixed> $nextManifest Desired manifest (next)
     * @param array<string,mixed> $currentManifest Current manifest (previous)
     */
    public function diff(array $nextManifest, array $currentManifest): DiffResult
    {
        $nextById = $this->indexEventsByIdentity($nextManifest);
        $currentById = $this->indexEventsByIdentity($currentManifest);

        // Enforce unmanaged collision rule:
        // If an identity exists in current as unmanaged, next must NOT attempt to manage it.
        foreach ($currentById as $id => $cur) {
            if (!$this->isManagedEvent($cur)) {
                $next = $nextById[$id] ?? null;
                if ($next !== null && $this->isManagedEvent($next)) {
                    throw new \RuntimeException(
                        'Managed/unmanaged collision: next attempts to manage an unmanaged identity: ' . $id
                    );
                }
            }
        }

        $creates = [];
        $updates = [];
        $deletes = [];

        // Creates & Updates (managed only)
        foreach ($nextById as $id => $nextEvent) {
            if (!$this->isManagedEvent($nextEvent)) {
                continue;
            }

            $curEvent = $currentById[$id] ?? null;

            if ($curEvent === null) {
                $creates[] = $nextEvent;
                continue;
            }

            // If current is unmanaged and next is managed, collision was already handled above.
            if (!$this->isManagedEvent($curEvent)) {
                continue;
            }

            $nextState = $this->readEventStateHash($nextEvent);
            $curState = $this->readEventStateHash($curEvent);

            if ($nextState !== $curState) {
                $updates[] = $nextEvent;
            }
        }

        // Deletes (managed only)
        foreach ($currentById as $id => $curEvent) {
            if (!$this->isManagedEvent($curEvent)) {
                continue;
            }

            // If desired state does not include this identity => delete
            if (!isset($nextById[$id])) {
                $deletes[] = $curEvent;
            }
        }

        // Deterministic ordering of result sets
        $sort = static function (array $a, array $b): int {
            $ak = (string)($a['id'] ?? $a['identityHash'] ?? '');
            $bk = (string)($b['id'] ?? $b['identityHash'] ?? '');
            return $ak <=> $bk;
        };

        usort($creates, $sort);
        usort($updates, $sort);
        usort($deletes, $sort);

        return new DiffResult($creates, $updates, $deletes);
    }

    /**
     * Index manifest events by canonical identity key.
     *
     * Supported shapes:
     * - { "events": { "<id>": { ...event... }, ... } }
     * - { "events": [ { ...event... }, ... ] }  (rare; tolerated)
     *
     * Identity key resolution (in order):
     * - manifest.events key if associative
     * - event.id
     * - event.identityHash
     *
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

        // If events is a list, derive identity from event fields.
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

        // Associative map keyed by id
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

    private function isManagedEvent(array $event): bool
    {
        $ownership = $event['ownership'] ?? null;
        if (!is_array($ownership)) {
            return false;
        }
        return (bool)($ownership['managed'] ?? false);
    }

    /**
     * Read the event-level stateHash.
     *
     * Contract:
     * - stateHash MUST exist for managed events during Phase 4
     * - unmanaged events may omit it (ignored for mutation)
     */
    private function readEventStateHash(array $event): string
    {
        $v = $event['stateHash'] ?? null;
        if (is_string($v) && trim($v) !== '') {
            return trim($v);
        }

        // Fail hard for managed entries (Diff relies on stateHash stability).
        if ($this->isManagedEvent($event)) {
            $id = (string)($event['id'] ?? $event['identityHash'] ?? '');
            throw new \RuntimeException('Managed manifest event missing stateHash: ' . $id);
        }

        return '';
    }
}
