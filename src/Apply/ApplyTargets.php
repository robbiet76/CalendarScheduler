<?php
declare(strict_types=1);

namespace CalendarScheduler\Apply;

use CalendarScheduler\Diff\ReconciliationAction;

/**
 * ApplyTargets
 *
 * Canonical list of writable external reconciliation targets for the Apply layer.
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
    public const TARGET_FPP = ReconciliationAction::TARGET_FPP;

    /**
     * Matches ReconciliationAction::TARGET_CALENDAR
     */
    public const TARGET_CALENDAR = ReconciliationAction::TARGET_CALENDAR;

    /**
     * Authoritative map of all valid targets.
     *
     * @var array<string, true>
     */
    public const ALL = [
        self::TARGET_FPP => true,
        self::TARGET_CALENDAR => true,
    ];

    /**
     * Convenience: only allow FPP writes.
     *
     * @return list<string>
     */
    public static function fppOnly(): array
    {
        return [self::TARGET_FPP];
    }

    /**
     * Convenience: allow all writable external reconciliation targets.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::TARGET_FPP,
            self::TARGET_CALENDAR,
        ];
    }

    /**
     * Returns true if the given target is a valid apply target.
     */
    public static function isValid(string $target): bool
    {
        return isset(self::ALL[$target]);
    }
}