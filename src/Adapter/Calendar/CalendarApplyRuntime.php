<?php
declare(strict_types=1);

namespace CalendarScheduler\Adapter\Calendar;

interface CalendarApplyRuntime
{
    public function providerName(): string;

    public function payloadEventIdField(): string;

    public function correlationEventIdsField(): string;

    /**
     * @param array<int,\CalendarScheduler\Diff\ReconciliationAction> $actions
     * @return array<int,CalendarMutationLink>
     */
    public function applyActions(array $actions): array;
}

