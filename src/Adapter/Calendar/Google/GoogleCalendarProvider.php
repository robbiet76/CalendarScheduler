<?php

namespace CalendarScheduler\Adapter\Calendar\Google;

class GoogleCalendarProvider
{
    private GoogleApplyExecutor $executor;

    public function __construct(GoogleApplyExecutor $executor)
    {
        $this->executor = $executor;
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