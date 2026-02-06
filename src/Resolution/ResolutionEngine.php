<?php
declare(strict_types=1);

namespace CalendarScheduler\Resolution;

use CalendarScheduler\Adapter\Calendar\OverrideIntent;
use CalendarScheduler\Adapter\Calendar\SnapshotEvent;
use CalendarScheduler\Resolution\Dto\ResolvedBundle;
use CalendarScheduler\Resolution\Dto\ResolvedSchedule;
use CalendarScheduler\Resolution\Dto\ResolvedSubevent;
use CalendarScheduler\Resolution\Dto\ResolutionRole;
use CalendarScheduler\Resolution\Dto\ResolutionScope;

/**
 * Stage 2 implementation:
 * - Split into contiguous segments when cancellations exist
 * - Emit override subevents that intersect each segment
 * - Emit base subevent last in each bundle
 */
final class ResolutionEngine implements ResolutionEngineInterface
{
    /**
     * @param SnapshotEvent[] $snapshotEvents
     */
    public function resolve(array $snapshotEvents): ResolvedSchedule
    {
        $bundles = [];

        foreach ($snapshotEvents as $snapshotEvent) {
            if (!$snapshotEvent instanceof SnapshotEvent) {
                continue;
            }

            $eventStart = $this->parseSnapshotDate($snapshotEvent->start, $snapshotEvent->timezone);
            $eventEnd   = $this->parseSnapshotDate($snapshotEvent->end, $snapshotEvent->timezone);

            // duration used for cancellation windows (assumes instance duration == base duration)
            $durationSeconds = max(1, $eventEnd->getTimestamp() - $eventStart->getTimestamp());

            // Build exclusion windows from cancelledDates
            $exclusions = [];
            foreach ($snapshotEvent->cancelledDates as $cancelStartRaw) {
                if (!is_string($cancelStartRaw) || $cancelStartRaw === '') {
                    continue;
                }
                $cancelStart = $this->parseIsoDateTime($cancelStartRaw, $snapshotEvent->timezone);
                $cancelEnd = $cancelStart->modify('+' . $durationSeconds . ' seconds');

                // Only exclude if it intersects the overall event window
                if ($cancelEnd <= $eventStart || $cancelStart >= $eventEnd) {
                    continue;
                }

                // Clamp
                if ($cancelStart < $eventStart) {
                    $cancelStart = $eventStart;
                }
                if ($cancelEnd > $eventEnd) {
                    $cancelEnd = $eventEnd;
                }

                $exclusions[] = [$cancelStart, $cancelEnd];
            }

            $segments = $this->subtractIntervals($eventStart, $eventEnd, $exclusions);

            foreach ($segments as [$segStart, $segEnd]) {
                $segmentScope = new ResolutionScope($segStart, $segEnd);
                $bundleUid = $this->makeBundleUid($snapshotEvent->parentUid, $segStart, $segEnd);

                $subevents = [];

                // Stage 2: naive override emission (no collapsing yet)
                foreach ($snapshotEvent->overrides as $override) {
                    if (!$override instanceof OverrideIntent) {
                        continue;
                    }
                    if ($override->enabled === false) {
                        continue;
                    }

                    $ovStart = $this->parseSnapshotDate($override->start, $snapshotEvent->timezone);
                    $ovEnd   = $this->parseSnapshotDate($override->end, $snapshotEvent->timezone);

                    // Only include overrides that intersect this segment
                    if ($ovEnd <= $segStart || $ovStart >= $segEnd) {
                        continue;
                    }

                    $ovScopeStart = ($ovStart < $segStart) ? $segStart : $ovStart;
                    $ovScopeEnd   = ($ovEnd > $segEnd) ? $segEnd : $ovEnd;
                    if ($ovScopeEnd <= $ovScopeStart) {
                        continue;
                    }

                    $overrideScope = new ResolutionScope($ovScopeStart, $ovScopeEnd);

                    $subevents[] = new ResolvedSubevent(
                        bundleUid: $bundleUid,
                        sourceEventUid: $snapshotEvent->sourceEventUid,
                        parentUid: $snapshotEvent->parentUid,
                        provider: $snapshotEvent->provider,
                        start: $ovStart,
                        end: $ovEnd,
                        allDay: $snapshotEvent->isAllDay,
                        timezone: $snapshotEvent->timezone,
                        role: ResolutionRole::OVERRIDE,
                        scope: $overrideScope,
                        priority: 100,
                        payload: $override->payload,
                        sourceTrace: [
                            'type' => 'override',
                            'originalStartTime' => $override->originalStartTime ?? null,
                        ]
                    );
                }

                // Base subevent (always last)
                $subevents[] = new ResolvedSubevent(
                    bundleUid: $bundleUid,
                    sourceEventUid: $snapshotEvent->sourceEventUid,
                    parentUid: $snapshotEvent->parentUid,
                    provider: $snapshotEvent->provider,
                    start: $eventStart,
                    end: $eventEnd,
                    allDay: $snapshotEvent->isAllDay,
                    timezone: $snapshotEvent->timezone,
                    role: ResolutionRole::BASE,
                    scope: $segmentScope,
                    priority: 0,
                    payload: $snapshotEvent->payload,
                    sourceTrace: [
                        'type' => 'base',
                        'rrule' => $snapshotEvent->rrule,
                    ]
                );

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

    private function makeBundleUid(string $parentUid, \DateTimeImmutable $start, \DateTimeImmutable $end): string
    {
        return substr(sha1($parentUid . '|' . $start->format('c') . '|' . $end->format('c')), 0, 16);
    }

    /**
     * @param array{dateTime?:string,date?:string} $value
     */
    private function parseSnapshotDate(array $value, ?string $tz): \DateTimeImmutable
    {
        $timezone = $tz ? new \DateTimeZone($tz) : new \DateTimeZone('UTC');

        if (isset($value['dateTime']) && is_string($value['dateTime']) && $value['dateTime'] !== '') {
            return new \DateTimeImmutable($value['dateTime']);
        }

        if (isset($value['date']) && is_string($value['date']) && $value['date'] !== '') {
            // date-only: treat as midnight in the event timezone
            return (new \DateTimeImmutable($value['date'] . ' 00:00:00', $timezone));
        }

        throw new \InvalidArgumentException('Invalid snapshot date payload.');
    }

    private function parseIsoDateTime(string $value, ?string $tz): \DateTimeImmutable
    {
        // cancelled originalStartTime from Google is typically ISO8601 with offset
        // If it’s missing timezone info, apply calendar timezone.
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception $e) {
            $timezone = $tz ? new \DateTimeZone($tz) : new \DateTimeZone('UTC');
            return new \DateTimeImmutable($value, $timezone);
        }
    }

    /**
     * Subtract exclusion intervals from a main window.
     *
     * @param \DateTimeImmutable $start
     * @param \DateTimeImmutable $end
     * @param array<int, array{0:\DateTimeImmutable,1:\DateTimeImmutable}> $exclusions
     * @return array<int, array{0:\DateTimeImmutable,1:\DateTimeImmutable}>
     */
    private function subtractIntervals(
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        array $exclusions
    ): array {
        if (empty($exclusions)) {
            return [[$start, $end]];
        }

        // Sort + merge exclusions
        usort($exclusions, static function ($a, $b): int {
            return $a[0] <=> $b[0];
        });

        $merged = [];
        foreach ($exclusions as [$s, $e]) {
            if (empty($merged)) {
                $merged[] = [$s, $e];
                continue;
            }
            [$ls, $le] = $merged[count($merged) - 1];
            if ($s <= $le) {
                // overlap/adjacent
                $merged[count($merged) - 1] = [$ls, ($e > $le) ? $e : $le];
            } else {
                $merged[] = [$s, $e];
            }
        }

        $segments = [];
        $cursor = $start;
        foreach ($merged as [$xs, $xe]) {
            if ($xe <= $cursor) {
                continue;
            }
            if ($xs > $cursor) {
                $segments[] = [$cursor, $xs];
            }
            $cursor = ($xe > $cursor) ? $xe : $cursor;
        }

        if ($cursor < $end) {
            $segments[] = [$cursor, $end];
        }

        // Drop zero/negative segments defensively
        $segments = array_values(array_filter($segments, static function ($seg): bool {
            return $seg[1] > $seg[0];
        }));

        return $segments;
    }
}
