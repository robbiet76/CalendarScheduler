<?php
declare(strict_types=1);

/**
 * Calendar Scheduler — Source Component
 *
 * File: Planner/ResolvedScheduleToIntentAdapter.php
 * Purpose: Defines the ResolvedScheduleToIntentAdapter component used by the Calendar Scheduler Planner layer.
 */

namespace CalendarScheduler\Planner;

use CalendarScheduler\Intent\Intent;
use CalendarScheduler\Resolution\Dto\ResolvedBundle;
use CalendarScheduler\Resolution\Dto\ResolvedSchedule;
use CalendarScheduler\Resolution\Dto\ResolvedSubevent;

/**
 * ResolvedScheduleToIntentAdapter (Option A)
 *
 * Converts Resolution output (bundles/subevents) into Intent objects
 * that can be consumed by ManifestPlanner.
 *
 * Notes:
 * - This is a bridge for pipeline plumbing while Resolution/Intent boundaries evolve.
 * - Symbolic times are carried in payload. We do NOT resolve symbolic times here.
 * - End timestamps are treated as exclusive at the ResolutionScope layer.
 */
final class ResolvedScheduleToIntentAdapter
{
    /**
     * @return array<string,Intent> indexed by identityHash
     */
    public function toIntents(ResolvedSchedule $schedule): array
    {
        $out = [];

        foreach ($schedule->getBundles() as $bundle) {
            $intent = $this->bundleToIntent($bundle);
            $out[$intent->identityHash] = $intent;
        }

        ksort($out, SORT_STRING);
        return $out;
    }

    private function bundleToIntent(ResolvedBundle $bundle): Intent
    {
        $subEvents = [];

        foreach ($bundle->getSubevents() as $subevent) {
            $subEvents[] = $this->subeventToIntentSubEvent($subevent);
        }

        $identity = $this->buildIdentity($bundle, $subEvents);
        $identityHash = $this->hashIdentityAndSubEvents($identity, $subEvents);

        $ownership = [
            // These are calendar-managed by definition of Resolution output.
            'managed' => true,
        ];

        $correlation = [
            'parentUid' => $bundle->getParentUid(),
            'sourceEventUid' => $bundle->getSourceEventUid(),
        ];

        return new Intent(
            $identityHash,
            $identity,
            $ownership,
            $correlation,
            $subEvents
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function subeventToIntentSubEvent(ResolvedSubevent $s): array
    {
        $timing = [
            // Planner/Manifest will carry these forward; no execution-time resolution here.
            'start' => $s->getStart()->format(DATE_ATOM),
            'end'   => $s->getEnd()->format(DATE_ATOM),
            'allDay' => $s->isAllDay(),
            'timezone' => $s->getTimezone(),
            'scope' => [
                'segmentStart' => $s->getScope()->getStart()->format(DATE_ATOM),
                'segmentEnd'   => $s->getScope()->getEnd()->format(DATE_ATOM),
            ],
        ];

        // Must exist for ManifestPlanner invariants.
        $stateHash = hash('sha256', json_encode([
            'role' => $s->getRole(),
            'priority' => $s->getPriority(),
            'timing' => $timing,
            'payload' => $s->getPayload(),
        ], JSON_THROW_ON_ERROR));

        return [
            'stateHash' => $stateHash,
            'timing'    => $timing,
            'payload'   => $s->getPayload(),
            // Optional metadata; not used by identity/state hashing elsewhere.
            'role'      => $s->getRole(),
            'priority'  => $s->getPriority(),
            'sourceTrace' => $s->getSourceTrace(),
        ];
    }

    /**
     * Intent.identity is meant to be human meaningful and stable.
     * We keep it minimal and deterministic.
     *
     * @param array<int,array<string,mixed>> $subEvents
     * @return array<string,mixed>
     */
    private function buildIdentity(ResolvedBundle $bundle, array $subEvents): array
    {
        // Best-effort type/target inference from payload.
        $firstPayload = $subEvents[0]['payload'] ?? [];
        $type = is_array($firstPayload) && isset($firstPayload['type']) ? (string)$firstPayload['type'] : 'playlist';
        $target = is_array($firstPayload) && isset($firstPayload['playlist']) ? (string)$firstPayload['playlist'] : $bundle->getParentUid();

        $scopeStart = $bundle->getSegmentScope()->getStart();
        $scopeEndEx = $bundle->getSegmentScope()->getEnd();

        // Identity timing is date-level and display-level only at this stage.
        $startDate = $scopeStart->format('Y-m-d');
        $endDate   = $scopeEndEx->modify('-1 day')->format('Y-m-d'); // scope end is exclusive

        return [
            'type' => $type,
            'target' => $target,
            'timing' => [
                'start_date' => $startDate,
                'end_date'   => $endDate,
                // Use the base subevent start/end time as a stable display-time.
                // Symbolic time remains in payload and is not resolved here.
                'start_time' => $scopeStart->format('H:i:s'),
                'end_time'   => $scopeEndEx->modify('-1 second')->format('H:i:s'),
                'days'       => null,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $identity
     * @param array<int,array<string,mixed>> $subEvents
     */
    private function hashIdentityAndSubEvents(array $identity, array $subEvents): string
    {
        // Per Intent contract: identityHash depends ONLY on identity + subEvents.
        return hash('sha256', json_encode([
            'identity' => $identity,
            'subEvents' => $subEvents,
        ], JSON_THROW_ON_ERROR));
    }
}
