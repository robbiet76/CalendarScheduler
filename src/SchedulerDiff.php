<?php

final class GcsSchedulerDiff
{
    /** @var array<int,array<string,mixed>> */
    private array $desired;

    private GcsSchedulerState $state;

    /**
     * @param array<int,array<string,mixed>> $desired
     */
    public function __construct(array $desired, GcsSchedulerState $state)
    {
        $this->desired = $desired;
        $this->state   = $state;
    }

    public function compute(): GcsSchedulerDiffResult
    {
        // Map existing entries by canonical identity key
        $existingByKey = [];
        foreach ($this->state->getEntries() as $entry) {
            $key = $entry->getGcsKey();
            if ($key !== null) {
                $existingByKey[$key] = $entry;
            }
        }

        $toCreate = [];
        $toUpdate = [];
        $seen     = [];

        foreach ($this->desired as $desiredEntry) {
            $key = GcsSchedulerIdentity::extractKey($desiredEntry);
            if ($key === null) {
                // Desired entry without GCS identity is ignored
                continue;
            }

            $seen[$key] = true;

            if (!isset($existingByKey[$key])) {
                $toCreate[] = $desiredEntry;
                continue;
            }

            $existing = $existingByKey[$key];

            if (!GcsSchedulerComparator::isEquivalent($existing, $desiredEntry)) {
                $toUpdate[] = [
                    'existing' => $existing,
                    'desired'  => $desiredEntry,
                ];
            }
        }

        $toDelete = [];
        foreach ($existingByKey as $key => $entry) {
            if (!isset($seen[$key])) {
                $toDelete[] = $entry;
            }
        }

        return new GcsSchedulerDiffResult($toCreate, $toUpdate, $toDelete);
    }
}
