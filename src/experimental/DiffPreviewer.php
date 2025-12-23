<?php
declare(strict_types=1);

/**
 * DiffPreviewer
 *
 * Preview scheduler diffs using the real pipeline.
 *
 * IMPORTANT:
 * - Preview only
 * - No apply
 * - No persistence
 * - No logging side effects
 */
final class DiffPreviewer
{
    /**
     * Compute a diff preview using the scheduler pipeline.
     *
     * @param array $config Loaded plugin configuration
     * @return array Summary counts: ['create' => int, 'update' => int, 'delete' => int]
     */
    public static function preview(array $config): array
    {
        // Force dry-run for preview safety
        $dryRun = true;

        // Use real scheduler horizon
        $horizonDays = GcsFppSchedulerHorizon::getDays();

        // Run scheduler pipeline in dry-run mode
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
