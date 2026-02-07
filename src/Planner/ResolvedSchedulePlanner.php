<?php
declare(strict_types=1);

namespace CalendarScheduler\Planner;

use CalendarScheduler\Resolution\Dto\ResolvedSchedule;
use CalendarScheduler\Resolution\Dto\ResolvedBundle;
use CalendarScheduler\Resolution\Dto\ResolvedSubevent;
use CalendarScheduler\Resolution\Dto\ResolutionRole;
use CalendarScheduler\Planner\PlannerResult;
use CalendarScheduler\Planner\PlannedEntry;
use CalendarScheduler\Planner\OrderKey;
use CalendarScheduler\Planner\OrderingKey;

/**
 * ResolvedSchedulePlanner
 *
 * Converts a ResolvedSchedule (bundles + subevents)
 * into executable PlannedEntry objects.
 *
 * This is the bridge between Resolution and Planner.
 *
 * Responsibilities:
 * - Preserve bundle atomicity
 * - Preserve override ordering (top-down)
 * - Produce deterministic OrderingKeys
 * - Carry symbolic time through untouched
 *
 * Non-responsibilities:
 * - No calendar logic
 * - No diffing
 * - No persistence
 * - No execution-time resolution
 *
 * NOTE: This planner emits PlannerResult directly.
 */
final class ResolvedSchedulePlanner
{
    public function plan(ResolvedSchedule $schedule): PlannerResult
    {
        $entries = [];

        $bundleIndex = 0;

        foreach ($schedule->getBundles() as $bundle) {
            $subIndex = 0;

            foreach ($bundle->getSubevents() as $subevent) {
                $orderingKey = $this->buildOrderingKey(
                    $bundleIndex,
                    $subIndex,
                    $subevent
                );

                $entries[] = new PlannedEntry(
                    eventId: $bundle->getParentUid(),
                    subEventId: $this->buildSubEventId($bundle, $subevent),
                    identityHash: $this->buildIdentityHash($bundle, $subevent),
                    target: $subevent->getPayload(),
                    timing: $this->buildTiming($subevent),
                    orderingKey: $orderingKey
                );

                $subIndex++;
            }

            $bundleIndex++;
        }

        // Deterministic total ordering (defensive)
        usort(
            $entries,
            static function (PlannedEntry $a, PlannedEntry $b): int {
                $cmp = OrderingKey::compare(
                    $a->orderingKey(),
                    $b->orderingKey()
                );

                return $cmp !== 0
                    ? $cmp
                    : ($a->stableKey() <=> $b->stableKey());
            }
        );

        return new PlannerResult($entries);
    }

    private function buildOrderingKey(
        int $bundleIndex,
        int $subIndex,
        ResolvedSubevent $subevent
    ): OrderingKey {
        // Calendar-managed entries always sort first
        $managedPriority = 0;

        // IMPORTANT:
        // Use scope start for ordering, not display start.
        $startEpochSeconds = $subevent
            ->getScope()
            ->getStart()
            ->getTimestamp();

        return new OrderingKey(
            $managedPriority,
            $bundleIndex,
            $subIndex,
            $startEpochSeconds,
            $subevent->getSourceEventUid()
        );
    }

    private function buildSubEventId(
        ResolvedBundle $bundle,
        ResolvedSubevent $subevent
    ): string {
        // Human-readable + stable
        return implode(':', [
            $bundle->getParentUid(),
            $subevent->getRole(),
            $subevent->getScope()->getStart()->format('Ymd'),
            $subevent->getScope()->getEnd()->format('Ymd'),
        ]);
    }

    private function buildIdentityHash(
        ResolvedBundle $bundle,
        ResolvedSubevent $subevent
    ): string {
        // Identity must change if ANY execution-relevant detail changes
        return hash('sha256', json_encode([
            'bundleUid' => $bundle->getParentUid(),
            'role'      => $subevent->getRole(),
            'scope'     => [
                'start' => $subevent->getScope()->getStart()->format(DATE_ATOM),
                'end'   => $subevent->getScope()->getEnd()->format(DATE_ATOM),
            ],
            'payload'   => $subevent->getPayload(),
            'priority'  => $subevent->getPriority(),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Build timing block for Planner.
     *
     * NOTE:
     * - Symbolic times are NOT resolved here
     * - start/end are display-safe only
     * - FPP resolves symbolic times at execution
     */
    private function buildTiming(ResolvedSubevent $subevent): array
    {
        return [
            'start' => $subevent->getStart()->format(DATE_ATOM),
            'end'   => $subevent->getEnd()->format(DATE_ATOM),
            'allDay' => $subevent->isAllDay(),
            'timezone' => $subevent->getTimezone(),
            'scope' => [
                'segmentStart' => $subevent->getScope()->getStart()->format(DATE_ATOM),
                'segmentEnd'   => $subevent->getScope()->getEnd()->format(DATE_ATOM),
            ],
        ];
    }
}