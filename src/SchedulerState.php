<?php
declare(strict_types=1);

final class GcsSchedulerState
{
    /** @var array<int,GcsExistingScheduleEntry> */
    private array $entries = [];

    /**
     * Create an empty scheduler state.
     * Population is explicit via add().
     */
    public function __construct()
    {
    }

    /**
     * Add an existing scheduler entry to state.
     */
    public function add(GcsExistingScheduleEntry $entry): void
    {
        $this->entries[] = $entry;
    }

    /**
     * @return array<int,GcsExistingScheduleEntry>
     */
    public function getEntries(): array
    {
        return $this->entries;
    }
}
