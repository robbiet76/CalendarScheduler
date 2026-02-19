<?php

declare(strict_types=1);

/**
 * Calendar Scheduler — Source Component
 *
 * File: Planner/Planner.php
 * Purpose: Defines the Planner component used by the Calendar Scheduler Planner layer.
 */

namespace CalendarScheduler\Planner;

use CalendarScheduler\Core\ManifestStore;

/**
 * Planner Core (Phase 2.2)
 *
 * Consumes a valid Manifest and produces a deterministic ordered plan.
 *
 * No I/O. No diffing. Trusts ManifestStore invariants.
 */
final class Planner
{
    private ManifestStore $manifestStore;

    public function __construct(ManifestStore $manifestStore)
    {
        $this->manifestStore = $manifestStore;
    }

    public function plan(): PlannerResult
    {
        $manifest = $this->manifestStore->load();

        $events = $this->extractEvents($manifest);

        $entries = [];
        $eventIndex = 0;

        foreach ($events as $event) {
            $eventId = (string) $this->readField($event, ['id', 'eventId'], 'id');
            $isManaged = (bool) $this->readField($event, ['managed', 'isManaged'], 'managed', true);
            $eventOrder = (int) $this->readField($event, ['order', 'eventOrder'], 'order', $eventIndex);

            $subEvents = $this->extractSubEvents($event);

            $subIndex = 0;
            foreach ($subEvents as $subEvent) {
                $subEventId = (string) $this->readField($subEvent, ['id', 'subEventId'], 'id');
                $subOrder = (int) $this->readField($subEvent, ['order', 'subEventOrder'], 'order', $subIndex);

                $identityHash = (string) $this->readField($subEvent, ['identityHash', 'identity'], 'identityHash');

                $target = (array) $this->readField($subEvent, ['target'], 'target');
                $timing = (array) $this->readField($subEvent, ['timing', 'time', 'timings'], 'timing');

                $startEpoch = $this->extractStartEpochSeconds($timing);

                // Managed entries sort before unmanaged by default.
                $managedPriority = $isManaged ? 0 : 1;

                $orderingKey = new OrderingKey(
                    $managedPriority,
                    $eventOrder,
                    $subOrder,
                    $startEpoch,
                    $identityHash
                );

                $entries[] = new PlannedEntry(
                    $eventId,
                    $subEventId,
                    $identityHash,
                    $target,
                    $timing,
                    $orderingKey
                );

                $subIndex++;
            }

            $eventIndex++;
        }

        usort($entries, static function (PlannedEntry $a, PlannedEntry $b): int {
            $cmp = OrderingKey::compare($a->orderingKey(), $b->orderingKey());
            if ($cmp !== 0) {
                return $cmp;
            }
            // Deterministic total-order tie-breaker.
            return $a->stableKey() <=> $b->stableKey();
        });

        return new PlannerResult($entries);
    }

    /** @return array<int,mixed> */
    private function extractEvents(mixed $manifest): array
    {
        if (is_array($manifest)) {
            $events = $manifest['events'] ?? null;
            if (is_array($events)) {
                return array_values($events);
            }
        }

        if (is_object($manifest)) {
            if (method_exists($manifest, 'events')) {
                $events = $manifest->events();
                if (is_array($events)) {
                    return array_values($events);
                }
            }
            if (method_exists($manifest, 'getEvents')) {
                $events = $manifest->getEvents();
                if (is_array($events)) {
                    return array_values($events);
                }
            }
        }

        throw new \InvalidArgumentException('Manifest does not expose an events collection');
    }

    /** @return array<int,mixed> */
    private function extractSubEvents(mixed $event): array
    {
        if (is_array($event)) {
            $subs = $event['subEvents'] ?? $event['subevents'] ?? $event['sub_events'] ?? null;
            if (is_array($subs)) {
                return array_values($subs);
            }
        }

        if (is_object($event)) {
            if (method_exists($event, 'subEvents')) {
                $subs = $event->subEvents();
                if (is_array($subs)) {
                    return array_values($subs);
                }
            }
            if (method_exists($event, 'getSubEvents')) {
                $subs = $event->getSubEvents();
                if (is_array($subs)) {
                    return array_values($subs);
                }
            }
        }

        // Empty is valid: event can exist but produce no scheduler entries.
        return [];
    }

    /**
     * @param array<int,string> $keys
     */
    private function readField(mixed $obj, array $keys, string $fallbackGetterBase, mixed $default = null): mixed
    {
        if (is_array($obj)) {
            foreach ($keys as $k) {
                if (array_key_exists($k, $obj)) {
                    return $obj[$k];
                }
            }
            return $default;
        }

        if (is_object($obj)) {
            foreach ($keys as $k) {
                if (method_exists($obj, $k)) {
                    return $obj->{$k}();
                }
                $uc = ucfirst($k);
                foreach (['get' . $uc, 'is' . $uc, $k] as $m) {
                    if (method_exists($obj, $m)) {
                        return $obj->{$m}();
                    }
                }
            }

            $uc = ucfirst($fallbackGetterBase);
            foreach (['get' . $uc, 'is' . $uc, $fallbackGetterBase] as $m) {
                if (method_exists($obj, $m)) {
                    return $obj->{$m}();
                }
            }
        }

        return $default;
    }

    /**
     * @param array<string,mixed> $timing
     */
    private function extractStartEpochSeconds(array $timing): int
    {
        $start = $timing['start'] ?? $timing['startsAt'] ?? $timing['startTime'] ?? null;

        if (is_int($start)) {
            return $start;
        }
        if (is_float($start)) {
            return (int) round($start);
        }
        if (is_string($start) && trim($start) !== '') {
            $ts = strtotime($start);
            if ($ts === false) {
                throw new \InvalidArgumentException('Unable to parse timing.start string: ' . $start);
            }
            return (int) $ts;
        }

        throw new \InvalidArgumentException('timing.start is missing or invalid');
    }
}