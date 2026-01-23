<?php
declare(strict_types=1);

namespace GoogleCalendarScheduler\Resolution;

use RuntimeException;

final class ResolutionInputs
{
    /** @var ResolvableEvent[] keyed by identity hash derived from canonical identity */
    public array $manifestEventsById;

    /** @var ResolvableEvent[] */
    public array $calendarEvents;

    /** @var ResolvableEvent[] */
    public array $fppEvents;

    public ResolutionPolicy $policy;

    /** Arbitrary execution context (for audit / UI / undo) */
    public array $context;

    /**
     * Internal constructor.
     * Callers MUST use named factories to ensure normalization.
     */
    private function __construct(
        array $manifestEventsById,
        array $calendarEvents,
        array $fppEvents,
        ResolutionPolicy $policy,
        array $context
    ) {
        $this->manifestEventsById = $manifestEventsById;
        $this->calendarEvents     = $calendarEvents;
        $this->fppEvents          = $fppEvents;
        $this->policy             = $policy;
        $this->context            = $context;
    }

    /**
     * Factory: build ResolutionInputs from a calendar snapshot manifest
     * and an FPP schedule.json structure.
     *
     * Calendar snapshot events are normalized via fromCalendarManifest.
     * FPP schedule entries are normalized via fromFppScheduleEntry.
     *
     * This is the ONLY supported entry point for resolution.
     */
    public static function fromCalendarSnapshot(
        array $calendarSnapshot,
        array $fppSchedule,
        ResolutionPolicy $policy,
        array $context = []
    ): self {
        if (!isset($calendarSnapshot['events']) || !is_array($calendarSnapshot['events'])) {
            throw new RuntimeException('Calendar snapshot missing events array');
        }

        $calendarEvents = [];
        $manifestEventsById = [];

        foreach ($calendarSnapshot['events'] as $rawEvent) {
            $event = ResolvableEvent::fromCalendarManifest($rawEvent, true);
            if (!$event instanceof ResolvableEvent) {
                throw new RuntimeException('Calendar manifest event normalization failed');
            }
            if ($event->identityHash === '') {
                throw new RuntimeException('ResolvableEvent missing identityHash after normalization');
            }

            $calendarEvents[] = $event;
            $manifestEventsById[$event->identityHash] = $event;
        }

        $fppEvents = [];
        foreach ($fppSchedule as $rawEvent) {
            $evt = ResolvableEvent::fromFppScheduleEntrySimple($rawEvent, false);
            if (!$evt instanceof ResolvableEvent) {
                throw new RuntimeException('FPP schedule entry normalization failed');
            }
            $fppEvents[] = $evt;
        }

        return new self(
            $manifestEventsById,
            $calendarEvents,
            $fppEvents,
            $policy,
            $context
        );
    }
}