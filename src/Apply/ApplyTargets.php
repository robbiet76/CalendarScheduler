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
    /**
     * Matches ReconciliationAction::TARGET_FPP
     */
    public const FPP = 'fpp';

    /**
     * Matches ReconciliationAction::TARGET_CALENDAR
     */
    public const CALENDAR = 'calendar';
}