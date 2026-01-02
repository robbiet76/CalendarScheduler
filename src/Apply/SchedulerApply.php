<?php
declare(strict_types=1);

/**
 * SchedulerApply
 *
 * APPLY BOUNDARY
 *
 * This class is the ONLY component permitted to mutate FPP's schedule.json.
 *
 * CORE RESPONSIBILITIES:
 * - Re-run the planner to obtain a canonical diff
 * - Enforce dry-run and safety policies
 * - Merge desired managed entries with existing unmanaged entries
 * - Preserve identity and GCS tags exactly
 * - Write schedule.json atomically
 * - Verify post-write integrity
 *
 * HARD GUARANTEES:
 * - Unmanaged entries are never modified
 * - Managed entries are matched by FULL GCS identity tag (current model)
 * - schedule.json is never partially written
 * - Apply is idempotent for the same planner output
 *
 * Phase 28 change:
 * - Apply no longer "fixes" planner output ordering.
 * - Managed entries are written in the exact order provided by Planner.
 * - Overrides adjacency is therefore controlled by Planner bundles.
 */
final class SchedulerApply
{
    public static function applyFromConfig(array $cfg): array
    {
        GcsLogger::instance()->info('GCS APPLY ENTERED', [
            'dryRun' => !empty($cfg['runtime']['dry_run']),
        ]);

        $plan   = SchedulerPlanner::plan($cfg);
        $dryRun = !empty($cfg['runtime']['dry_run']);

        $existing = (isset($plan['existingRaw']) && is_array($plan['existingRaw']))
            ? $plan['existingRaw']
            : [];

        $desired = (isset($plan['desiredEntries']) && is_array($plan['desiredEntries']))
            ? $plan['desiredEntries']
            : [];

        $previewCounts = [
            'creates' => isset($plan['creates']) && is_array($plan['creates']) ? count($plan['creates']) : 0,
            'updates' => isset($plan['updates']) && is_array($plan['updates']) ? count($plan['updates']) : 0,
            'deletes' => isset($plan['deletes']) && is_array($plan['deletes']) ? count($plan['deletes']) : 0,
        ];

        if ($dryRun) {
            return [
                'ok'             => true,
                'dryRun'         => true,
                'counts'         => $previewCounts,
                'creates'        => $plan['creates'] ?? [],
                'updates'        => $plan['updates'] ?? [],
                'deletes'        => $plan['deletes'] ?? [],
                'desiredEntries' => $desired,
                'existingRaw'    => $existing,
                'desiredBundles' => $plan['desiredBundles'] ?? [],
            ];
        }

        $applyPlan = self::planApply($existing, $desired);

        if (
            count($applyPlan['creates']) === 0 &&
            count($applyPlan['updates']) === 0 &&
            count($applyPlan['deletes']) === 0
        ) {
            return [
                'ok'     => true,
                'dryRun' => false,
                'counts' => ['creates' => 0, 'updates' => 0, 'deletes' => 0],
                'noop'   => true,
            ];
        }

        $backupPath = SchedulerSync::backupScheduleFileOrThrow(
            SchedulerSync::SCHEDULE_JSON_PATH
        );

        SchedulerSync::writeScheduleJsonAtomicallyOrThrow(
            SchedulerSync::SCHEDULE_JSON_PATH,
            $applyPlan['newSchedule']
        );

        SchedulerSync::verifyScheduleJsonKeysOrThrow(
            $applyPlan['expectedManagedKeys'],
            $applyPlan['expectedDeletedKeys']
        );

        return [
            'ok'     => true,
            'dryRun' => false,
            'counts' => $previewCounts,
            'backup' => $backupPath,
        ];
    }

    /**
     * Build apply plan:
     * - Unmanaged entries preserved in original order
     * - Managed entries rewritten in Planner-provided order (NO global resort)
     *
     * @param array<int,array<string,mixed>> $existing
     * @param array<int,array<string,mixed>> $desired
     * @return array<string,mixed>
     */
    private static function planApply(array $existing, array $desired): array
    {
        // Index desired entries by FULL GCS identity key (current model)
        $desiredByKey       = [];
        $desiredKeysInOrder = [];

        foreach ($desired as $d) {
            if (!is_array($d)) {
                continue;
            }

            $k = SchedulerIdentity::extractKey($d);
            if ($k === null) {
                continue;
            }

            if (!isset($desiredByKey[$k])) {
                $desiredKeysInOrder[] = $k;
            }

            $norm = self::normalizeForApply($d);

            if (!isset($norm['args']) || !is_array($norm['args'])) {
                $norm['args'] = [];
            }

            $hasTag = false;
            foreach ($norm['args'] as $a) {
                if (is_string($a) && strpos($a, SchedulerIdentity::TAG_MARKER) === 0) {
                    $hasTag = true;
                    break;
                }
            }

            if (!$hasTag) {
                $norm['args'][] = $k;
            }

            $desiredByKey[$k] = $norm;
        }

        // Index existing managed entries by identity key
        $existingManagedByKey = [];
        foreach ($existing as $ex) {
            if (!is_array($ex)) {
                continue;
            }

            $k = SchedulerIdentity::extractKey($ex);
            if ($k === null) {
                continue;
            }

            $existingManagedByKey[$k] = $ex;
        }

        // Compute create/update/delete sets
        $createsKeys = [];
        $updatesKeys = [];
        $deletesKeys = [];

        foreach ($desiredByKey as $k => $d) {
            if (!isset($existingManagedByKey[$k])) {
                $createsKeys[] = $k;
                continue;
            }

            if (!self::entriesEquivalentForCompare($existingManagedByKey[$k], $d)) {
                $updatesKeys[] = $k;
            }
        }

        foreach ($existingManagedByKey as $k => $_) {
            if (!isset($desiredByKey[$k])) {
                $deletesKeys[] = $k;
            }
        }

        /*
         * Construct new schedule.json:
         * - Preserve unmanaged entries in original order
         * - Append managed entries in Planner order (desiredKeysInOrder)
         *
         * This intentionally stops global re-sorting. Planner controls adjacency.
         */
        $newSchedule = [];

        // 1) Unmanaged entries preserved
        foreach ($existing as $ex) {
            if (!is_array($ex)) {
                $newSchedule[] = $ex;
                continue;
            }
            $k = SchedulerIdentity::extractKey($ex);
            if ($k === null) {
                $newSchedule[] = $ex;
            }
        }

        // 2) Managed entries in Planner order
        foreach ($desiredKeysInOrder as $k) {
            if (isset($desiredByKey[$k])) {
                $newSchedule[] = $desiredByKey[$k];
            }
        }

        return [
            'creates'             => $createsKeys,
            'updates'             => $updatesKeys,
            'deletes'             => $deletesKeys,
            'newSchedule'         => $newSchedule,
            'expectedManagedKeys' => array_keys($desiredByKey),
            'expectedDeletedKeys' => $deletesKeys,
        ];
    }

    private static function normalizeForApply(array $entry): array
    {
        // FPP "day" is an enum (0..15), NOT a bitmask
        if (!isset($entry['day']) || !is_int($entry['day']) || $entry['day'] < 0 || $entry['day'] > 15) {
            $entry['day'] = 7; // Everyday
        }

        if (isset($entry['args']) && !is_array($entry['args'])) {
            $entry['args'] = [];
        }

        return $entry;
    }

    private static function entriesEquivalentForCompare(array $a, array $b): bool
    {
        unset(
            $a['id'],
            $a['lastRun'],
            $b['id'],
            $b['lastRun']
        );

        ksort($a);
        ksort($b);

        return $a === $b;
    }
}
