<?php
declare(strict_types=1);

/**
 * SchedulerPlanner
 *
 * Planning-only entry point.
 * NEVER writes to FPP scheduler.
 */
final class SchedulerPlanner
{
    /**
     * Compute scheduler diff (plan-only).
     *
     * @param array $config
     * @return array{
     *   creates: array,
     *   updates: array,
     *   deletes: array,
     *   desiredEntries: array,
     *   existingRaw: array
     * }
     */
    public static function plan(array $config): array
    {
        // 1. Calendar ingestion → intents (PURE)
        $runner = new GcsSchedulerRunner(
            $config,
            GcsFppSchedulerHorizon::getDays()
        );

        $result = $runner->run();
        if (empty($result['ok']) || empty($result['intents'])) {
            return self::emptyPlan();
        }

        // 2. Map intents → desired scheduler entries (PURE)
        $desiredEntries = [];
        foreach ($result['intents'] as $intent) {
            if (!is_array($intent)) {
                continue;
            }

            $entry = SchedulerSync::intentToScheduleEntryPublic($intent);
            if (is_array($entry)) {
                $desiredEntries[] = $entry;
            }
        }

        // 3. Load existing schedule.json
        $existingRaw = SchedulerSync::readScheduleJsonStatic(
            SchedulerSync::SCHEDULE_JSON_PATH
        );

        $state = new GcsSchedulerState();
        foreach ($existingRaw as $row) {
            if (is_array($row)) {
                $state->add(new GcsExistingScheduleEntry($row));
            }
        }

        // 4. Compute diff
        $diff = new GcsSchedulerDiff($desiredEntries, $state);
        $res  = $diff->compute();

        return [
            'creates'        => $res->creates(),
            'updates'        => $res->updates(),
            'deletes'        => $res->deletes(),
            'desiredEntries' => $desiredEntries,
            'existingRaw'    => $existingRaw,
        ];
    }

    private static function emptyPlan(): array
    {
        return [
            'creates'        => [],
            'updates'        => [],
            'deletes'        => [],
            'desiredEntries' => [],
            'existingRaw'    => [],
        ];
    }
}
