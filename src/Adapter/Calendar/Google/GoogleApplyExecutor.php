<?php
declare(strict_types=1);

/**
 * Calendar Scheduler — Source Component
 *
 * File: Adapter/Calendar/Google/GoogleApplyExecutor.php
 * Purpose: Execute mapped Google mutation operations against the Google API
 * client and return mutation results for apply summaries and diagnostics.
 */

namespace CalendarScheduler\Adapter\Calendar\Google;

use CalendarScheduler\Diff\ReconciliationAction;

final class GoogleApplyExecutor
{
    // Provider API boundary and action-to-mutation mapper.
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
     * Contract note:
     * - Calendar is an intent model and may contain overlapping events.
     * - We intentionally do NOT inject EXDATE-based shadow suppression at apply time.
     *
     * @param ReconciliationAction[] $actions
     * @return GoogleMutationResult[]
     */
    public function applyActions(array $actions): array
    {
        // Convert reconciliation actions to concrete provider mutations.
        $mutations = [];
        foreach ($actions as $action) {
            $mapped = $this->mapper->mapAction($action, $this->client->getConfig());
            foreach ($mapped as $mutation) {
                $mutations[] = $mutation;
            }
        }

        // Emit mapper/client diagnostics around batch execution.
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
        // Execute mutations sequentially to preserve deterministic ordering.
        $results = [];
        foreach ($mutations as $mutation) {
            $results[] = $this->applyOne($mutation);
        }
        return $results;
    }

    private function applyOne(GoogleMutation $mutation): GoogleMutationResult
    {
        // Route each mutation opcode to the matching Google API operation.
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
