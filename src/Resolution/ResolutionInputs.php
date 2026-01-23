<?php
declare(strict_types=1);

namespace GoogleCalendarScheduler\Resolution;

final class ResolutionInputs
{
    /** Existing manifest events keyed by identity hash */
    public array $manifestEventsById;

    /** @var ResolvableEvent[] */
    public array $calendarEvents;

    /** @var ResolvableEvent[] */
    public array $fppEvents;

    public ResolutionPolicy $policy;

    public function __construct(
        array $manifestEventsById,
        array $calendarEvents,
        array $fppEvents,
        ResolutionPolicy $policy
    ) {
        $this->manifestEventsById = $manifestEventsById;
        $this->calendarEvents     = $calendarEvents;
        $this->fppEvents          = $fppEvents;
        $this->policy             = $policy;
    }
}