<?php
declare(strict_types=1);

namespace GoogleCalendarScheduler\Intent;

use DateTimeZone;
use GoogleCalendarScheduler\Platform\FPPSemantics;
use GoogleCalendarScheduler\Platform\HolidayResolver;

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