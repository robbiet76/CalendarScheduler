<?php
declare(strict_types=1);

namespace CalendarScheduler\Adapter\Calendar\Google;

use CalendarScheduler\Adapter\Calendar\Google\GoogleMutation;
use CalendarScheduler\Adapter\Calendar\Google\GoogleMutationResult;
use CalendarScheduler\Diff\ReconciliationAction;

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
     * Convenience entrypoint: map ReconciliationAction[] to GoogleMutation[] and apply.
     *
     * This keeps the "apply() consumes GoogleMutation[] only" contract intact,
     * while allowing ApplyRunner to pass actions at the orchestration boundary.
     *
     * @param ReconciliationAction[] $actions
     */
    public function applyActions(array $actions): void
    {
        $mutations = [];

        foreach ($actions as $action) {
            $mapped = $this->mapper->mapAction($action, $this->client->getConfig());
            foreach ($mapped as $mutation) {
                $mutations[] = $mutation;
            }
        }

        $this->apply($mutations);
    }

    /**
     * Apply a batch of reconciliation actions to Google Calendar.
     *
     * @param GoogleMutation[] $mutations
     */
    public function apply(array $mutations): void
    {
        foreach ($mutations as $mutation) {
            $this->applyOne($mutation);
        }
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
