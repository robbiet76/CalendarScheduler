<?php
declare(strict_types=1);

namespace CalendarScheduler\Resolution;

use CalendarScheduler\Adapter\Calendar\CalendarSnapshot;
use CalendarScheduler\Adapter\Calendar\SnapshotEvent;
use CalendarScheduler\Adapter\Calendar\OverrideIntent;
use CalendarScheduler\Resolution\Dto\ResolvedBundle;
use CalendarScheduler\Resolution\Dto\ResolvedSchedule;
use CalendarScheduler\Resolution\Dto\ResolvedSubevent;
use CalendarScheduler\Resolution\Dto\ResolutionRole;
use CalendarScheduler\Resolution\Dto\ResolutionScope;

/**
 * Stage 3 implementation:
 * - Split base recurrence into date segments when cancellations exist
 * - Attach override intents (minimal collapsing: merge contiguous same-payload overrides)
 * - Emit bundles: overrides first, base last
 *
 * Notes:
 * - Date-level cancellation is enforced (time component ignored for segmentation)
 * - This stage does NOT expand RRULE to occurrences; it operates on ranges/scopes only
 */
final class ResolutionEngine implements ResolutionEngineInterface
{
    public function resolve(CalendarSnapshot $snapshot): ResolvedSchedule
    {
        $bundles = [];

        $snapshotEvents = $snapshot->getSnapshotEvents();
        foreach ($snapshotEvents as $snapshotEvent) {
            $segments = $this->buildDateSegments($snapshotEvent);
            foreach ($segments as $segmentScope) {
                $segmentOverrides = $this->collectOverridesForSegment($snapshotEvent, $segmentScope);
                $overrideSubevents = $this->collapseOverridesToResolvedSubevents($snapshotEvent, $segmentOverrides, $segmentScope);

                $baseSubevent = $this->buildBaseSubeventForSegment($snapshotEvent, $segmentScope);

                // Overrides first (top-down), base always last
                $subevents = array_merge($overrideSubevents, [$baseSubevent]);

                $bundles[] = new ResolvedBundle(
                    sourceEventUid: $snapshotEvent->sourceEventUid,
                    parentUid: $snapshotEvent->parentUid,
                    segmentScope: $segmentScope,
                    subevents: $subevents
                );
            }
        }

        return new ResolvedSchedule($bundles);
    }

    /**
     * Build date-only segments from event start/end, subtracting cancelled dates.
     * This operates at DATE granularity (midnight boundaries).
     *
     * @return ResolutionScope[]
     */
    private function buildDateSegments(SnapshotEvent $event): array
    {
        [$eventStart, $eventEnd] = $this->extractEventBounds($event);

        // Normalize to date boundaries for segmentation
        $tz = $eventStart->getTimezone();
        $startDate = (new \DateTimeImmutable($eventStart->format('Y-m-d'), $tz))->setTime(0, 0, 0);
        $endDate = (new \DateTimeImmutable($eventEnd->format('Y-m-d'), $tz))->setTime(0, 0, 0);

        if ($endDate <= $startDate) {
            // Defensive: treat as single minimal scope (should not normally happen)
            $endDate = $startDate->modify('+1 day');
        }

        // Cancelled dates (date-level)
        $cancelled = [];
        foreach ($event->cancelledDates as $originalStartTime) {
            $dt = null;

            if (is_string($originalStartTime)) {
                $dt = $this->parseCalendarDateTime($originalStartTime, $tz);
            } elseif (is_array($originalStartTime)) {
                if (isset($originalStartTime['dateTime'])) {
                    $dt = $this->parseCalendarDateTime($originalStartTime['dateTime'], $tz);
                } elseif (isset($originalStartTime['date'])) {
                    $dt = (new \DateTimeImmutable($originalStartTime['date'], $tz))->setTime(0, 0, 0);
                }
            }

            if ($dt !== null) {
                $cancelled[$dt->format('Y-m-d')] = true;
            }
        }

        // If no cancellations, one segment = full event range
        if (empty($cancelled)) {
            return [new ResolutionScope($startDate, $endDate)];
        }

        // Walk dates from startDate to endDate (end exclusive) and emit contiguous "kept" ranges
        $segments = [];
        $cursor = $startDate;
        $currentStart = null;

        while ($cursor < $endDate) {
            $key = $cursor->format('Y-m-d');
            $isCancelled = isset($cancelled[$key]);

            if ($isCancelled) {
                if ($currentStart !== null) {
                    // close current segment at cursor
                    if ($cursor > $currentStart) {
                        $segments[] = new ResolutionScope($currentStart, $cursor);
                    }
                    $currentStart = null;
                }
            } else {
                if ($currentStart === null) {
                    $currentStart = $cursor;
                }
            }

            $cursor = $cursor->modify('+1 day');
        }

        if ($currentStart !== null && $endDate > $currentStart) {
            $segments[] = new ResolutionScope($currentStart, $endDate);
        }

        // Edge case: if everything is cancelled, return empty => schedule contributes nothing
        return $segments;
    }

    /**
     * @return array{0:\DateTimeImmutable,1:\DateTimeImmutable}
     */
    private function extractEventBounds(SnapshotEvent $event): array
    {
        $tz = $event->timezone ? new \DateTimeZone($event->timezone) : new \DateTimeZone('UTC');

        $start = $this->extractStartEndDateTime($event->start, $tz, true);
        $end   = $this->extractStartEndDateTime($event->end,   $tz, false);

        return [$start, $end];
    }

    /**
     * @param array $arr expects ['date'] OR ['dateTime']
     */
    private function extractStartEndDateTime(array $arr, \DateTimeZone $tz, bool $isStart): \DateTimeImmutable
    {
        if (isset($arr['dateTime'])) {
            return $this->parseCalendarDateTime($arr['dateTime'], $tz);
        }

        if (isset($arr['date'])) {
            // All-day dates: end is typically exclusive; we still use midnight boundaries here
            $d = new \DateTimeImmutable($arr['date'], $tz);
            return $d->setTime(0, 0, 0);
        }

        // Fallback: now, but this should never happen for valid translated rows
        $now = new \DateTimeImmutable('now', $tz);
        return $isStart ? $now : $now->modify('+1 day');
    }

    private function parseCalendarDateTime(string $value, \DateTimeZone $fallbackTz): \DateTimeImmutable
    {
        // Handles RFC3339 timestamps and date strings
        try {
            $dt = new \DateTimeImmutable($value);
            return $dt;
        } catch (\Exception $e) {
            // If it's not parseable directly, treat as date in fallback tz
            return (new \DateTimeImmutable($value, $fallbackTz));
        }
    }

    /**
     * Collect overrides whose originalStartTime date falls within this segment.
     *
     * @return OverrideIntent[]
     */
    private function collectOverridesForSegment(SnapshotEvent $event, ResolutionScope $segment): array
    {
        if (empty($event->overrides)) {
            return [];
        }

        $result = [];
        $segStart = $segment->getStart();
        $segEnd = $segment->getEnd(); // exclusive

        $tz = $event->timezone ? new \DateTimeZone($event->timezone) : $segStart->getTimezone();

        foreach ($event->overrides as $override) {
            // Use originalStartTime as the anchor for date membership (calendar exception semantics)
            $anchor = null;
            if (isset($override->originalStartTime['dateTime'])) {
                $anchor = $this->parseCalendarDateTime($override->originalStartTime['dateTime'], $tz);
            } elseif (isset($override->originalStartTime['date'])) {
                $anchor = (new \DateTimeImmutable($override->originalStartTime['date'], $tz))->setTime(0, 0, 0);
            }

            if ($anchor === null) {
                continue;
            }

            // Date-level membership: compare by day boundary
            $anchorDay = (new \DateTimeImmutable($anchor->format('Y-m-d'), $tz))->setTime(0, 0, 0);
            if ($anchorDay >= $segStart && $anchorDay < $segEnd) {
                $result[] = $override;
            }
        }

        return $result;
    }

    /**
     * Collapse overrides into the minimal number of ResolvedSubevents.
     * Stage 3 rules:
     * - Merge contiguous dates if payload + time geometry match
     * - Otherwise keep as single-day scoped overrides
     *
     * @param OverrideIntent[] $overrides
     * @return ResolvedSubevent[]
     */
    private function collapseOverridesToResolvedSubevents(
        SnapshotEvent $event,
        array $overrides,
        ResolutionScope $segmentScope
    ): array {
        if (empty($overrides)) {
            return [];
        }

        $tz = $event->timezone ? new \DateTimeZone($event->timezone) : $segmentScope->getStart()->getTimezone();

        // Build normalized override records keyed by day
        $rows = [];
        foreach ($overrides as $override) {
            $anchor = null;
            if (isset($override->originalStartTime['dateTime'])) {
                $anchor = $this->parseCalendarDateTime($override->originalStartTime['dateTime'], $tz);
            } elseif (isset($override->originalStartTime['date'])) {
                $anchor = (new \DateTimeImmutable($override->originalStartTime['date'], $tz))->setTime(0, 0, 0);
            }
            if ($anchor === null) {
                continue;
            }

            $day = (new \DateTimeImmutable($anchor->format('Y-m-d'), $tz))->setTime(0, 0, 0);

            // Geometry: time-of-day start/end for the override row
            $ovStart = $this->extractStartEndDateTime($override->start, $tz, true);
            $ovEnd = $this->extractStartEndDateTime($override->end, $tz, false);

            $rows[] = [
                'day' => $day,
                'startTime' => $ovStart->format('H:i:s'),
                'endTime' => $ovEnd->format('H:i:s'),
                'payload' => $override->payload,
                'enabled' => $override->enabled ?? true,
                'stopType' => $override->stopType ?? null,
                'source' => $override,
            ];
        }

        usort($rows, fn($a, $b) => $a['day'] <=> $b['day']);

        $subevents = [];

        $current = null;
        foreach ($rows as $row) {
            if ($current === null) {
                $current = $row + ['endDayExclusive' => $row['day']->modify('+1 day')];
                continue;
            }

            $isNextDay = ($row['day'] == $current['endDayExclusive']);
            $sameGeometry = (
                $row['startTime'] === $current['startTime'] &&
                $row['endTime'] === $current['endTime'] &&
                $row['enabled'] === $current['enabled'] &&
                $row['stopType'] === $current['stopType'] &&
                $row['payload'] == $current['payload']
            );

            if ($isNextDay && $sameGeometry) {
                $current['endDayExclusive'] = $current['endDayExclusive']->modify('+1 day');
                continue;
            }

            $subevents[] = $this->buildOverrideSubevent($event, $current['day'], $current['endDayExclusive'], $segmentScope, $current);
            $current = $row + ['endDayExclusive' => $row['day']->modify('+1 day')];
        }

        if ($current !== null) {
            $subevents[] = $this->buildOverrideSubevent($event, $current['day'], $current['endDayExclusive'], $segmentScope, $current);
        }

        // Highest priority first (still stable)
        usort($subevents, fn(ResolvedSubevent $a, ResolvedSubevent $b) => $b->getPriority() <=> $a->getPriority());

        return $subevents;
    }

    private function buildOverrideSubevent(
        SnapshotEvent $event,
        \DateTimeImmutable $dayStart,
        \DateTimeImmutable $dayEndExclusive,
        ResolutionScope $segmentScope,
        array $row
    ): ResolvedSubevent {
        $bundleUid = $event->parentUid;

        // Stage 3 scope for override is day-range (date-level)
        $scope = new ResolutionScope($dayStart, $dayEndExclusive);

        // Start/end for executable entry: keep as dayStart/dayEndExclusive for now
        // (Later stages may preserve exact times and/or symbolic time resolution.)
        return new ResolvedSubevent(
            bundleUid: $bundleUid,
            sourceEventUid: $event->sourceEventUid,
            parentUid: $event->parentUid,
            provider: $event->provider,
            start: $dayStart,
            end: $dayEndExclusive,
            allDay: $event->isAllDay,
            timezone: $event->timezone,
            role: ResolutionRole::OVERRIDE,
            scope: $scope,
            priority: 100,
            payload: $row['payload'] ?? [],
            sourceTrace: [
                'kind' => 'override',
                'segmentStart' => $segmentScope->getStart()->format(\DateTimeInterface::ATOM),
                'segmentEnd' => $segmentScope->getEnd()->format(\DateTimeInterface::ATOM),
            ]
        );
    }

    private function buildBaseSubeventForSegment(SnapshotEvent $event, ResolutionScope $segmentScope): ResolvedSubevent
    {
        $bundleUid = $event->parentUid;

        return new ResolvedSubevent(
            bundleUid: $bundleUid,
            sourceEventUid: $event->sourceEventUid,
            parentUid: $event->parentUid,
            provider: $event->provider,
            start: $segmentScope->getStart(),
            end: $segmentScope->getEnd(),
            allDay: $event->isAllDay,
            timezone: $event->timezone,
            role: ResolutionRole::BASE,
            scope: $segmentScope,
            priority: 0,
            payload: $event->payload ?? [],
            sourceTrace: [
                'kind' => 'base',
                'segmentStart' => $segmentScope->getStart()->format(\DateTimeInterface::ATOM),
                'segmentEnd' => $segmentScope->getEnd()->format(\DateTimeInterface::ATOM),
            ]
        );
    }
}
