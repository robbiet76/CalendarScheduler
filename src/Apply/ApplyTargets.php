<?php
declare(strict_types=1);

namespace CalendarScheduler\Apply;

use CalendarScheduler\Diff\ReconciliationAction;

/**
 * ApplyTargets
 *
 * Canonical list of writable targets for the Apply layer.
 *
 * IMPORTANT:
 * - These constants MUST align exactly with ReconciliationAction::TARGET_*
 * - ApplyTargets is the single source of truth for target validation
 */
final class ApplyTargets
{
    /**
     * Matches ReconciliationAction::TARGET_FPP
     */
    public const FPP = ReconciliationAction::TARGET_FPP;

    /**
     * Matches ReconciliationAction::TARGET_CALENDAR
     */
    public const CALENDAR = ReconciliationAction::TARGET_CALENDAR;

    /**
     * Literal 'manifest' target.
     */
    public const MANIFEST = 'manifest';

    /**
     * Validate a reconciliation target.
     */
    public static function isValid(string $target): bool
    {
        return in_array($target, [
            self::FPP,
            self::CALENDAR,
            self::MANIFEST,
        ], true);
    }
}