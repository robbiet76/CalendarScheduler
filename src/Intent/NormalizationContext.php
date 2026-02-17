<?php
declare(strict_types=1);

/**
 * Calendar Scheduler — Source Component
 *
 * File: Intent/NormalizationContext.php
 * Purpose: Defines the NormalizationContext component used by the Calendar Scheduler Intent layer.
 */

namespace CalendarScheduler\Intent;

use DateTimeZone;
use CalendarScheduler\Platform\FPPSemantics;
use CalendarScheduler\Platform\HolidayResolver;

/**
 * NormalizationContext
 *
 * Explicit environmental inputs required to normalize intent.
 */
final class NormalizationContext
{
    public DateTimeZone $timezone;
    public FPPSemantics $fpp;
    public HolidayResolver $holidayResolver;

    /** @var array */
    public array $extras;

    public function __construct(
        DateTimeZone $timezone,
        FPPSemantics $fpp,
        HolidayResolver $holidayResolver,
        array $extras = []
    ) {
        $this->timezone = $timezone;
        $this->fpp      = $fpp;
        $this->holidayResolver = $holidayResolver;
        $this->extras   = $extras;
    }
}