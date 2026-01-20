<?php
declare(strict_types=1);

namespace GCS\Planner;

use GCS\Core\ManifestInvariantViolation;

/**
 * Planner
 *
 * Transforms a VALID Manifest into ordered PlannedEntries.
 */
final class Planner
{
    public function plan(array $manifest): PlannerResult
    {
        $events = $manifest['events'] ?? [];

        if (!is_array($events)) {
            throw ManifestInvariantViolation::fail(
                ManifestInvariantViolation::MANIFEST_ROOT_INVALID,
                'Manifest.events must be an array'
            );
        }

        $planned = [];

        foreach ($events as $eventOrder => $event) {
            $this->assertEventShape($event, $eventOrder);

            foreach ($event['subEvents'] as $subOrder => $sub) {
                $planned[] = new PlannedEntry(
                    eventId:              $event['id'],
                    eventIdentityHash:    $event['identity_hash'],
                    subEventIdentityHash: $sub['identity_hash'],
                    type:                 $sub['intent']['type'],
                    target:               $sub['intent']['target'],
                    timing:               $sub['intent']['timing'],
                    enabled:              $sub['intent']['enabled'],
                    eventOrder:           $eventOrder,
                    subEventOrder:        $subOrder
                );
            }
        }

        // Attach ordering keys
        $withKeys = array_map(
            fn (PlannedEntry $e) => [
                'entry' => $e,
                'key'   => OrderingKey::fromPlannedEntry($e)
            ],
            $planned
        );

        // Sort deterministically
        usort(
            $withKeys,
            fn ($a, $b) => OrderingKey::compare($a['key'], $b['key'])
        );

        return new PlannerResult(
            array_map(fn ($row) => $row['entry'], $withKeys)
        );
    }

    private function assertEventShape(array $event, int $index): void
    {
        foreach (['id', 'identity_hash', 'subEvents'] as $field) {
            if (!isset($event[$field])) {
                throw ManifestInvariantViolation::fail(
                    ManifestInvariantViolation::EVENT_IDENTITY_MISSING,
                    "Manifest event missing {$field}",
                    ['index' => $index]
                );
            }
        }

        if (!is_array($event['subEvents'])) {
            throw ManifestInvariantViolation::fail(
                ManifestInvariantViolation::SUBEVENT_IDENTITY_INVALID,
                'Manifest event subEvents must be an array',
                ['eventId' => $event['id']]
            );
        }
    }
}