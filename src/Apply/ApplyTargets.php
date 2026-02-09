<?php
declare(strict_types=1);

namespace CalendarScheduler\Apply;

/**
 * ApplyTargets
 *
 * Canonical list of writable targets for the Apply layer.
 * These are intentionally string literals to avoid tight coupling
 * to Diff-layer internals.
 */
final class ApplyTargets
{
    public const FPP = 'fpp';

    /**
     * Reserved for future use.
     * Manifest writes are currently unconditional and not target-gated.
     */
    public const MANIFEST = 'manifest';
}