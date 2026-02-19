<?php
declare(strict_types=1);

/**
 * Calendar Scheduler — Source Component
 *
 * File: Resolution/Dto/ResolvedSchedule.php
 * Purpose: Defines the ResolvedSchedule component used by the Calendar Scheduler Resolution/Dto layer.
 */

namespace CalendarScheduler\Resolution\Dto;

use CalendarScheduler\Planner\Dto\PlannerIntent;

/**
 * Top-level output of Resolution.
 *
 * @psalm-type ResolvedBundles = list<ResolvedBundle>
 */
final class ResolvedSchedule
{
    /** @var ResolvedBundle[] */
    private array $bundles;

    /**
     * @param ResolvedBundle[] $bundles
     */
    public function __construct(array $bundles)
    {
        $this->bundles = $this->sortBundles($bundles);
    }

    /**
     * @return ResolvedBundle[]
     */
    public function getBundles(): array
    {
        return $this->bundles;
    }

    /**
     * Flatten resolved bundles into planner-ready intents.
     *
     * Ordering rules:
     * - Bundles are atomic
     * - Overrides first, base last (already enforced in bundle)
     * - Bundle order preserved
     *
     * @return PlannerIntent[]
     */
    public function toPlannerIntents(): array
    {
        $intents = [];

        foreach ($this->bundles as $bundle) {
            foreach ($bundle->getSubevents() as $subevent) {
                $intents[] = new PlannerIntent(
                    bundleUid: $subevent->getBundleUid(),
                    parentUid: $subevent->getParentUid(),
                    sourceEventUid: $subevent->getSourceEventUid(),
                    provider: $subevent->getProvider(),
                    start: $subevent->getStart(),
                    end: $subevent->getEnd(),
                    allDay: $subevent->isAllDay(),
                    timezone: $subevent->getTimezone(),
                    role: $subevent->getRole(),
                    scope: $subevent->getScope(),
                    priority: $subevent->getPriority(),
                    payload: $subevent->getPayload(),
                    sourceTrace: $subevent->getSourceTrace(),
                    weeklyDays: $subevent->getWeeklyDays()
                );
            }
        }

        return $intents;
    }

    /**
     * Ensure deterministic ordering of bundles for diffing / hashing.
     *
     * @param ResolvedBundle[] $bundles
     * @return ResolvedBundle[]
     */
    private function sortBundles(array $bundles): array
    {
        usort(
            $bundles,
            function (ResolvedBundle $a, ResolvedBundle $b): int {
                $aStart = $a->getSegmentScope()->getStart()->getTimestamp();
                $bStart = $b->getSegmentScope()->getStart()->getTimestamp();
                if ($aStart !== $bStart) {
                    return $aStart <=> $bStart;
                }

                $aEnd = $a->getSegmentScope()->getEnd()->getTimestamp();
                $bEnd = $b->getSegmentScope()->getEnd()->getTimestamp();
                if ($aEnd !== $bEnd) {
                    return $aEnd <=> $bEnd;
                }

                if ($a->getParentUid() !== $b->getParentUid()) {
                    return strcmp($a->getParentUid(), $b->getParentUid());
                }

                return strcmp($a->getSourceEventUid(), $b->getSourceEventUid());
            }
        );

        return $bundles;
    }
}
