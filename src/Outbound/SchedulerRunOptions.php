<?php

declare(strict_types=1);

namespace GoogleCalendarScheduler\Outbound;

/**
 * SchedulerRunOptions
 *
 * Describes how a scheduler run should be executed.
 *
 * This is intentionally minimal.
 * Additional options (locking, force, verbosity) can be added later
 * without changing the SchedulerRunner API.
 */
final class SchedulerRunOptions
{
    private bool $dryRun;

    public function __construct(bool $dryRun = false)
    {
        $this->dryRun = $dryRun;
    }

    /**
     * If true, the scheduler will:
     * - plan
     * - diff
     * - apply (in memory)
     * - but NOT write schedule.json
     */
    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    /**
     * If true, the scheduler will run resolution in read-only mode.
     * No manifest or schedule mutations will be applied.
     */
    public function isReadOnlyResolution(): bool
    {
        return false;
    }
}