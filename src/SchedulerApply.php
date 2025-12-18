<?php

final class SchedulerApply
{
    /**
     * Apply (or dry-run apply) a diff plan.
     *
     * IMPORTANT: Phase 8.3 remains side-effect free.
     * - In dry-run: logs what would happen
     * - In live mode: still logs only (writes are introduced in next phase)
     *
     * @param array{adds:array,updates:array,deletes:array} $diff
     * @param bool $dryRun
     * @return array{adds:int,updates:int,deletes:int}
     */
    public static function apply(array $diff, bool $dryRun): array
    {
        $adds = is_array($diff['adds'] ?? null) ? $diff['adds'] : [];
        $updates = is_array($diff['updates'] ?? null) ? $diff['updates'] : [];
        $deletes = is_array($diff['deletes'] ?? null) ? $diff['deletes'] : [];

        $result = [
            'adds'    => count($adds),
            'updates' => count($updates),
            'deletes' => count($deletes),
        ];

        if ($dryRun) {
            self::logDryRun($adds, $updates, $deletes);
            return $result;
        }

        // Phase 8.3: still no real writes. We only log.
        // Next phase will implement actual scheduler persistence.
        self::logLiveNotImplemented($adds, $updates, $deletes);

        return $result;
    }

    /**
     * @param array<int,array<string,mixed>> $adds
     * @param array<int,array<string,mixed>> $updates
     * @param array<int,array<string,mixed>> $deletes
     */
    private static function logDryRun(array $adds, array $updates, array $deletes): void
    {
        foreach ($adds as $e) {
            GcsLog::info('[DRY-RUN] APPLY ADD', [
                'playlist'  => (string)($e['playlist'] ?? ''),
                'startDate' => (string)($e['startDate'] ?? ''),
                'endDate'   => (string)($e['endDate'] ?? ''),
                'dayMask'   => (int)($e['dayMask'] ?? 0),
                'startTime' => (string)($e['startTime'] ?? ''),
                'endTime'   => (string)($e['endTime'] ?? ''),
            ]);
        }

        foreach ($updates as $u) {
            $from = is_array($u['from'] ?? null) ? $u['from'] : [];
            $to   = is_array($u['to'] ?? null) ? $u['to'] : [];

            GcsLog::info('[DRY-RUN] APPLY UPDATE', [
                'from' => self::compactEntry($from),
                'to'   => self::compactEntry($to),
            ]);
        }

        foreach ($deletes as $e) {
            GcsLog::info('[DRY-RUN] APPLY DELETE', [
                'playlist'  => (string)($e['playlist'] ?? ''),
                'startDate' => (string)($e['startDate'] ?? ''),
                'endDate'   => (string)($e['endDate'] ?? ''),
                'dayMask'   => (int)($e['dayMask'] ?? 0),
                'startTime' => (string)($e['startTime'] ?? ''),
                'endTime'   => (string)($e['endTime'] ?? ''),
                'rawIndex'  => (int)($e['rawIndex'] ?? -1),
            ]);
        }
    }

    /**
     * @param array<int,array<string,mixed>> $adds
     * @param array<int,array<string,mixed>> $updates
     * @param array<int,array<string,mixed>> $deletes
     */
    private static function logLiveNotImplemented(array $adds, array $updates, array $deletes): void
    {
        GcsLog::info('[LIVE] SchedulerApply is not yet writing schedule.json (Phase 8.3)', [
            'plannedAdds'    => count($adds),
            'plannedUpdates' => count($updates),
            'plannedDeletes' => count($deletes),
        ]);
    }

    /**
     * @param array<string,mixed> $e
     * @return array<string,mixed>
     */
    private static function compactEntry(array $e): array
    {
        return [
            'enabled'   => (int)($e['enabled'] ?? 0),
            'playlist'  => (string)($e['playlist'] ?? ''),
            'startDate' => (string)($e['startDate'] ?? ''),
            'endDate'   => (string)($e['endDate'] ?? ''),
            'dayMask'   => (int)($e['dayMask'] ?? 0),
            'startTime' => (string)($e['startTime'] ?? ''),
            'endTime'   => (string)($e['endTime'] ?? ''),
            'repeat'    => (int)($e['repeat'] ?? 0),
            'stopType'  => (int)($e['stopType'] ?? 0),
        ];
    }
}
