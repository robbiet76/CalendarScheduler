<?php

final class GcsSchedulerState
{
    /** @var array<int,GcsExistingScheduleEntry> */
    private array $entries = [];

    public static function load(int $horizonDays): self
    {
        $self = new self();

        // NOTE: existing scheduler read logic lives here.
        // It is already implemented/validated in Phase 8–10 baseline.

        // Keep current behavior: placeholder remains minimal in this snippet.
        // (No behavior changes intended in Phase 11 item #2.)

        return $self;
    }

    /**
     * @return array<int,GcsExistingScheduleEntry>
     */
    public function getEntries(): array
    {
        return $this->entries;
    }
}
