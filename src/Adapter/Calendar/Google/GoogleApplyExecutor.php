<?php
declare(strict_types=1);

namespace CalendarScheduler\Adapter\Calendar\Google;

use CalendarScheduler\Adapter\Calendar\Google\GoogleMutation;
use CalendarScheduler\Adapter\Calendar\Google\GoogleMutationResult;

final class GoogleApplyExecutor
{
    private GoogleApiClient $client;
    private GoogleEventMapper $mapper;

    public function __construct(GoogleApiClient $client, GoogleEventMapper $mapper)
    {
        $this->client = $client;
        $this->mapper = $mapper;
    }

    /**
     * @param GoogleMutation[] $mutations
     * @return GoogleMutationResult[]
     */
    public function apply(array $mutations): array
    {
        $results = [];
        foreach ($mutations as $mutation) {
            $results[] = $this->applyOne($mutation);
        }
        return $results;
    }

    private function applyOne(GoogleMutation $mutation): GoogleMutationResult
    {
        switch ($mutation->op) {
            case GoogleMutation::OP_CREATE:
                $eventId = $this->client->createEvent(
                    $mutation->calendarId,
                    $mutation->payload
                );
                return new GoogleMutationResult($mutation->op, $mutation->calendarId, $eventId);

            case GoogleMutation::OP_UPDATE:
                $this->client->updateEvent(
                    $mutation->calendarId,
                    $mutation->googleEventId,
                    $mutation->payload
                );
                return new GoogleMutationResult($mutation->op, $mutation->calendarId, $mutation->googleEventId);

            case GoogleMutation::OP_DELETE:
                $this->client->deleteEvent(
                    $mutation->calendarId,
                    $mutation->googleEventId
                );
                return new GoogleMutationResult($mutation->op, $mutation->calendarId, $mutation->googleEventId);

            default:
                throw new \RuntimeException(
                    'GoogleApplyExecutor: unsupported mutation op ' . $mutation->op
                );
        }
    }
}
