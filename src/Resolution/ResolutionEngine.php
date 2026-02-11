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
                $bundleUid = $this->buildBundleUid($snapshotEvent, $segmentScope);

                $segmentOverrides = $this->collectOverridesForSegment($snapshotEvent, $segmentScope);
                $overrideSubevents = $this->collapseOverridesToResolvedSubevents($snapshotEvent, $segmentOverrides, $segmentScope);

                $baseSubevent = $this->buildBaseSubeventForSegment($snapshotEvent, $segmentScope);

                // Overrides first (top-down), base always last
                $subevents = array_merge($overrideSubevents, [$baseSubevent]);

                $bundles[] = new ResolvedBundle(
                    bundleUid: $bundleUid,
                    sourceEventUid: $snapshotEvent->sourceEventUid,
                    parentUid: $snapshotEvent->parentUid,
                    segmentScope: $segmentScope,
                    subevents: $subevents
                );
            }
        }

        // Coalesce adjacent bundles where possible
        $coalescedBundles = [];
        $count = count($bundles);
        $i = 0;
        while ($i < $count) {
            $current = $bundles[$i];
            // Try to merge with the next bundle if possible
            if (
                $i + 1 < $count
                && $current->getParentUid() === $bundles[$i + 1]->getParentUid()
                && $current->getSourceEventUid() === $bundles[$i + 1]->getSourceEventUid()
                && $current->getOverrideSignature() === $bundles[$i + 1]->getOverrideSignature()
                && $current->getSegmentScope()->getEnd() == $bundles[$i + 1]->getSegmentScope()->getStart()
            ) {
                // Merge current and next
                $mergedScope = new \CalendarScheduler\Resolution\Dto\ResolutionScope(
                    $current->getSegmentScope()->getStart(),
                    $bundles[$i + 1]->getSegmentScope()->getEnd()
                );
                // Reuse subevents from the first bundle
                $mergedBundleUid = $this->buildBundleUid(
                    // We need the original event, but we only have the ResolvedBundle.
                    // Since bundleUid, parentUid, etc. are always from the same event,
                    // we can pass a dummy SnapshotEvent with only the required fields.
                    // But instead, we rely on the fact that bundleUid is only a hash of parentUid and scope.
                    // So, to avoid breaking logic, let's assume buildBundleUid can be used with a dummy event:
                    (object)[
                        'parentUid' => $current->getParentUid(),
                        'sourceEventUid' => $current->getSourceEventUid(),
                        // These fields are not used in buildBundleUid for hashing
                    ],
                    $mergedScope
                );
                $merged = new \CalendarScheduler\Resolution\Dto\ResolvedBundle(
                    bundleUid: $mergedBundleUid,
                    sourceEventUid: $current->getSourceEventUid(),
                    parentUid: $current->getParentUid(),
                    segmentScope: $mergedScope,
                    subevents: $current->getSubevents()
                );
                $coalescedBundles[] = $merged;
                $i += 2; // skip next bundle, since merged
                continue;
            }
            // Not mergeable, keep as-is
            $coalescedBundles[] = $current;
            $i++;
        }
        return new ResolvedSchedule($coalescedBundles);
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

            // NOTE: Do not interpret start/end times here.
            // Symbolic time is carried in payload and resolved by FPP at execution.
            $rows[] = [
                'day' => $day,
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

            // NOTE: Do not compare derived hard times. Payload must carry any symbolic-time intent.
            $sameGeometry = (
                $row['enabled'] === $current['enabled'] &&
                $row['stopType'] === $current['stopType'] &&
                $row['payload'] == $current['payload']
            );

            $isNextDay = ($row['day'] == $current['endDayExclusive']);

            if ($isNextDay && $sameGeometry) {
                $current['endDayExclusive'] = $current['endDayExclusive']->modify('+1 day');
                continue;
            }

            try {
                $subevents[] = $this->buildOverrideSubevent($event, $current['day'], $current['endDayExclusive'], $segmentScope, $current);
            } catch (\RuntimeException $e) {
                // Skip overrides that do not intersect this segment.
            }
            $current = $row + ['endDayExclusive' => $row['day']->modify('+1 day')];
        }

        if ($current !== null) {
            try {
                $subevents[] = $this->buildOverrideSubevent($event, $current['day'], $current['endDayExclusive'], $segmentScope, $current);
            } catch (\RuntimeException $e) {
                // Skip overrides that do not intersect this segment.
            }
        }

        // Highest priority first, then stable tie-breakers
        usort($subevents, fn(ResolvedSubevent $a, ResolvedSubevent $b) => $this->compareSubevents($a, $b));

        return $subevents;
    }

    /**
     * Clip [start,end) to the segment scope.
     * Returns null if there is no intersection.
     */
    private function clipToSegment(
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        ResolutionScope $segmentScope
    ): ?array {
        $segStart = $segmentScope->getStart();
        $segEnd = $segmentScope->getEnd();

        $clippedStart = ($start < $segStart) ? $segStart : $start;
        $clippedEnd = ($end > $segEnd) ? $segEnd : $end;

        if ($clippedEnd <= $clippedStart) {
            return null;
        }

        return [$clippedStart, $clippedEnd];
    }

    /**
     * Deterministic priority:
     * - Overrides always outrank base.
     * - Narrower scopes outrank wider scopes within the same role.
     */
    private function computePriority(string $role, ResolutionScope $scope): int
    {
        $roleBase = ($role === ResolutionRole::OVERRIDE) ? 100000 : 0;

        // Narrower scope => higher boost. Work in whole days because Stage 3/4.1 uses date-level scopes.
        $seconds = $scope->getEnd()->getTimestamp() - $scope->getStart()->getTimestamp();
        $days = (int) max(1, (int) ceil($seconds / 86400));

        // 1-day => 9999, 2-day => 9998, ... floor at 0
        $narrowBoost = max(0, 10000 - $days);

        return $roleBase + $narrowBoost;
    }

    /**
     * Stable ordering for overrides within a bundle.
     */
    private function compareSubevents(ResolvedSubevent $a, ResolvedSubevent $b): int
    {
        $p = $b->getPriority() <=> $a->getPriority();
        if ($p !== 0) {
            return $p;
        }

        // Earlier scope first for stability.
        $s = $a->getScope()->getStart() <=> $b->getScope()->getStart();
        if ($s !== 0) {
            return $s;
        }

        $e = $a->getScope()->getEnd() <=> $b->getScope()->getEnd();
        if ($e !== 0) {
            return $e;
        }

        // Final stable tiebreaker: payload hash.
        return hash('sha256', json_encode($a->getPayload())) <=> hash('sha256', json_encode($b->getPayload()));
    }

    private function buildOverrideSubevent(
        SnapshotEvent $event,
        \DateTimeImmutable $dayStart,
        \DateTimeImmutable $dayEndExclusive,
        ResolutionScope $segmentScope,
        array $row
    ): ResolvedSubevent {
        $bundleUid = $this->buildBundleUid($event, $segmentScope);

        $clipped = $this->clipToSegment($dayStart, $dayEndExclusive, $segmentScope);
        if ($clipped === null) {
            // No intersection with segment; do not emit.
            // Caller expects only emitted subevents, so return an empty scope via exception is worse.
            // We handle by returning a zero-length scope would violate ResolutionScope.
            throw new \RuntimeException('Override subevent scope does not intersect segment scope.');
        }

        [$dayStart, $dayEndExclusive] = $clipped;

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
            priority: $this->computePriority(ResolutionRole::OVERRIDE, $scope),
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
        $bundleUid = $this->buildBundleUid($event, $segmentScope);

        // Preserve the true DTSTART/DTEND time geometry for the executable window.
        // Segment scopes are DATE-level (midnight boundaries), but the subevent start/end
        // must retain time-of-day to avoid collapsing distinct intents downstream.
        [$eventStart, $eventEnd] = $this->extractEventBounds($event);

        $clipped = $this->clipToSegment($eventStart, $eventEnd, $segmentScope);
        if ($clipped === null) {
            // Defensive fallback: use segment scope directly.
            $start = $segmentScope->getStart();
            $end = $segmentScope->getEnd();
        } else {
            [$start, $end] = $clipped;
        }

        // Option B:
        // Do NOT perform instance-level RRULE expansion.
        // However, each emitted subevent must carry a scope that is aligned to the
        // *occurrence day* so downstream timing extraction does not collapse to
        // midnight defaults.
        //
        // We keep bundle identity and segment trace based on the segmentScope (date-level),
        // but set the executable scope to the day that contains the subevent start.
        $tz = $start->getTimezone();
        $dayStart = (new \DateTimeImmutable($start->format('Y-m-d'), $tz))->setTime(0, 0, 0);
        $dayEndExclusive = $dayStart->modify('+1 day');
        $executableScope = new ResolutionScope($dayStart, $dayEndExclusive);

        return new ResolvedSubevent(
            bundleUid: $bundleUid,
            sourceEventUid: $event->sourceEventUid,
            parentUid: $event->parentUid,
            provider: $event->provider,
            start: $start,
            end: $end,
            allDay: $event->isAllDay,
            timezone: $event->timezone,
            role: ResolutionRole::BASE,
            scope: $executableScope,
            priority: $this->computePriority(ResolutionRole::BASE, $segmentScope),
            payload: $event->payload ?? [],
            sourceTrace: [
                'kind' => 'base',
                'segmentStart' => $segmentScope->getStart()->format(\DateTimeInterface::ATOM),
                'segmentEnd' => $segmentScope->getEnd()->format(\DateTimeInterface::ATOM),
            ]
        );
    }

    /**
     * Deterministic bundle identity.
     * One bundle per (parentUid + segment scope).
     */
    private function buildBundleUid(
        SnapshotEvent $event,
        ResolutionScope $segmentScope
    ): string {
        return hash(
            'sha256',
            implode('|', [
                $event->parentUid,
                $segmentScope->getStart()->format('Y-m-d'),
                $segmentScope->getEnd()->format('Y-m-d'),
            ])
        );
    }
}
