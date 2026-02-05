<?php

namespace CalendarScheduler\Adapter\Calendar\Google;

class GoogleCalendarProvider
{
    private GoogleApplyExecutor $executor;
    private GoogleCalendarTranslator $translator;

    public function __construct(
        GoogleCalendarTranslator $translator,
        GoogleApplyExecutor $executor
    ) {
        $this->translator = $translator;
        $this->executor = $executor;
    }

    /**
     * Ingest calendar data from Google into provider-neutral CalendarEvent records.
     */
    public function ingest($context): array
    {
        // GoogleCalendarTranslator::ingest expects (rawEvents, context)
        return $this->translator->ingest($context['events'] ?? [], $context);
    }

    /**
     * Apply phase entrypoint.
     *
     * @param array[] $applyOps
     */
    public function apply(array $applyOps): void
    {
        $this->executor->apply($applyOps);
    }
}
