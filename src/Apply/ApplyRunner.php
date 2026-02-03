<?php
declare(strict_types=1);

namespace CalendarScheduler\Apply;

use CalendarScheduler\Diff\ReconciliationAction;
use CalendarScheduler\Diff\ReconciliationResult;
use CalendarScheduler\Apply\ApplyOptions;

/**
 * ApplyRunner
 *
 * The ONLY layer allowed to mutate external state.
 */
final class ApplyRunner
{
    public function __construct(
        private readonly ManifestWriter $manifestWriter
    ) {}

    public function evaluate(
        ReconciliationResult $result,
        ApplyOptions $options
    ): ApplyEvaluation
    {
        $evaluation = new ApplyEvaluation();

        foreach ($result->actions() as $action) {
            if ($action->type === ReconciliationAction::TYPE_NOOP) {
                $evaluation->noops[] = $action;
                continue;
            }

            if ($options->canWrite($action->target)) {
                $evaluation->allowed[] = $action;
            } else {
                $evaluation->blocked[] = $action;
            }
        }

        return $evaluation;
    }

    public function apply(
        ReconciliationResult $result,
        ApplyOptions $options
    ): void
    {
        $evaluation = $this->evaluate($result, $options);

        if ($options->isPlan() || $options->isDryRun()) {
            return;
        }

        if ($options->isApply()) {
            if ($evaluation->blocked !== [] && $options->failOnBlockedActions) {
                $messages = [];
                foreach ($evaluation->blocked as $action) {
                    $messages[] = "Blocked: {$action->identityHash} ({$action->reason})";
                }
                throw new \RuntimeException('Apply blocked: ' . implode('; ', $messages));
            }

            $this->manifestWriter->applyTargetManifest($result->targetManifest());
        }
    }
}