<?php
declare(strict_types=1);

namespace GoogleCalendarScheduler\Platform;

/**
 * YamlMetadata (V2)
 *
 * Lightweight, read-only YAML metadata extractor for calendar events.
 *
 * RESPONSIBILITIES:
 * - Extract scheduler metadata embedded in event descriptions
 * - Parse a deterministic, limited YAML subset safely
 * - Normalize values for downstream scheduler logic
 *
 * DESIGN GOALS:
 * - Never throw
 * - Never mutate input
 * - No scheduler knowledge
 * - No I/O
 *
 * SUPPORTED YAML (INTENTIONALLY LIMITED):
 * - Flat key: value pairs
 * - One-level nested maps using indentation (e.g. command metadata)
 * - Scalar values only (string, int, bool)
 * - Explicit `type` field is authoritative (playlist | sequence | command)
 *
 * EXPLICITLY NOT SUPPORTED:
 * - Arrays / lists
 * - Multiline scalars
 * - Anchors, tags, or advanced YAML features
 *
 * If parsing fails or metadata is invalid, an empty array is returned.
 */
final class YamlMetadata
{
    /**
     * Alias for parse(), used by CalendarTranslator.
     *
     * @param string|null $description
     * @return array<string,mixed>
     */
    public static function fromDescription(?string $description): array
    {
        return self::parse($description);
    }
    /**
     * Parse YAML metadata from a calendar event description.
     *
     * @param string|null $description Raw description text from calendar event
     * @param array<string,mixed> $context Optional context (reserved for debug/logging)
     * @return array<string,mixed> Parsed metadata or empty array
     */
    public static function parse(?string $description, array $context = []): array
    {
        if ($description === null) {
            return [];
        }

        $description = trim($description);
        // Some ICS sources may preserve newlines as the two-character sequence "\\n".
        // Normalize those into real line breaks so YAML extraction works deterministically.
        if (str_contains($description, "\\n") && !str_contains($description, "\n")) {
            $description = str_replace(["\\r\\n", "\\n", "\\r"], ["\n", "\n", "\n"], $description);
        }
        if ($description === '') {
            return [];
        }

        $yamlText = self::extractYamlBlock($description);
        if ($yamlText === null) {
            return [];
        }

        try {
            $parsed = self::normalizeKeys(self::parseYamlBlock($yamlText));
            if (empty($parsed)) {
                return [];
            }

            return self::normalize($parsed);
        } catch (\Throwable) {
            // Parsing metadata must never break scheduler behavior
            return [];
        }
    }

    /**
     * Extract a YAML block from description text.
     *
     * Supported formats:
     *
     * 1) Fenced block:
     *    ```yaml
     *    stopType: graceful
     *    repeat: immediate
     *    ```
     *
     * 2) Raw YAML at top of description:
     *    stopType: graceful
     *    repeat: immediate
     *
     * @return string|null
     */
    private static function extractYamlBlock(string $text): ?string
    {
        $lines = preg_split('/\r?\n/', $text);
        if (!$lines) {
            return null;
        }

        $start = null;
        $end = null;
        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            if (trim($lines[$i]) === '---') {
                $start = $i;
                break;
            }
        }

        if ($start === null) {
            return null;
        }

        for ($j = $start + 1; $j < $count; $j++) {
            if (trim($lines[$j]) === '---') {
                $end = $j;
                break;
            }
        }

        if ($end === null || $end <= $start + 1) {
            return null;
        }

        $blockLines = array_slice($lines, $start + 1, $end - $start - 1);

        // Trim trailing empty lines
        while (!empty($blockLines) && trim(end($blockLines)) === '') {
            array_pop($blockLines);
        }

        if (empty($blockLines)) {
            return null;
        }

        return implode("\n", $blockLines);
    }

    /**
     * Parse a limited YAML block.
     *
     * Supports:
     * - Flat key/value pairs
     * - One-level nested maps via indentation
     *
     * @param string $raw
     * @return array<string,mixed>
     */
    private static function parseYamlBlock(string $raw): array
    {
        $out = [];
        $currentParent = null;

        $lines = preg_split('/\r?\n/', $raw);
        if (!$lines) {
            return $out;
        }

        foreach ($lines as $line) {
            $line = rtrim($line);

            // Skip blanks and comments
            if ($line === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }

            // Count indentation (spaces only)
            preg_match('/^(\s*)/', $line, $m);
            $indent = strlen($m[1]);
            $trimmed = ltrim($line);
            $line = $trimmed;

            // Top-level key
            if ($indent === 0) {
                $currentParent = null;

                if (!str_contains($trimmed, ':')) {
                    continue;
                }

                [$key, $value] = explode(':', $trimmed, 2);
                $key = trim($key);
                $value = trim($value);

                if ($key === '') {
                    continue;
                }

                // Parent map (e.g. "start:")
                if ($value === '') {
                    $out[$key] = [];
                    $currentParent = $key;
                    continue;
                }

                // Scalar value
                $out[$key] = self::normalizeScalar($value);
                continue;
            }

            // Nested key (one level only)
            if ($indent > 0 && $currentParent !== null) {
                if (!str_contains($trimmed, ':')) {
                    continue;
                }

                [$childKey, $childValue] = explode(':', $trimmed, 2);
                $childKey = trim($childKey);
                $childValue = trim($childValue);

                if ($childKey === '') {
                    continue;
                }

                $out[$currentParent][$childKey] =
                    self::normalizeScalar($childValue);
            }
        }

        $clean = [];
        foreach ($out as $k => $v) {
            if (!is_string($k)) {
                continue;
            }
            $tk = trim($k);
            if ($tk === '') {
                continue;
            }
            $clean[$tk] = $v;
        }
        return $clean;
    }

    /**
     * Recursively trim all array keys.
     *
     * @param array $in
     * @return array
     */
    private static function normalizeKeys(array $in): array
    {
        $out = [];

        foreach ($in as $k => $v) {
            if (!is_string($k)) {
                continue;
            }

            $tk = trim($k);
            if ($tk === '') {
                continue;
            }

            $out[$tk] = is_array($v)
                ? self::normalizeKeys($v)
                : $v;
        }

        return $out;
    }

    /**
     * Normalize parsed metadata.
     *
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    private static function normalize(array $raw): array
    {
        $out = [];

        // Preserve all scalar keys verbatim, including `type`.
        // `type` is authoritative and must not be inferred or altered.
        foreach ($raw as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $key = trim($key);   // MUST happen first
            if ($key === '') {
                continue;
            }

            if (is_array($value)) {
                if (in_array($key, ['gcs', 'payload', 'settings'], true)) {
                    foreach ($value as $childKey => $childValue) {
                        if (!is_string($childKey)) {
                            continue;
                        }
                        $childKey = trim($childKey);
                        if ($childKey === '') {
                            continue;
                        }
                        $out[$childKey] = self::normalizeValue($childValue);
                    }
                    continue;
                } else {
                    $out[$key] = self::normalize($value);
                    continue;
                }
            }

            $out[$key] = self::normalizeValue($value);
        }

        return $out;
    }

    /**
     * Normalize a single value.
     */
    private static function normalizeValue($v)
    {
        if (is_bool($v) || is_int($v) || is_float($v)) {
            return $v;
        }

        if (is_string($v)) {
            return trim($v);
        }

        return null;
    }

    /**
     * NOTE:
     * - Lists are intentionally unsupported.
     * - Scalar strings (e.g. IPs, host lists, command args) must be preserved verbatim.
     */
    private static function normalizeScalar(string $value)
    {
        if ($value === '') {
            return '';
        }

        if (ctype_digit($value)) {
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
