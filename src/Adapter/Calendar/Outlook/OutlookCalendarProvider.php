<?php
declare(strict_types=1);

/**
 * Calendar Scheduler — Source Component
 *
 * File: Adapter/Calendar/Outlook/OutlookCalendarProvider.php
 * Purpose: Provider wrapper for Outlook translation/apply collaborators.
 */

namespace CalendarScheduler\Adapter\Calendar\Outlook;

class OutlookCalendarProvider
{
    private OutlookApplyExecutor $executor;
    private OutlookCalendarTranslator $translator;

    public function __construct(OutlookCalendarTranslator $translator, OutlookApplyExecutor $executor)
    {
        $this->translator = $translator;
        $this->executor = $executor;
    }

    public function ingest(mixed $context): array
    {
        throw new \LogicException(
            'OutlookCalendarProvider::ingest() is not supported in the V2 pipeline. '
            . 'Calendar ingestion must occur via CalendarSnapshot + ResolutionEngine.'
        );
    }

    /** @param array<int,mixed> $applyOps */
    public function apply(array $applyOps): void
    {
        $this->executor->apply($applyOps);
    }
}
