<?php

final class SchedulerSync
{
    private bool $dryRun;

    public function __construct(bool $dryRun)
    {
        $this->dryRun = $dryRun;
    }

    /**
     * Sync desired mapped schedule entries against existing FPP scheduler state.
     *
     * @param array<int,array<string,mixed>> $desiredMapped
     * @return array<string,mixed>
     */
    public function sync(array $desiredMapped): array
    {
        // Phase 8.1: load existing (read-only)
        $existing = SchedulerState::loadExisting();

        // Phase 8.2: diff (read-only)
        $diff = SchedulerDiff::diff($desiredMapped, $existing);

        // Phase 8.3: apply plan (dry-run guarded; still no writes in this phase)
        $counts = SchedulerApply::apply($diff, $this->dryRun);

        return [
            'adds'         => (int)($counts['adds'] ?? 0),
            'updates'      => (int)($counts['updates'] ?? 0),
            'deletes'      => (int)($counts['deletes'] ?? 0),
            'dryRun'       => $this->dryRun,
            'intents_seen' => count($desiredMapped),
        ];
    }
}
