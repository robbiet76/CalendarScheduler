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

                /**
                 * IMPORTANT:
                 * FPP deletions are expressed by *absence* from the rewritten schedule.json.
                 * Therefore, when there are any executable FPP actions (create/update/delete),
                 * we must rewrite schedule.json from the *target manifest*, not from only the
                 * create/update action list (which can be empty in a delete-only run).
                 */
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

                    // v2 events contain subEvents; adapter knows how to expand them.
                    $expanded = $this->fppAdapter->toScheduleEntries($event);
                    if ($expanded !== []) {
                        foreach ($expanded as $entry) {
                            if (is_array($entry)) {
                                $scheduleEntries[] = $entry;
                            }
                        }
                        continue;
                    }

                    // Fallback: if a v1-style event slips through, map it as a single entry.
                    $scheduleEntries[] = $this->fppAdapter->toScheduleEntry($event);
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
            // or there were none.
            $this->manifestWriter->applyTargetManifest($result->targetManifest());
        } catch (\Throwable $e) {
            throw $e;
        }
    }
}
