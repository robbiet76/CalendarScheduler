<?php
declare(strict_types=1);

namespace CalendarScheduler\Apply;

use CalendarScheduler\Diff\ReconciliationAction;
use CalendarScheduler\Diff\ReconciliationResult;
use CalendarScheduler\Apply\ApplyOptions;
use CalendarScheduler\Adapter\FppScheduleAdapter;
use CalendarScheduler\Apply\FppScheduleMutator;
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
        private readonly ?FppScheduleMutator $fppMutator = null,
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

        // Enforce per-target canWrite policy
        foreach ($actionsByTarget as $target => $actions) {
            if ($actions === []) {
                continue;
            }

            if (!$options->canWrite($target)) {
                if ($options->failOnBlockedActions) {
                    throw new \RuntimeException(
                        "Apply blocked: target '{$target}' is not writable by policy"
                    );
                }

                // Skip execution for this target
                $actionsByTarget[$target] = [];
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
                if ($this->fppMutator === null || $this->fppWriter === null) {
                    throw new \RuntimeException(
                        'FPP actions present but FppScheduleMutator and/or FppScheduleWriter not configured'
                    );
                }
                $schedule = $this->fppWriter->load();
                $schedule = $this->fppMutator->apply($schedule, $fppActions);
                $this->fppWriter->write($schedule);
                $fppApplied = true;
            }

            if ($calendarActions !== []) {
                if ($this->googleExecutor === null) {
                    throw new \RuntimeException(
                        'Calendar actions present but no GoogleApplyExecutor configured'
                    );
                }
                $this->googleExecutor->apply($calendarActions);
                $calendarApplied = true;
            }

            if ($fppApplied !== $calendarApplied) {
                throw new \RuntimeException('Apply failed: target symmetry violation between FPP and Calendar actions');
            }

            // Persist the new canonical manifest
            $this->manifestWriter->applyTargetManifest($result->targetManifest());
        } catch (\Throwable $e) {
            throw $e;
        }
    }
}