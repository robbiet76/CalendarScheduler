<?php
declare(strict_types=1);

namespace CalendarScheduler\Planner;

use CalendarScheduler\Intent\Intent;

/**
 * ManifestPlanner
 *
 * Pure planning layer responsible for constructing a canonical manifest
 * from normalized Intents.
 *
 * Responsibilities:
 * - Materialize Manifest Events from Intents
 * - Compute event-level stateHash
 * - Produce deterministic manifest structure suitable for Diff/Reconcile
 *
 * Non-responsibilities:
 * - No I/O
 * - No persistence
 * - No diff logic
 * - No reconciliation logic
 * - No mutation of existing manifest
 */
final class ManifestPlanner
{
    /**
     * Build an in-memory manifest structure from normalized Intents.
     *
     * This method is PURE:
     * - deterministic
     * - no side effects
     * - no filesystem access
     *
     * @param array<string,Intent> $intents Normalized Intents indexed by identityHash
     * @return array<string,mixed> Manifest structure (not persisted)
     */
    public function buildManifestFromIntents(array $intents): array
    {
        $manifest = [
            'events' => [],
            'version' => 2,
            'generated_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->format(DATE_ATOM),
        ];

        foreach ($intents as $identityHash => $intent) {
            if (!($intent instanceof Intent)) {
                throw new \InvalidArgumentException(
                    "ManifestPlanner: intent at key '{$identityHash}' must be an instance of Intent"
                );
            }

            $event = $this->renderEvent($intent);

            if (
                !isset($event['identityHash']) ||
                !is_string($event['identityHash']) ||
                $event['identityHash'] === ''
            ) {
                throw new \RuntimeException(
                    'ManifestPlanner: rendered event missing required identityHash'
                );
            }

            $manifest['events'][$event['identityHash']] = $event;
        }

        // Deterministic ordering
        ksort($manifest['events'], SORT_STRING);

        return $manifest;
    }

    /**
     * Render a Manifest Event from a normalized Intent.
     *
     * Pure transform — no mutation, no inference.
     */
    private function renderEvent(Intent $intent): array
    {
        $subEvents = [];

        // SubEvents MUST already be ordered and MUST already contain stateHash
        foreach ($intent->subEvents as $sub) {
            if (!isset($sub['stateHash']) || !is_string($sub['stateHash'])) {
                throw new \RuntimeException(
                    'ManifestPlanner: subEvent missing required stateHash'
                );
            }

            $timing = $sub['timing'];

            // Weekly days normalization:
            // If timing.days is absent/null, but RRULE provides BYDAY,
            // lift it into timing.days so downstream FPPSemantics can compute
            // the correct FPP day mask deterministically.
            if (
                (!array_key_exists('days', $timing) || $timing['days'] === null) &&
                isset($sub['payload']['rrule']['byday']) &&
                is_array($sub['payload']['rrule']['byday'])
            ) {
                $timing['days'] = $sub['payload']['rrule']['byday'];
            }

            // If weeklyDays metadata exists (from Resolution layer),
            // propagate it into canonical timing.days so downstream
            // FppSemantics can compute correct day mask.
            if (
                isset($sub['weeklyDays']) &&
                is_array($sub['weeklyDays']) &&
                $sub['weeklyDays'] !== []
            ) {
                $timing['days'] = $sub['weeklyDays'];
            }

            // Enforce canonical timing rule:
            // If symbolic time exists, hard time must be null.
            if (
                isset($timing['start_time']['symbolic']) &&
                $timing['start_time']['symbolic'] !== null
            ) {
                $timing['start_time']['hard'] = null;
            }

            if (
                isset($timing['end_time']['symbolic']) &&
                $timing['end_time']['symbolic'] !== null
            ) {
                $timing['end_time']['hard'] = null;
            }

            $subEvents[] = [
                'stateHash' => $sub['stateHash'],
                'timing'    => $timing,
                'behavior'  => [
                    'enabled'  => $sub['payload']['enabled'] ?? true,
                    'repeat'   => $sub['payload']['repeat'] ?? 'none',
                    'stopType' => $sub['payload']['stopType'] ?? 'graceful',
                ],
                'payload'   => $this->renderPayload($sub['payload']),
            ];
        }

        // Aggregate event-level stateHash deterministically
        $eventStateHash = hash(
            'sha256',
            implode('|', array_map(
                static fn(array $s) => $s['stateHash'],
                $subEvents
            ))
        );

        // Invariants
        if (($intent->ownership['managed'] ?? false) && $subEvents === []) {
            throw new \RuntimeException(
                'ManifestPlanner: managed event must contain at least one subEvent'
            );
        }

        if (($intent->ownership['managed'] ?? false) && $eventStateHash === '') {
            throw new \RuntimeException(
                'ManifestPlanner: managed event missing required stateHash'
            );
        }

        return [
            'id'           => $intent->identityHash,
            'identityHash' => $intent->identityHash,
            'stateHash'    => $eventStateHash,

            'identity'     => $intent->identity,
            'ownership'    => $intent->ownership,
            'correlation'  => $intent->correlation,
            'provenance'   => $intent->provenance ?? null,

            'subEvents'    => $subEvents,
        ];
    }

    /**
     * Render execution payload.
     *
     * Removes behavior fields already lifted into the behavior block.
     */
    private function renderPayload(array $payload): array
    {
        $out = $payload;

        unset(
            $out['enabled'],
            $out['repeat'],
            $out['stopType']
        );

        return $out;
    }
}
