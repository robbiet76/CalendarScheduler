<?php

/**
 * Orchestrates scheduler diff + apply.
 */
final class SchedulerSync
{
    private bool $dryRun;

    public function __construct(bool $dryRun)
    {
        $this->dryRun = $dryRun;
    }

    /**
     * @param array<int,array<string,mixed>> $desired
     * @return array<string,mixed>
     */
    public function sync(array $desired): array
    {
        // Load existing scheduler state
        $existing = SchedulerState::load();

        GcsLog::info('SchedulerState loaded (stub)', [
            'count' => count($existing),
        ]);

        // Diff desired vs existing
        $diffEngine = new SchedulerDiff();
        $diff = $diffEngine->diff($desired, $existing);

        GcsLog::info('SchedulerDiff summary' . ($this->dryRun ? ' (dry-run)' : ''), [
            'adds'    => count($diff->adds),
            'updates' => count($diff->updates),
            'deletes' => count($diff->deletes),
        ]);

        // Apply (or simulate)
        $applier = new SchedulerApply($this->dryRun);
        $applier->apply($diff);

        return [
            'adds'         => count($diff->adds),
            'updates'      => count($diff->updates),
            'deletes'      => count($diff->deletes),
            'dryRun'       => $this->dryRun,
            'intents_seen' => count($desired),
        ];
    }
}
