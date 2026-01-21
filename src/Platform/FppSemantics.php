<?php
declare(strict_types=1);

namespace GoogleCalendarScheduler\Platform;

/**
 * FPPSemantics (V2)
 *
 * Centralized Falcon Player (FPP) semantic definitions.
 *
 * PURPOSE:
 * - Encode factual FPP scheduler semantics
 * - Define enums, sentinel values, and symbolic identifiers
 * - Provide pure, side-effect-free conversions
 *
 * HARD RULES:
 * - No heuristics
 * - No logging
 * - No calendar logic
 * - No identity logic
 * - No defaults or policy
 * - No planner knowledge
 *
 * This file must only change when FPP runtime behavior changes.
 */
final class FPPSemantics
{
    /* =====================================================================
     * Entry types (FPP scheduler targets)
     * ===================================================================== */

    public const TYPE_PLAYLIST = 'playlist';
    public const TYPE_SEQUENCE = 'sequence';
    public const TYPE_COMMAND  = 'command';

    /**
     * Normalize entry type to a known FPP type.
     */
    public static function normalizeType(?string $type): string
    {
        return match ($type) {
            self::TYPE_SEQUENCE => self::TYPE_SEQUENCE,
            self::TYPE_COMMAND  => self::TYPE_COMMAND,
            default             => self::TYPE_PLAYLIST,
        };
    }

    /* =====================================================================
     * Enabled / disabled semantics
     * ===================================================================== */

    /**
     * Normalize FPP enabled field to boolean.
     */
    public static function normalizeEnabled(mixed $value): bool
    {
        return !($value === false || $value === 0 || $value === '0');
    }

    /* =====================================================================
     * Scheduler stop types (FPP enum)
     * ===================================================================== */

    /**
     * FPP ScheduleEntry.cpp:
     *   0 = Graceful
     *   1 = Hard
     *   2 = Graceful Loop
     */
    public const STOP_TYPE_GRACEFUL      = 0;
    public const STOP_TYPE_HARD          = 1;
    public const STOP_TYPE_GRACEFUL_LOOP = 2;

    /**
     * Normalize stopType value into valid FPP enum.
     */
    public static function stopTypeToEnum(mixed $value): int
    {
        if (is_int($value)) {
            return max(
                self::STOP_TYPE_GRACEFUL,
                min(self::STOP_TYPE_GRACEFUL_LOOP, $value)
            );
        }

        if (is_string($value)) {
            return match (strtolower(trim($value))) {
                'hard', 'hard_stop'     => self::STOP_TYPE_HARD,
                'graceful_loop'         => self::STOP_TYPE_GRACEFUL_LOOP,
                default                 => self::STOP_TYPE_GRACEFUL,
            };
        }

        return self::STOP_TYPE_GRACEFUL;
    }

    /* =====================================================================
     * Repeat semantics (opaque to Platform)
     * ===================================================================== */

    /**
     * Normalize repeat value to integer.
     *
     * Platform does not interpret repeat semantics;
     * it only preserves and forwards values.
     */
    public static function normalizeRepeat(mixed $value): int
    {
        return is_numeric($value) ? (int)$value : 0;
    }

    /* =====================================================================
     * Day-of-week enum semantics (FPP bitmask)
     * ===================================================================== */

    /**
     * Raw day mask values are preserved as-is.
     *
     * Interpretation is handled elsewhere.
     */
    public static function normalizeDayMask(mixed $value): int
    {
        return is_numeric($value) ? (int)$value : 0;
    }

    /* =====================================================================
     * Sentinel values
     * ===================================================================== */

    public const SENTINEL_YEAR = '0000';

    /**
     * Determine if a date is an FPP sentinel date.
     */
    public static function isSentinelDate(string $ymd): bool
    {
        return str_starts_with($ymd, self::SENTINEL_YEAR . '-');
    }

    /**
     * Determine if a time represents end-of-day in FPP.
     */
    public static function isEndOfDayTime(string $time): bool
    {
        return $time === '24:00:00';
    }

    /* =====================================================================
     * Symbolic time identifiers
     * ===================================================================== */

    public const SYMBOLIC_TIMES = [
        'Dawn',
        'SunRise',
        'SunSet',
        'Dusk',
    ];

    /**
     * Check if a time value is symbolic.
     */
    public static function isSymbolicTime(?string $value): bool
    {
        return is_string($value)
            && in_array($value, self::SYMBOLIC_TIMES, true);
    }
}