<?php
declare(strict_types=1);

namespace GoogleCalendarScheduler\Resolution;

final class CalendarManifestResolver implements EventResolver
{
    public function resolve(ResolutionInputs $in): ResolutionResult
    {
        $result = new ResolutionResult();

        $manifest = $in->manifestEventsById;
        $policy   = $in->policy;

        $seen = [];

        foreach ($in->calendarEvents as $calEvent) {
            $id = $calEvent->identityHash;
            $seen[$id] = true;

            if (!isset($manifest[$id])) {
                $result->addOperation(
                    new ResolutionOperation(
                        ResolutionOperation::UPSERT,
                        $id,
                        $calEvent->event,
                        'calendar_new_event'
                    )
                );
                continue;
            }

            $existing = $manifest[$id];

            if (!$calEvent->managed && !$policy->allowMutateUnmanaged) {
                $result->addOperation(
                    new ResolutionOperation(
                        ResolutionOperation::REVIEW,
                        $id,
                        null,
                        'unmanaged_event_conflict'
                    )
                );
                continue;
            }

            if ($existing->event !== $calEvent->event) {
                $result->addOperation(
                    new ResolutionOperation(
                        ResolutionOperation::UPSERT,
                        $id,
                        $calEvent->event,
                        'calendar_event_changed'
                    )
                );
            } else {
                $result->addOperation(
                    new ResolutionOperation(
                        ResolutionOperation::NOOP,
                        $id,
                        null,
                        'no_change'
                    )
                );
            }
        }

        if ($policy->deleteOrphans) {
            foreach ($manifest as $id => $evt) {
                if (!isset($seen[$id])) {
                    $result->addOperation(
                        new ResolutionOperation(
                            ResolutionOperation::REVIEW,
                            $id,
                            null,
                            'missing_from_calendar'
                        )
                    );
                }
            }
        }

        return $result;
    }
}