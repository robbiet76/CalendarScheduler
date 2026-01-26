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
        array $extras = []
    ) {
        $this->timezone = $timezone;
        $this->fpp      = $fpp;

        $envPath = __DIR__ . '/../../runtime/fpp-env.json';
        $holidays = [];

        if (is_file($envPath)) {
            $env = json_decode(file_get_contents($envPath), true);
            if (isset($env['rawLocale']['holidays']) && is_array($env['rawLocale']['holidays'])) {
                $holidays = $env['rawLocale']['holidays'];
            }
        }

        $this->holidayResolver = new HolidayResolver($holidays);

        $this->extras   = $extras;
    }
}