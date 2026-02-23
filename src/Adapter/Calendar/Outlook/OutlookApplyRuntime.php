<?php
declare(strict_types=1);

namespace CalendarScheduler\Adapter\Calendar\Outlook;

use CalendarScheduler\Adapter\Calendar\CalendarApplyRuntime;
use CalendarScheduler\Adapter\Calendar\CalendarMutationLink;

final class OutlookApplyRuntime implements CalendarApplyRuntime
{
    public function __construct(
        private readonly OutlookApplyExecutor $executor
    ) {}

    public function providerName(): string
    {
        return 'outlook';
    }

    public function payloadEventIdField(): string
    {
        return 'outlookEventId';
    }

    public function correlationEventIdsField(): string
    {
        return 'outlookEventIds';
    }

    public function applyActions(array $actions): array
    {
        $results = $this->executor->applyActions($actions);
        $links = [];
        foreach ($results as $result) {
            if (!($result instanceof OutlookMutationResult)) {
                continue;
            }
            if (($result->op ?? '') !== OutlookMutation::OP_CREATE && ($result->op ?? '') !== OutlookMutation::OP_UPDATE) {
                continue;
            }
            $eventId = is_string($result->outlookEventId ?? null) ? trim((string)$result->outlookEventId) : '';
            if ($eventId === '') {
                continue;
            }
            $manifestEventId = is_string($result->manifestEventId ?? null) ? trim((string)$result->manifestEventId) : '';
            $subEventHash = is_string($result->subEventHash ?? null) ? trim((string)$result->subEventHash) : '';
            if ($manifestEventId === '' || $subEventHash === '') {
                continue;
            }
            $links[] = new CalendarMutationLink(
                (string)$result->op,
                $manifestEventId,
                $subEventHash,
                $eventId
            );
        }
        return $links;
    }
}

