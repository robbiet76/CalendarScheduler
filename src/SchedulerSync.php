<?php

final class GcsSchedulerSync
{
    private array $cfg;
    private int $horizonDays;
    private bool $dryRun;

    public function __construct(array $cfg, int $horizonDays, bool $dryRun)
    {
        $this->cfg = $cfg;
        $this->horizonDays = $horizonDays;
        $this->dryRun = $dryRun;
    }

    /**
     * Execute full pipeline.
     *
     * @return array<string,mixed>
     */
    public function run(): array
    {
        // Load scheduler state
        $state = GcsSchedulerState::load($this->horizonDays);

        GcsLog::info('SchedulerState loaded (stub)', [
            'count' => count($state->getEntries()),
        ]);

        // TODO: will be used by SchedulerRunner orchestration
        // For now keep legacy logs for continuity

        $diff = new GcsSchedulerDiff([], $state);
        $diffResult = $diff->compute();

        GcsLog::info('SchedulerDiff summary' . ($this->dryRun ? ' (dry-run)' : ''), [
            'create' => count($diffResult->getToCreate()),
            'update' => count($diffResult->getToUpdate()),
            'delete' => count($diffResult->getToDelete()),
        ]);

        $apply = new GcsSchedulerApply($this->dryRun);
        $applySummary = $apply->apply($diffResult);

        return [
            'dryRun' => $this->dryRun,
            'diff'   => $diffResult->toArray(),
            'apply'  => $applySummary,
        ];
    }
}
