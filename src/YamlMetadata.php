<?php

/**
 * YAML metadata parser and schema validator.
 *
 * PHASE 11 CONTRACT:
 * - Schema is explicit and locked
 * - Unknown keys generate warnings
 * - Invalid value types generate warnings
 * - No silent ignores
 * - Behavior remains backward compatible
 */
final class GcsYamlMetadata
{
    /**
     * Allowed schema and expected value types.
     *
     * NOTE:
     * - This is intentionally conservative.
     * - New keys must be explicitly added here.
     */
    private const SCHEMA = [
        // Core scheduler controls
        'type'       => 'string', // playlist | sequence | command
        'stopType'   => 'string', // graceful | hard
        'repeat'     => 'string', // none | 5 | 10 | 15 | etc.
        'override'   => 'bool',

        // Command-only fields
        'command'          => 'string',
        'args'             => 'array',
        'multisyncCommand' => 'bool',
    ];

    /**
     * Parse YAML metadata from an event description.
     *
     * @param string|null $text
     * @return array<string,mixed>
     */
    public static function parse(?string $text): array
    {
        if ($text === null || trim($text) === '') {
            return [];
        }

        // Look for fenced YAML block or inline YAML
        $yamlText = self::extractYaml($text);
        if ($yamlText === null) {
            return [];
        }

        // Parse YAML safely (no ext-yaml dependency)
        $parsed = self::parseSimpleYaml($yamlText);
        if (!is_array($parsed)) {
            GcsLog::warn('Invalid YAML metadata (parse failed)', [
                'yaml' => $yamlText,
            ]);
            return [];
        }

        $out = [];

        foreach ($parsed as $key => $value) {
            if (!array_key_exists($key, self::SCHEMA)) {
                GcsLog::warn('Unknown YAML key ignored', [
                    'key' => $key,
                ]);
                continue;
            }

            $expected = self::SCHEMA[$key];
            if (!self::isValidType($value, $expected)) {
                GcsLog::warn('Invalid YAML value type', [
                    'key'      => $key,
                    'expected' => $expected,
                    'actual'   => gettype($value),
                ]);
                continue;
            }

            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * Extract YAML from description text.
     *
     * Supports:
     * - ```yaml fenced blocks
     * - Inline YAML starting with "fpp:"
     */
    private static function extractYaml(string $text): ?string
    {
        // Fenced YAML block
        if (preg_match('/```yaml(.*?)```/s', $text, $m)) {
            return trim($m[1]);
        }

        // Inline YAML (legacy support)
        $pos = strpos($text, 'fpp:');
        if ($pos !== false) {
            return substr($text, $pos);
        }

        return null;
    }

    /**
     * Extremely small YAML parser sufficient for our schema.
     *
     * Supports:
     * - key: value
     * - key:
     *     - list items
     */
    private static function parseSimpleYaml(string $yaml): array
    {
        $lines = preg_split('/\r?\n/', $yaml);
        $data  = [];
        $currentKey = null;

        foreach ($lines as $line) {
            $line = rtrim($line);
            if ($line === '' || str_starts_with(trim($line), '#')) {
                continue;
            }

            // List item
            if ($currentKey !== null && preg_match('/^\s*-\s*(.+)$/', $line, $m)) {
                if (!isset($data[$currentKey]) || !is_array($data[$currentKey])) {
                    $data[$currentKey] = [];
                }
                $data[$currentKey][] = self::castValue($m[1]);
                continue;
            }

            // key: value
            if (preg_match('/^([a-zA-Z0-9_]+)\s*:\s*(.*)$/', $line, $m)) {
                $key = $m[1];
                $val = $m[2];

                if ($val === '') {
                    // Start of list or nested block
                    $data[$key] = [];
                    $currentKey = $key;
                } else {
                    $data[$key] = self::castValue($val);
                    $currentKey = null;
                }
            }
        }

        return $data;
    }

    /**
     * Cast scalar YAML values into native PHP types.
     *
     * @param string $val
     * @return mixed
     */
    private static function castValue(string $val)
    {
        $v = trim($val);

        if ($v === 'true') {
            return true;
        }
        if ($v === 'false') {
            return false;
        }
        if (is_numeric($v)) {
            return (int)$v;
        }

        return $v;
    }

    /**
     * Validate a value against an expected schema type.
     */
    private static function isValidType($value, string $expected): bool
    {
        return match ($expected) {
            'string' => is_string($value),
            'bool'   => is_bool($value),
            'array'  => is_array($value),
            default  => false,
        };
    }
}
