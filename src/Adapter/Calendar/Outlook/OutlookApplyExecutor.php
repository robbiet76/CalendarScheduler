<?php
declare(strict_types=1);

/**
 * Calendar Scheduler — Source Component
 *
 * File: Adapter/Calendar/Outlook/OutlookApplyExecutor.php
 * Purpose: Execute mapped Outlook mutation operations.
 */

namespace CalendarScheduler\Adapter\Calendar\Outlook;

use CalendarScheduler\Diff\ReconciliationAction;

final class OutlookApplyExecutor
{
    private OutlookApiClient $client;
    private OutlookEventMapper $mapper;

    public function __construct(OutlookApiClient $client, OutlookEventMapper $mapper)
    {
        $this->client = $client;
        $this->mapper = $mapper;
    }

    /**
     * @param ReconciliationAction[] $actions
     * @return OutlookMutationResult[]
     */
    public function applyActions(array $actions): array
    {
        $mutations = [];
        foreach ($actions as $action) {
            foreach ($this->mapper->mapAction($action, $this->client->getConfig()) as $mutation) {
                $mutations[] = $mutation;
            }
        }

        $this->mapper->emitDiagnosticsSummary();
        $results = $this->apply($mutations);
        $this->client->emitDiagnosticsSummary();
        return $results;
    }

    /**
     * @param OutlookMutation[] $mutations
     * @return OutlookMutationResult[]
     */
    public function apply(array $mutations): array
    {
        $results = [];
        foreach ($mutations as $mutation) {
            $results[] = $this->applyOne($mutation);
        }
        return $results;
    }

    private function applyOne(OutlookMutation $mutation): OutlookMutationResult
    {
        switch ($mutation->op) {
            case OutlookMutation::OP_CREATE:
                $eventId = $this->client->createEvent($mutation->calendarId, $mutation->payload);
                return new OutlookMutationResult(
                    $mutation->op,
                    $mutation->calendarId,
                    $eventId,
                    $mutation->manifestEventId,
                    $mutation->subEventHash
                );

            case OutlookMutation::OP_UPDATE:
                $this->client->updateEvent($mutation->calendarId, (string)$mutation->outlookEventId, $mutation->payload);
                return new OutlookMutationResult(
                    $mutation->op,
                    $mutation->calendarId,
                    $mutation->outlookEventId,
                    $mutation->manifestEventId,
                    $mutation->subEventHash
                );

            case OutlookMutation::OP_DELETE:
                $this->client->deleteEvent($mutation->calendarId, (string)$mutation->outlookEventId);
                return new OutlookMutationResult(
                    $mutation->op,
                    $mutation->calendarId,
                    $mutation->outlookEventId,
                    $mutation->manifestEventId,
                    $mutation->subEventHash
                );

            default:
                throw new \RuntimeException('OutlookApplyExecutor: unsupported mutation op ' . $mutation->op);
        }
    }
}
