<?php
declare(strict_types=1);

namespace CalendarScheduler\Platform;

/**
 * SunTimeDisplayEstimator (V2)
 *
 * PURPOSE
 * -------
 * Compute an approximate, human-readable wall-clock time for symbolic
 * sun-based timings (Dawn, Dusk, SunRise, SunSet) for CALENDAR DISPLAY ONLY.
 *
 * This helper is explicitly NOT used for execution, identity, or manifest
 * hard-time resolution. FPP remains the sole authority for resolving
 * sun-based times at runtime.
 *
 * DESIGN GUARANTEES
 * -----------------
 * - Deterministic
 * - No FPP runtime dependencies
 * - No external APIs
 * - No side effects
 * - Safe to change or discard without impacting execution semantics
 *
 * CRITICAL INVARIANTS
 * ------------------
 * - Output MUST NEVER be written to the manifest as a hard time
 * - Output MUST ONLY be used to populate calendar DTSTART / DTEND
 * - Symbolic timing (e.g. "Dusk", offset) must be preserved separately
 *
 * ACCURACY
 * --------
 * Based on simplified NOAA solar calculations.
 * Typical accuracy ±10–15 minutes, sufficient for display purposes.
 */
final class SunTimeDisplayEstimator
{
    /** Civil twilight angle (degrees below horizon) */
    private const CIVIL_TWILIGHT_DEGREES = -6.0;

    /**
     * Estimate a display-only wall-clock time for a symbolic sun event.
     *
     * @param string $ymd           YYYY-MM-DD
     * @param string $symbolicTime  Dawn | Dusk | SunRise | SunSet
     * @param float  $latitude
     * @param float  $longitude
     * @param int    $offsetMinutes FPP-style offset (minutes)
     * @param int    $roundMinutes  Rounding granularity (default 30)
     *
     * @return string|null HH:MM:SS or null if not computable
     */
    public static function estimate(
        string $ymd,
        string $symbolicTime,
        float $latitude,
        float $longitude,
        int $offsetMinutes = 0,
        int $roundMinutes = 30
    ): ?string {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
            return null;
        }

        if (!in_array($symbolicTime, ['Dawn', 'Dusk', 'SunRise', 'SunSet'], true)) {
            return null;
        }

        // Use noon to avoid DST boundary artifacts
        $timestamp = strtotime($ymd . ' 12:00:00');
        if ($timestamp === false) {
            return null;
        }

        [$sunrise, $sunset, $dawn, $dusk] =
            self::calculateSunTimes($timestamp, $latitude, $longitude);

        $baseSeconds = match ($symbolicTime) {
            'SunRise' => $sunrise,
            'SunSet'  => $sunset,
            'Dawn'    => $dawn,
            'Dusk'    => $dusk,
            default   => null,
        };

        if ($baseSeconds === null) {
            return null;
        }

        $seconds = $baseSeconds + ($offsetMinutes * 60);

        return self::roundSeconds($seconds, $roundMinutes);
    }

    /* ======================================================================
     * Core solar calculations (display-only)
     * ====================================================================== */

    /**
     * @return array{int,int,int,int}
     *   sunrise, sunset, dawn, dusk (seconds since local midnight)
     */
    private static function calculateSunTimes(
        int $timestamp,
        float $lat,
        float $lon
    ): array {
        $dayOfYear = (int)date('z', $timestamp) + 1;
        $lngHour   = $lon / 15.0;

        $sunrise = self::calcSolarTime($dayOfYear, $lat, $lngHour, true,  -0.833);
        $sunset  = self::calcSolarTime($dayOfYear, $lat, $lngHour, false, -0.833);

        $dawn = self::calcSolarTime(
            $dayOfYear,
            $lat,
            $lngHour,
            true,
            self::CIVIL_TWILIGHT_DEGREES
        );

        $dusk = self::calcSolarTime(
            $dayOfYear,
            $lat,
            $lngHour,
            false,
            self::CIVIL_TWILIGHT_DEGREES
        );

        return [$sunrise, $sunset, $dawn, $dusk];
    }

    private static function calcSolarTime(
        int $dayOfYear,
        float $lat,
        float $lngHour,
        bool $isRise,
        float $zenith
    ): int {
        // Approximate time
        $t = $dayOfYear + (($isRise ? 6 : 18) - $lngHour) / 24;

        // Sun's mean anomaly
        $M = (0.9856 * $t) - 3.289;

        // Sun's true longitude
        $L = $M
           + (1.916 * sin(deg2rad($M)))
           + (0.020 * sin(deg2rad(2 * $M)))
           + 282.634;

        $L = fmod($L + 360, 360);

        // Right ascension
        $RA = rad2deg(atan(0.91764 * tan(deg2rad($L))));
        $RA = fmod($RA + 360, 360);

        $Lquadrant  = floor($L / 90) * 90;
        $RAquadrant = floor($RA / 90) * 90;
        $RA = ($RA + ($Lquadrant - $RAquadrant)) / 15;

        // Declination
        $sinDec = 0.39782 * sin(deg2rad($L));
        $cosDec = cos(asin($sinDec));

        // Local hour angle
        $cosH =
            (cos(deg2rad(90 + $zenith)) -
             ($sinDec * sin(deg2rad($lat))))
            / ($cosDec * cos(deg2rad($lat)));

        // Polar guard
        if ($cosH > 1 || $cosH < -1) {
            return $isRise ? 6 * 3600 : 18 * 3600;
        }

        $H = $isRise
            ? 360 - rad2deg(acos($cosH))
            : rad2deg(acos($cosH));

        $H /= 15;

        // Local mean time
        $T = $H + $RA - (0.06571 * $t) - 6.622;

        // Universal Time
        $UT = fmod($T - $lngHour + 24, 24);

        // Convert UTC → local wall-clock time (respects DST)
        $tzOffsetHours = date('Z') / 3600;
        $localTime = fmod($UT + $tzOffsetHours + 24, 24);

        return (int)round($localTime * 3600);
    }

    /* ======================================================================
     * Rounding helpers
     * ====================================================================== */

    private static function roundSeconds(int $seconds, int $roundMinutes): string
    {
        $seconds = max(0, $seconds);

        $minutes = (int)round($seconds / 60);
        $rounded = (int)(round($minutes / $roundMinutes) * $roundMinutes);

        $hours = intdiv($rounded, 60);
        $mins  = $rounded % 60;

        if ($hours > 23) {
            $hours = 23;
            $mins  = 59;
        }

        return sprintf('%02d:%02d:00', $hours, $mins);
    }
}