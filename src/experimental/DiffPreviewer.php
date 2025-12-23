<?php
declare(strict_types=1);

/**
 * DiffPreviewer
 *
 * Read-only diff preview helper.
 *
 * Responsibilities:
 * - Run scheduler pipeline in dry-run mode
 * - Extract diff summary only
 *
 * IMPORTANT:
 * - No apply
 * - No mutation
 * - No logging
 * - No side effects
 */
final class DiffPreviewer
{
    /**
     * Preview a diff between desired schedule and current scheduler state.
     *
     * @param array $config Loaded plugin configuration
     * @return array Summary counts: ['create' => int, 'update' => int, 'delete' => int]
     */
    public static function preview(array $config): array
    {
        // Run scheduler in dry-run mode to compute diff
        $dryRun = true;
        $horizonDays = GcsFppSchedulerHorizon::getDays();

        $runner = new GcsSchedulerRunner($config, $horizonDays, $dryRun);
        $result = $runner->run();

        $diff = $result['diff'] ?? [];

        return [
            'create' => isset($diff['create']) ? count($diff['create']) : 0,
            'update' => isset($diff['update']) ? count($diff['update']) : 0,
            'delete' => isset($diff['delete']) ? count($diff['delete']) : 0,
        ];
    }
}
