<?php
declare(strict_types=1);

namespace CalendarScheduler\Resolution;

use CalendarScheduler\Adapter\Calendar\CalendarSnapshot;
use CalendarScheduler\Resolution\Dto\ResolvedSchedule;

/**
 * Stage 1 stub implementation.
 * Later stages will implement segmentation + overrides + bundle ordering.
 */
final class ResolutionEngine implements ResolutionEngineInterface
{
    public function resolve(CalendarSnapshot $snapshot): ResolvedSchedule
    {
        // Stage 1: no-op output; we are defining contracts first.
        return new ResolvedSchedule([]);
    }
}
