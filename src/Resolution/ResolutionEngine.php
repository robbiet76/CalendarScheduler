<?php
declare(strict_types=1);

namespace CalendarScheduler\Resolution;

use CalendarScheduler\Adapter\Calendar\CalendarSnapshot;
use CalendarScheduler\Resolution\Dto\ResolvedBundle;
use CalendarScheduler\Resolution\Dto\ResolvedSchedule;
use CalendarScheduler\Resolution\Dto\ResolutionScope;

/**
 * Stage 1 stub implementation.
 * Later stages will implement segmentation + overrides + bundle ordering.
 */
final class ResolutionEngine implements ResolutionEngineInterface
{
    public function resolve(CalendarSnapshot $snapshot): ResolvedSchedule
    {
        $snapshotEvents = $snapshot->getSnapshotEvents();

        $bundles = [];

        foreach ($snapshotEvents as $snapshotEvent) {
            // Stage 1 resolution strategy:
            // - One bundle per snapshot event
            // - No segmentation yet
            // - No override collapsing yet
            // - Base subevent only

            $baseSubevent = $snapshotEvent->toResolvedBaseSubevent();

            $bundles[] = new ResolvedBundle(
                sourceEventUid: $snapshotEvent->getSourceEventUid(),
                parentUid: $snapshotEvent->getParentUid(),
                segmentScope: new ResolutionScope(
                    $snapshotEvent->getStart(),
                    $snapshotEvent->getEnd()
                ),
                subevents: [$baseSubevent]
            );
        }

        // TODO (Stage 2+):
        // - Split into segments when cancellations exist
        // - Introduce override subevents
        // - Minimize bundles for FPP representation
        // - Apply bundle ordering rules

        return new ResolvedSchedule($bundles);
    }
}