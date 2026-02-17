<?php
declare(strict_types=1);

/**
 * Calendar Scheduler — Source Component
 *
 * File: Platform/IniMetadata.php
 * Purpose: Defines the IniMetadata component used by the Calendar Scheduler Platform layer.
 */

namespace CalendarScheduler\Platform;

/**
 * IniMetadata
 *
 * Generic, deterministic INI-style metadata parser for calendar descriptions.
 *
 * DESIGN PRINCIPLES:
 * - Parser owns syntax only, never semantics
 * - Never throws
 * - Never mutates input
 * - Ignores meaning of section names and keys
 * - Deterministic output for hashing
 *
 * SUPPORTED FORMAT:
 *
 * [Section]
 * key=value
 * key = value
 *
 * Blank lines and comments (# or ;) are ignored.
 * Values are scalars only.
 *
 * EXAMPLE:
 *
 * [Settings]
 * enabled=true
 * repeat=immediate
 * stopType=hard
 *
 * [SymbolicTime]
 * start=Dusk
 * startOffset=-60
 */
final class IniMetadata
{
    /**
     * Parse metadata from a calendar description field.
     *
     * @param string|null $description
     * @return array<string,array<string,mixed>>
     */
    public static function fromDescription(?string $description): array
    {
        if ($description === null) {
            return [];
        }

        $description = trim($description);
        if ($description === '') {
            return [];
        }

        // Normalize escaped newlines from ICS sources
        if (str_contains($description, "\\n") && !str_contains($description, "\n")) {
            $description = str_replace(["\\r\\n", "\\n", "\\r"], "\n", $description);
        }

        return self::parseIni($description);
    }

    /**
     * Core INI parser.
     *
     * @param string $text
     * @return array<string,array<string,mixed>>
     */
    private static function parseIni(string $text): array
    {
        $out = [];
        $section = null;

        $lines = preg_split('/\r?\n/', $text);
        if (!$lines) {
            return [];
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) {
                continue;
            }

            // Section header
            if (preg_match('/^\[(.+)]$/', $line, $m)) {
                $section = strtolower(trim($m[1]));
                if ($section !== '') {
                    $out[$section] ??= [];
                } else {
                    $section = null;
                }
                continue;
            }

            // Key/value
            if ($section !== null && str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);

                if ($key === '') {
                    continue;
                }

                $out[$section][$key] = self::normalizeScalar($value);
            }
        }

        return $out;
    }

    /**
     * Normalize scalar values deterministically.
     */
    private static function normalizeScalar(string $value)
    {
        if ($value === '') {
            return '';
        }

        if (ctype_digit($value) || (str_starts_with($value, '-') && ctype_digit(substr($value, 1)))) {
            return (int)$value;
        }

        $lv = strtolower($value);
        if ($lv === 'true') {
            return true;
        }
        if ($lv === 'false') {
            return false;
        }

        return $value;
    }
}
