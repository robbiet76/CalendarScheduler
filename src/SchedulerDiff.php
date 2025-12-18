<?php

/**
 * Computes differences between desired scheduler entries and existing FPP state.
 */
final class SchedulerDiff
{
    /**
     * @param array<int,array<string,mixed>> $desired
     * @param array<int,array<string,mixed>> $existing
     */
    public function diff(array $desired, array $existing): SchedulerDiffResult
    {
        $adds = [];
        $updates = [];
        $deletes = [];

        // Index existing entries by playlist identity
        $existingByKey = [];

        foreach ($existing as $e) {
            if (!isset($e['playlist'])) {
                continue;
            }
            $existingByKey[$e['playlist']] = $e;
        }

        // Adds + updates
        foreach ($desired as $d) {
            if (!isset($d['playlist'])) {
                continue;
            }

            $key = $d['playlist'];

            if (!isset($existingByKey[$key])) {
                $adds[] = $d;
                continue;
            }

            $existingEntry = $existingByKey[$key];

            if ($this->isDifferent($d, $existingEntry)) {
                $updates[] = [
                    'from' => $existingEntry,
                    'to'   => $d,
                ];
            }

            unset($existingByKey[$key]);
        }

        // Remaining existing entries are deletes (Phase 8.6)
        foreach ($existingByKey as $e) {
            $deletes[] = $e;
        }

        return new SchedulerDiffResult($adds, $updates, $deletes);
    }

    /**
     * Determine whether two scheduler entries differ meaningfully.
     */
    private function isDifferent(array $a, array $b): bool
    {
        $fields = [
            'startTime',
            'endTime',
            'dayMask',
            'startDate',
            'endDate',
            'repeat',
            'stopType',
        ];

        foreach ($fields as $f) {
            if (($a[$f] ?? null) !== ($b[$f] ?? null)) {
                return true;
            }
        }

        return false;
    }
}
