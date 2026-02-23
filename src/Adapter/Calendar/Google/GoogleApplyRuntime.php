<?php
declare(strict_types=1);

namespace CalendarScheduler\Adapter\Calendar\Google;

use CalendarScheduler\Adapter\Calendar\CalendarApplyRuntime;
use CalendarScheduler\Adapter\Calendar\CalendarMutationLink;

final class GoogleApplyRuntime implements CalendarApplyRuntime
{
    public function __construct(
        private readonly GoogleApplyExecutor $executor
    ) {}

    public function providerName(): string
    {
        return 'google';
    }

    public function payloadEventIdField(): string
    {
        return 'googleEventId';
    }

    public function correlationEventIdsField(): string
    {
        return 'googleEventIds';
    }

    public function applyActions(array $actions): array
    {
        $results = $this->executor->applyActions($actions);
        $links = [];
        foreach ($results as $result) {
            if (!($result instanceof GoogleMutationResult)) {
                continue;
            }
            if (($result->op ?? '') !== GoogleMutation::OP_CREATE && ($result->op ?? '') !== GoogleMutation::OP_UPDATE) {
                continue;
            }
            $eventId = is_string($result->googleEventId ?? null) ? trim((string)$result->googleEventId) : '';
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

