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

        foreach ($this->calendarEvents as $event) {
            if (!($event instanceof ResolvableEvent)) {
                throw new \RuntimeException('ResolutionInputs requires normalized ResolvableEvent objects in calendarEvents');
            }
        }

        foreach ($this->fppEvents as $event) {
            if (!($event instanceof ResolvableEvent)) {
                throw new \RuntimeException('ResolutionInputs requires normalized ResolvableEvent objects in fppEvents');
            }
        }

        foreach ($this->manifestEventsById as $event) {
            if (!($event instanceof ResolvableEvent)) {
                throw new \RuntimeException('ResolutionInputs requires normalized ResolvableEvent objects in manifestEventsById');
            }
        }
    }
}