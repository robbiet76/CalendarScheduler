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

    /**
     * Return the default repeat value for a given FPP entry type.
     *
     * This reflects native FPP scheduler behavior.
     */
    public static function defaultRepeatForType(string $type): int
    {
        return match ($type) {
            self::TYPE_COMMAND  => 0,
            default             => 1,
        };
    }

    /**
     * Determine if a repeat value represents an immediate repeat.
     */
    public static function isImmediateRepeat(int $value): bool
    {
        return $value === 1;
    }

    /**
     * Determine if a repeat value represents no repeat.
     */
    public static function isNoneRepeat(int $value): bool
    {
        return $value === 0;
    }


    /**
     * Determine if a repeat value represents a finite repeat count.
     */
    public static function isCountRepeat(int $value): bool
    {
        return $value > 1;
    }

    /* =====================================================================
     * Repeat semantic mappings
     * ===================================================================== */

    /**
     * Canonical mapping of FPP repeat values to semantic labels.
     *
     * These values are factual FPP scheduler semantics.
     */
    public const REPEAT_MAP = [
        0  => 'none',
        1  => 'immediate',
        5  => '5min',
        10 => '10min',
        15 => '15min',
        20 => '20min',
        30 => '30min',
        60 => '60min',
    ];

    /**
     * Convert numeric repeat value to semantic string.
     */
    public static function repeatToSemantic(int $value): string
    {
        return self::REPEAT_MAP[$value] ?? 'none';
    }

    /**
     * Convert semantic repeat value to numeric FPP value.
     */
    public static function semanticToRepeat(string $value): int
    {
        $value = strtolower(trim($value));

        foreach (self::REPEAT_MAP as $numeric => $semantic) {
            if ($semantic === $value) {
                return $numeric;
            }
        }

        return 0;
    }

    /* =====================================================================
     * Default behavior values (FPP scheduler defaults)
     * ===================================================================== */

    /**
     * NOTE:
     * These defaults describe FPP runtime behavior when fields are omitted.
     * They are factual semantics, not planner policy.
     */

    /**
     * Return canonical default behavior values used by FPP
     * when no explicit behavior is provided.
     */
    public static function defaultBehavior(): array
    {
        return [
            'enabled'  => true,
            'repeat'   => 0,
            'stopType' => self::STOP_TYPE_GRACEFUL,
        ];
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

    /**
     * Default rounding interval (minutes) used by FPP
     * when resolving symbolic times.
     */
    public const DEFAULT_TIME_ROUNDING_MINUTES = 30;

    /* =====================================================================
     * Symbolic time offset handling
     * ===================================================================== */

    /**
     * Normalize symbolic time offset to integer.
     *
     * Offset semantics are opaque to Platform; values are preserved
     * and interpreted later by FPP.
     */
    public static function normalizeTimeOffset(mixed $value): int
    {
        return is_numeric($value) ? (int)$value : 0;
    }
}