<?php
declare(strict_types=1);

namespace CalendarScheduler\Adapter\Calendar;

final class ExecutorApplyRuntime implements CalendarApplyRuntime
{
    /** @var callable(array<int,\CalendarScheduler\Diff\ReconciliationAction>): array<int,CalendarMutationLink> */
    private $applyFn;

    /**
     * @param callable(array<int,\CalendarScheduler\Diff\ReconciliationAction>): array<int,CalendarMutationLink> $applyFn
     */
    public function __construct(
        private readonly string $providerNameValue,
        private readonly string $payloadEventIdFieldValue,
        private readonly string $correlationEventIdsFieldValue,
        callable $applyFn
    ) {
        $this->applyFn = $applyFn;
    }

    public function providerName(): string
    {
        return $this->providerNameValue;
    }

    public function payloadEventIdField(): string
    {
        return $this->payloadEventIdFieldValue;
    }

    public function correlationEventIdsField(): string
    {
        return $this->correlationEventIdsFieldValue;
    }

    public function applyActions(array $actions): array
    {
        return ($this->applyFn)($actions);
    }
}
