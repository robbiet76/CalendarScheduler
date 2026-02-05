<?php
declare(strict_types=1);

namespace CalendarScheduler\Adapter\Calendar\Google;

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
     * Apply a batch of reconciliation actions to Google Calendar.
     *
     * @param ReconciliationAction[] $applyOps
     */
    public function apply(array $applyOps): void
    {
        foreach ($applyOps as $op) {
            $this->applyOne($op);
        }
    }

    private function applyOne(ReconciliationAction $action): void
    {
        // Delegate full interpretation to the mapper
        $mapped = $this->mapper->mapAction(
            $action,
            $this->client->getConfig()
        );

        switch ($mapped['op']) {
            case 'create':
                $this->client->createEvent(
                    $mapped['calendarId'],
                    $mapped['payload']
                );
                break;

            case 'update':
                $this->client->updateEvent(
                    $mapped['calendarId'],
                    $mapped['googleEventId'],
                    $mapped['payload']
                );
                break;

            case 'delete':
                $this->client->deleteEvent(
                    $mapped['calendarId'],
                    $mapped['googleEventId']
                );
                break;
        }
    }
}
