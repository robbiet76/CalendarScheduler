<?php

declare(strict_types=1);

namespace CalendarScheduler\Apply;

use CalendarScheduler\Diff\ReconciliationAction;
use CalendarScheduler\Diff\ReconciliationResult;
use CalendarScheduler\Apply\ApplyOptions;
use CalendarScheduler\Adapter\FppScheduleAdapter;
use CalendarScheduler\Apply\FppScheduleWriter;

/**
 * ApplyRunner
 *
 * The ONLY layer allowed to mutate external state.
 * Operates exclusively on executable actions from ReconciliationResult.
 */
final class ApplyRunner
{
    public function __construct(
        private readonly ManifestWriter $manifestWriter,
        private readonly ?FppScheduleAdapter $fppAdapter = null,
        private readonly ?FppScheduleWriter $fppWriter = null,
        private readonly ?\CalendarScheduler\Adapter\Calendar\Google\GoogleApplyExecutor $googleExecutor = null
    ) {}

    public function apply(
        ReconciliationResult $result,
        ApplyOptions $options
    ): void
    {
        $targetManifest = $result->targetManifest();
        $executable = $result->executableActions();
        $blocked = $result->blockedActions();

        $actionsByTarget = [
            ReconciliationAction::TARGET_FPP => [],
            ReconciliationAction::TARGET_CALENDAR => [],
        ];
        $disallowed = [];

        foreach ($executable as $action) {
            if (!isset($actionsByTarget[$action->target])) {
                continue;
            }
            if (!$options->canWrite($action->target)) {
                $disallowed[] = $action;
                continue;
            }
            $actionsByTarget[$action->target][] = $action;
        }

        if (($blocked !== [] || $disallowed !== []) && $options->failOnBlockedActions) {
            $messages = [];
            foreach ($blocked as $action) {
                $messages[] = "Blocked: {$action->identityHash} ({$action->reason})";
            }
            foreach ($disallowed as $action) {
                $messages[] = "Blocked by target policy: {$action->identityHash} ({$action->target} not writable)";
            }
            throw new \RuntimeException('Apply blocked: ' . implode('; ', $messages));
        }

        $fppActions = $actionsByTarget[ReconciliationAction::TARGET_FPP];
        $calendarActions = $actionsByTarget[ReconciliationAction::TARGET_CALENDAR];
        $fppApplied = false;
        $calendarApplied = false;

        try {
            if ($fppActions !== []) {
                if ($this->fppAdapter === null || $this->fppWriter === null) {
                    throw new \RuntimeException(
                        'FPP actions present but FppScheduleAdapter and/or FppScheduleWriter not configured'
                    );
                }

                // Build full target schedule from manifest (deletes expressed by absence)
                $target = $targetManifest;
                $targetEvents = $target['events'] ?? [];
                if (!is_array($targetEvents)) {
                    $targetEvents = [];
                }

                $scheduleEntries = [];
                foreach ($targetEvents as $event) {
                    if (!is_array($event)) {
                        continue;
                    }

                    // v2 manifest event: 1 manifest event => N FPP schedule entries (subEvents)
                    // FppScheduleAdapter expects the v2 manifest shape: identity + subEvents.
                    $identity = $event['identity'] ?? null;
                    $subEvents = $event['subEvents'] ?? null;

                    if (!is_array($identity) || !is_array($subEvents)) {
                        // No legacy support in this project; fail fast with a helpful message.
                        $id = is_string($event['identityHash'] ?? null) ? $event['identityHash'] : '(missing identityHash)';
                        throw new \InvalidArgumentException(
                            "ApplyRunner: target manifest event is missing required v2 keys (identity/subEvents). identityHash={$id}"
                        );
                    }

                    foreach ($subEvents as $sub) {
                        if (!is_array($sub)) {
                            continue;
                        }

                        // Ensure behavior fields are present in payload for adapters that expect them.
                        // We keep the canonical v2 shape, but duplicate enabled/repeat/stopType into payload.
                        $payload = is_array($sub['payload'] ?? null) ? $sub['payload'] : [];
                        $behavior = is_array($sub['behavior'] ?? null) ? $sub['behavior'] : [];
                        $payload = array_merge($payload, [
                            'enabled'  => $behavior['enabled']  ?? ($payload['enabled']  ?? true),
                            'repeat'   => $behavior['repeat']   ?? ($payload['repeat']   ?? 'none'),
                            'stopType' => $behavior['stopType'] ?? ($payload['stopType'] ?? 'graceful'),
                        ]);

                        $single = [
                            'id'           => $event['id'] ?? ($event['identityHash'] ?? null),
                            'identityHash' => $event['identityHash'] ?? null,
                            'stateHash'    => $event['stateHash'] ?? null,
                            'identity'     => $identity,
                            'ownership'    => is_array($event['ownership'] ?? null) ? $event['ownership'] : [],
                            'correlation'  => is_array($event['correlation'] ?? null) ? $event['correlation'] : [],
                            'provenance'   => $event['provenance'] ?? null,
                            'subEvents'    => [
                                array_merge($sub, [
                                    'payload' => $payload,
                                ]),
                            ],
                            'source'       => 'manifest',
                        ];

                        $scheduleEntries[] = $this->fppAdapter->toScheduleEntry($single);
                    }
                }

                // ALWAYS write staged schedule (even in plan/dry-run)
                $this->fppWriter->writeStaged($scheduleEntries);

                // Only commit to live schedule.json during real apply
                if (!$options->isPlan() && !$options->isDryRun()) {
                    $this->fppWriter->commitStaged();
                    $fppApplied = true;
                }
            }

            if ($calendarActions !== []) {
                if ($this->googleExecutor === null) {
                    throw new \RuntimeException(
                        'Calendar actions present but no GoogleApplyExecutor configured'
                    );
                }

                if (!$options->isPlan() && !$options->isDryRun()) {
                    $googleResults = $this->googleExecutor->applyActions($calendarActions);
                    $targetManifest = $this->applyGoogleMutationResultsToManifest($targetManifest, $googleResults);
                    $calendarApplied = true;
                }
            }

            // Persist canonical manifest ONLY during real apply
            if (!$options->isPlan() && !$options->isDryRun()) {
                $this->manifestWriter->applyTargetManifest($targetManifest);
            }
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    /**
     * Persist Google provider linkage into manifest subEvent payloads so subsequent
     * update/delete operations can resolve concrete googleEventId values.
     *
     * @param array<string,mixed> $manifest
     * @param array<int,\CalendarScheduler\Adapter\Calendar\Google\GoogleMutationResult> $results
     * @return array<string,mixed>
     */
    private function applyGoogleMutationResultsToManifest(array $manifest, array $results): array
    {
        $events = $manifest['events'] ?? [];
        if (!is_array($events) || $results === []) {
            return $manifest;
        }

        foreach ($results as $result) {
            if (!($result instanceof \CalendarScheduler\Adapter\Calendar\Google\GoogleMutationResult)) {
                continue;
            }

            if (
                $result->op !== \CalendarScheduler\Adapter\Calendar\Google\GoogleMutation::OP_CREATE
                && $result->op !== \CalendarScheduler\Adapter\Calendar\Google\GoogleMutation::OP_UPDATE
            ) {
                continue;
            }

            if (!is_string($result->googleEventId) || $result->googleEventId === '') {
                continue;
            }

            $id = $result->manifestEventId;
            if (!is_array($events[$id] ?? null)) {
                continue;
            }

            $event = $events[$id];
            $subEvents = $event['subEvents'] ?? null;
            if (!is_array($subEvents)) {
                continue;
            }

            foreach ($subEvents as $i => $sub) {
                if (!is_array($sub)) {
                    continue;
                }

                $stateHash = $sub['stateHash'] ?? null;
                if (!is_string($stateHash) || $stateHash === '') {
                    continue;
                }

                if ($stateHash !== $result->subEventHash) {
                    continue;
                }

                $payload = is_array($sub['payload'] ?? null) ? $sub['payload'] : [];
                $payload['googleEventId'] = $result->googleEventId;
                $sub['payload'] = $payload;
                $subEvents[$i] = $sub;
                break;
            }

            $correlation = is_array($event['correlation'] ?? null) ? $event['correlation'] : [];
            $googleEventIds = is_array($correlation['googleEventIds'] ?? null) ? $correlation['googleEventIds'] : [];
            $googleEventIds[$result->subEventHash] = $result->googleEventId;
            $correlation['googleEventIds'] = $googleEventIds;
            $event['correlation'] = $correlation;
            $event['subEvents'] = array_values($subEvents);
            $events[$id] = $event;
        }

        $manifest['events'] = $events;
        return $manifest;
    }
}
