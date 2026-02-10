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

        // Guard: prevent mutations or manifest writes during plan or dry-run modes
        if ($options->isPlan() || $options->isDryRun()) {
            return;
        }

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
        // NOTE: If a target has executable actions but policy disallows writes AND we are not failing,
        // we MUST NOT commit the target manifest, because external state will not match.
        $skippedTargetsDueToPolicy = [];

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

                // Skip execution for this target (but do not commit manifest later)
                $actionsByTarget[$target] = [];
                $skippedTargetsDueToPolicy[$target] = true;
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

                $scheduleEntries = [];

                foreach ($fppActions as $action) {
                    if ($action->event === null) {
                        throw new \RuntimeException(
                            'ApplyRunner: FPP action missing manifest event for ' . $action->identityHash
                        );
                    }

                    if (
                        $action->type === ReconciliationAction::TYPE_CREATE ||
                        $action->type === ReconciliationAction::TYPE_UPDATE
                    ) {
                        $scheduleEntries[] = $this->fppAdapter->toScheduleEntry($action->event);
                    }

                    if ($action->type === ReconciliationAction::TYPE_DELETE) {
                        // Deletes are handled by full schedule rewrite semantics;
                        // absence from rendered entries implies deletion.
                        continue;
                    }
                }

                $this->fppWriter->write($scheduleEntries);
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


            // Persist the new canonical manifest.
            // This is a COMMIT RECORD: only write it when all executable actions were either applied
            // or there were none. If we skipped any target due to policy, external state will not match.
            if ($skippedTargetsDueToPolicy === []) {
                $this->manifestWriter->applyTargetManifest($result->targetManifest());
            }
        } catch (\Throwable $e) {
            throw $e;
        }
    }
}