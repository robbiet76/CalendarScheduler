<?php
declare(strict_types=1);

/**
 * Scheduler identity helper.
 *
 * Stored tag format (args[]):
 *   |GCS:v1|uid=<uid>|range=<start..end>|days=<shortdays>
 *
 * Canonical identity key:
 *   gcs:v1:<uid>
 *
 * IMPORTANT:
 * - Identity is UID-only. range/days are metadata and may change.
 */
final class GcsSchedulerIdentity
{
    public const TAG_MARKER = '|GCS:v1|';
    public const KEY_PREFIX = 'gcs:v1:';

    /**
     * Extract canonical identity key from a scheduler entry.
     *
     * @param array<string,mixed> $entry
     */
    public static function extractKey(array $entry): ?string
    {
        $uid = self::extractUid($entry);
        if ($uid === null) return null;
        return self::KEY_PREFIX . $uid;
    }

    /**
     * Extract UID from args[] tag.
     *
     * @param array<string,mixed> $entry
     */
    public static function extractUid(array $entry): ?string
    {
        $args = $entry['args'] ?? null;
        if (!is_array($args)) {
            return null;
        }

        foreach ($args as $a) {
            if (!is_string($a)) continue;
            if (strpos($a, self::TAG_MARKER) === false) continue;

            // Expected: |GCS:v1|uid=<uid>|range=...|days=...
            if (preg_match('/\|GCS:v1\|uid=([^|]+)/', $a, $m) === 1) {
                $uid = (string)$m[1];
                return ($uid !== '') ? $uid : null;
            }
        }

        return null;
    }
}
