<?php
declare(strict_types=1);

namespace CalendarScheduler\Resolution;

use CalendarScheduler\Adapter\Calendar\CalendarSnapshot;
use CalendarScheduler\Resolution\Dto\ResolvedSchedule;

/**
 * Resolution converts a provider-agnostic snapshot into bundles/subevents that
 * represent minimal FPP-executable geometry (base + overrides).
 *
 * Stage 1: contracts only. No behavioral logic yet.
 */
interface ResolutionEngineInterface
{
    public function resolve(CalendarSnapshot $snapshot): ResolvedSchedule;
}
