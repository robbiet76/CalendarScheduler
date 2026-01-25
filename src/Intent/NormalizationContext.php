<?php
declare(strict_types=1);

namespace GoogleCalendarScheduler\Intent;

use DateTimeZone;
use GoogleCalendarScheduler\Platform\FPPSemantics;

/**
 * NormalizationContext
 *
 * Explicit environmental inputs required to normalize intent.
 */
final class NormalizationContext
{
    public DateTimeZone $timezone;
    public FPPSemantics $fpp;

    /** @var array|null */
    public ?array $holidays;

    /** @var array */
    public array $extras;

    public function __construct(
        DateTimeZone $timezone,
        FPPSemantics $fpp,
        ?array $holidays = null,
        array $extras = []
    ) {
        $this->timezone = $timezone;
        $this->fpp      = $fpp;
        $this->holidays = $holidays;
        $this->extras   = $extras;
    }
}