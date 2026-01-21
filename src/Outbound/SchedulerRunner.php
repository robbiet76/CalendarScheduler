<?php

declare(strict_types=1);

namespace GoogleCalendarScheduler\Outbound;

use GoogleCalendarScheduler\Planner\PlannerResult;
use GoogleCalendarScheduler\Diff\Diff;
use GoogleCalendarScheduler\Platform\FppScheduleWriter;

/**
 * SchedulerRunner
 *
 * Orchestrates a single scheduler execution cycle:
 *
 *   PlannerResult → Diff → Apply → (optional) Write
 *
 * The runner does NOT know how to build Planner inputs.
 * That responsibility is injected.
 */
final class SchedulerRunner
{
    private Diff $diff;
    private ApplyEngine $applyEngine;
    private FppScheduleWriter $writer;

    /** @var callable(): PlannerResult */
    private $plannerResultProvider;

    /** @var callable(): array<int,array<string,mixed>> */
    private $existingScheduleLoader;

    /**
     * @param callable(): PlannerResult $plannerResultProvider
     *        Callable responsible for producing the desired PlannerResult
     *
     * @param callable(): array<int,array<string,mixed>> $existingScheduleLoader
     *        Callable responsible for loading the current schedule
     */
    public function __construct(
        callable $plannerResultProvider,
        Diff $diff,
        ApplyEngine $applyEngine,
        FppScheduleWriter $writer,
        callable $existingScheduleLoader
    ) {
        $this->plannerResultProvider = $plannerResultProvider;
        $this->diff = $diff;
        $this->applyEngine = $applyEngine;
        $this->writer = $writer;
        $this->existingScheduleLoader = $existingScheduleLoader;
    }

    /**
     * Execute one scheduler cycle.
     *
     * @throws \RuntimeException on invariant violations or unrecoverable errors
     */
    public function run(SchedulerRunOptions $options): SchedulerRunResult
    {
        // ---------------------------------------------------------------------
        // 1. Planner — desired state (delegated)
        // ---------------------------------------------------------------------

        $plannerResult = ($this->plannerResultProvider)();
        if (!$plannerResult instanceof PlannerResult) {
            throw new \RuntimeException(
                'Planner result provider must return PlannerResult'
            );
        }

        // ---------------------------------------------------------------------
        // 2. Load existing schedule
        // ---------------------------------------------------------------------

        $existingSchedule = ($this->existingScheduleLoader)();
        if (!is_array($existingSchedule)) {
            throw new \RuntimeException(
                'Existing schedule loader must return an array'
            );
        }

        // ---------------------------------------------------------------------
        // 3. Diff — reconcile desired vs existing
        // ---------------------------------------------------------------------

        $diffResult = $this->diff->diff($plannerResult, $existingSchedule);

        if ($diffResult->isNoop()) {
            return new SchedulerRunResult(true, 0, 0, 0);
        }

        // ---------------------------------------------------------------------
        // 4. Apply — outbound orchestration
        // ---------------------------------------------------------------------

        $applyResult = $this->applyEngine->apply(
            $diffResult,
            $existingSchedule
        );

        // ---------------------------------------------------------------------
        // 5. Write — platform side effects (unless dry-run)
        // ---------------------------------------------------------------------

        if (!$options->isDryRun()) {
            $this->writer->write($applyResult->schedule());
        }

        // ---------------------------------------------------------------------
        // 6. Result summary
        // ---------------------------------------------------------------------

        return new SchedulerRunResult(
            false,
            $applyResult->createCount(),
            $applyResult->updateCount(),
            $applyResult->deleteCount()
        );
    }
}