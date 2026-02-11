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

    /**
     * Choose the manifest that should be committed as the canonical record of intent.
     *
     * We want the planner-produced desired manifest (calendarManifest) as the commit record.
     * Do NOT depend on "targetManifest" semantics here, since reconciliation may treat that
     * differently (and we've observed it can be empty).
     *
     * @return array<string,mixed>
     */
    private function plannedManifest(ReconciliationResult $result): array
    {
        return $result->targetManifest();
    }

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
                $this->googleExecutor->applyActions($calendarActions);
                $calendarApplied = true;
            }

            // Persist the new canonical manifest (COMMIT RECORD).
            // Commit the planner's desired manifest after side effects have been applied.
            $this->manifestWriter->applyTargetManifest($this->plannedManifest($result));
        } catch (\Throwable $e) {
            throw $e;
        }
    }
}
