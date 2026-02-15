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
     * @return GoogleMutationResult[]
     */
    public function applyActions(array $actions): array
    {
        $mutations = [];

        foreach ($actions as $action) {
            $mapped = $this->mapper->mapAction($action, $this->client->getConfig());
            foreach ($mapped as $mutation) {
                $mutations[] = $mutation;
            }
        }

        $this->mapper->emitDiagnosticsSummary();
        $results = $this->apply($mutations);
        $this->client->emitDiagnosticsSummary();
        return $results;
    }

    /**
     * Apply a batch of Google mutations to Google Calendar.
     *
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
                return new GoogleMutationResult(
                    $mutation->op,
                    $mutation->calendarId,
                    $eventId,
                    $mutation->manifestEventId,
                    $mutation->subEventHash
                );

            case GoogleMutation::OP_UPDATE:
                $this->client->updateEvent(
                    $mutation->calendarId,
                    $mutation->googleEventId,
                    $mutation->payload
                );
                return new GoogleMutationResult(
                    $mutation->op,
                    $mutation->calendarId,
                    $mutation->googleEventId,
                    $mutation->manifestEventId,
                    $mutation->subEventHash
                );

            case GoogleMutation::OP_DELETE:
                $this->client->deleteEvent(
                    $mutation->calendarId,
                    $mutation->googleEventId
                );
                return new GoogleMutationResult(
                    $mutation->op,
                    $mutation->calendarId,
                    $mutation->googleEventId,
                    $mutation->manifestEventId,
                    $mutation->subEventHash
                );

            default:
                throw new \RuntimeException(
                    'GoogleApplyExecutor: unsupported mutation op ' . $mutation->op
                );
        }
    }
}
