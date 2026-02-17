<?php
declare(strict_types=1);

/**
 * Calendar Scheduler — Source Component
 *
 * File: Intent/NormalizationContext.php
 * Purpose: Provide all environmental dependencies required by intent
 * normalization (timezone, FPP semantics, holiday resolver, and extras).
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
    // Core normalization dependencies shared across all parsed intents.
    public DateTimeZone $timezone;
    public FPPSemantics $fpp;
    public HolidayResolver $holidayResolver;

    // Optional context for future provider-specific or runtime-specific behavior.
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
