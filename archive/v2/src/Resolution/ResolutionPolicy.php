<?php
declare(strict_types=1);

namespace GoogleCalendarScheduler\Resolution;

/**
 * Explicit safety and ownership policy.
 * Resolver MUST NOT infer behavior outside this object.
 */
final class ResolutionPolicy
{
    /** Never mutate unmanaged events unless explicitly allowed */
    public bool $allowMutateUnmanaged = false;

    /** Allow deletion of managed events missing from provider */
    public bool $deleteOrphans = false;

    /** Two-way sync flag (future) */
    public bool $twoWayEnabled = false;

    /** Dry-run: annotate only */
    public bool $dryRun = true;
}