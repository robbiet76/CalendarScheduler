<?php
declare(strict_types=1);

/**
 * FPPSemantics
 *
 * Centralized Falcon Player (FPP) semantic knowledge.
 *
 * PURPOSE:
 * - Document and centralize values derived from FPP source / UI behavior
 * - Provide meaning for scheduler enums and sentinel values
 *
 * NON-GOALS:
 * - No holiday calculations
 * - No sun time math
 * - No calendar logic
 * - No external command execution
 *
 * This file should be small, stable, and change only when FPP changes.
 */
final class FPPSemantics
{
    /* =====================================================================
     * Scheduler stop types (derived from FPP)
     * ===================================================================== */

    public const STOP_TYPE_GRACEFUL       = 0;
    public const STOP_TYPE_HARD           = 1;
    public const STOP_TYPE_GRACEFUL_LOOP  = 2;

    public static function stopTypeToString(int $v): string
    {
        return match ($v) {
            self::STOP_TYPE_HARD          => 'hard',
            self::STOP_TYPE_GRACEFUL_LOOP => 'graceful_loop',
            default                       => 'graceful',
        };
    }

    /* =====================================================================
     * Repeat semantics (derived from FPP scheduler behavior)
     * ===================================================================== */

    /**
     * Convert FPP repeat integer into a stable semantic value.
     *
     * 0      → none
     * 1      → immediate
     * 100+   → repeat every N minutes (value / 100)
     */
    public static function repeatToYaml(int $repeat): string|int
    {
        return match (true) {
            $repeat === 0     => 'none',
            $repeat === 1     => 'immediate',
            $repeat >= 100    => (int)($repeat / 100),
            default           => 'none',
        };
    }

    /* =====================================================================
     * Day-of-week enum semantics (derived from FPP)
     * ===================================================================== */

    /**
     * Convert FPP day enum into RFC5545 BYDAY list.
     */
    public static function dayEnumToByDay(int $enum): string
    {
        return match ($enum) {
            0  => 'SU',
            1  => 'MO',
            2  => 'TU',
            3  => 'WE',
            4  => 'TH',
            5  => 'FR',
            6  => 'SA',
            7  => '', // daily
            8  => 'MO,TU,WE,TH,FR',
            9  => 'SU,SA',
            10 => 'MO,WE,FR',
            11 => 'TU,TH',
            12 => 'SU,MO,TU,WE,TH',
            13 => 'FR,SA',
            default => '',
        };
    }

    /* =====================================================================
     * Sentinel and special values used by FPP
     * ===================================================================== */

    /**
     * FPP uses year 0000 as a wildcard / sentinel.
     */
    public const SENTINEL_YEAR = '0000';

    public static function isSentinelDate(string $ymd): bool
    {
        return str_starts_with($ymd, self::SENTINEL_YEAR . '-');
    }

    /**
     * FPP allows 24:00:00 to represent end-of-day.
     */
    public static function isEndOfDayTime(string $time): bool
    {
        return $time === '24:00:00';
    }

    /* =====================================================================
     * Symbolic time identifiers (names only; no math here)
     * ===================================================================== */

    public const SYMBOLIC_TIMES = [
        'Dawn',
        'SunRise',
        'SunSet',
        'Dusk',
    ];

    public static function isSymbolicTime(?string $value): bool
    {
        return is_string($value) && in_array($value, self::SYMBOLIC_TIMES, true);
    }
}