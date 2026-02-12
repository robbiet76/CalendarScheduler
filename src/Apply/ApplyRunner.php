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
        $executable = $result->executableActions();
        $blocked = $result->blockedActions();

        $actionsByTarget = [
            ReconciliationAction::TARGET_FPP => [],
            ReconciliationAction::TARGET_CALENDAR => [],
        ];

        foreach ($executable as $action) {
            if (isset($actionsByTarget[$action->target])) {
                $actionsByTarget[$action->target][] = $action;
            }
        }

        if ($blocked !== [] && $options->failOnBlockedActions) {
            $messages = [];
            foreach ($blocked as $action) {
                $messages[] = "Blocked: {$action->identityHash} ({$action->reason})";
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
                $target = $result->targetManifest();
                $targetEvents = $target['events'] ?? [];
                if (!is_array($targetEvents)) {
                    $targetEvents = [];
                }

                $scheduleEntries = [];
                foreach ($targetEvents as $event) {
                    if (!is_array($event)) {
                        continue;
                    }

                    // v2 manifest event: 1 calendar event => N FPP schedule entries (subEvents)
                    // Adapter expects a single executable entry shape, so we flatten here.
                    if (isset($event['subEvents']) && is_array($event['subEvents'])) {
                        $eventType = $event['identity']['type'] ?? $event['type'] ?? null;
                        $eventTarget = $event['identity']['target'] ?? $event['target'] ?? null;

                        foreach ($event['subEvents'] as $sub) {
                            if (!is_array($sub)) {
                                continue;
                            }

                            $timing = $sub['timing'] ?? null;
                            if (!is_array($timing)) {
                                continue;
                            }

                            $payload = is_array($sub['payload'] ?? null) ? $sub['payload'] : [];
                            $behavior = is_array($sub['behavior'] ?? null) ? $sub['behavior'] : [];

                            // Lift behavior fields into payload for FPP adapter compatibility.
                            // (Adapter expects enabled/repeat/stopType within payload.)
                            $payload = array_merge($payload, [
                                'enabled'  => $behavior['enabled']  ?? ($payload['enabled']  ?? true),
                                'repeat'   => $behavior['repeat']   ?? ($payload['repeat']   ?? 'none'),
                                'stopType' => $behavior['stopType'] ?? ($payload['stopType'] ?? 'graceful'),
                            ]);

                            $flat = [
                                'type'        => $eventType,
                                'target'      => $eventTarget,
                                'timing'      => $timing,
                                'payload'     => $payload,
                                'ownership'   => is_array($event['ownership'] ?? null) ? $event['ownership'] : [],
                                'correlation' => is_array($event['correlation'] ?? null) ? $event['correlation'] : [],
                                'source'      => 'manifest',
                            ];

                            $scheduleEntries[] = $this->fppAdapter->toScheduleEntry($flat);
                        }

                        continue;
                    }

                    // Legacy-style single executable event
                    $scheduleEntries[] = $this->fppAdapter->toScheduleEntry($event);
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
                    $this->googleExecutor->apply($calendarActions);
                    $calendarApplied = true;
                }
            }

            // Persist canonical manifest ONLY during real apply
            if (!$options->isPlan() && !$options->isDryRun()) {
                $this->manifestWriter->applyTargetManifest($result->targetManifest());
            }
        } catch (\Throwable $e) {
            throw $e;
        }
    }
}
