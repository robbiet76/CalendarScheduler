<?php
declare(strict_types=1);

/**
 * Calendar Scheduler — Source Component
 *
 * File: Adapter/Calendar/Google/GoogleCalendarProvider.php
 * Purpose: Provide a provider-facing wrapper for Google translation/apply
 * collaborators while enforcing V2 ingestion wiring constraints.
 */

namespace CalendarScheduler\Adapter\Calendar\Google;

class GoogleCalendarProvider
{
    // Core Google provider collaborators.
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
     *
     * IMPORTANT:
     * This method is legacy and must NOT be used in the V2 scheduler pipeline.
     * Calendar ingestion now flows through:
     *   Google API → Translator → CalendarSnapshot → ResolutionEngine
     *
     * Any invocation here indicates a wiring error.
     */
    public function ingest(mixed $context): array
    {
        // Ingestion is intentionally blocked to prevent legacy pipeline usage.
        throw new \LogicException(
            'GoogleCalendarProvider::ingest() is not supported in the V2 pipeline. '
            . 'Calendar ingestion must occur via CalendarSnapshot + ResolutionEngine.'
        );
    }

    /**
     * Apply phase entrypoint.
     *
     * @param array[] $applyOps
     */
    public function apply(array $applyOps): void
    {
        // Forward provider mutations to the execute-only apply boundary.
        $this->executor->apply($applyOps);
    }
}
