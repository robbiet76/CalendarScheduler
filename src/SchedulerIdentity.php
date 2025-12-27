<?php
declare(strict_types=1);

/**
 * Scheduler identity helper.
 *
 * Phase 17+ identity:
 * - Identity is derived from GCS v1 tag stored in args[]
 * - Tag format (unchanged):
 *     |GCS:v1|uid=<uid>|range=<start..end>|days=<shortdays>
 *
 * Canonical identity value used by diff/apply:
 * - UID string
 *
 * Back-compat:
 * - extractKey() is an alias of extractUid()
 */
final class GcsSchedulerIdentity
{
    public const TAG_MARKER = '|GCS:v1|';

    /**
     * Canonical: Extract UID from a scheduler entry (desired or existing).
     *
     * @param array<string,mixed> $entry
     */
    public static function extractUid(array $entry): ?string
    {
        $tag = self::extractTag($entry);
        if ($tag === null) {
            return null;
        }

        if (!preg_match('/uid=([^|]+)/', $tag, $m)) {
            return null;
        }

        $uid = $m[1] ?? '';
        return ($uid !== '') ? $uid : null;
    }

    /**
     * Back-compat alias: historically used "key". In Phase 17+ this is the UID.
     *
     * @param array<string,mixed> $entry
     */
    public static function extractKey(array $entry): ?string
    {
        return self::extractUid($entry);
    }

    /**
     * True if entry contains a GCS v1 tag in args[].
     *
     * @param array<string,mixed> $entry
     */
    public static function isGcsManaged(array $entry): bool
    {
        return self::extractTag($entry) !== null;
    }

    /**
     * Locate the raw GCS v1 tag in args[].
     *
     * @param array<string,mixed> $entry
     */
    private static function extractTag(array $entry): ?string
    {
        $args = $entry['args'] ?? null;
        if (!is_array($args)) {
            return null;
        }

        foreach ($args as $a) {
            if (!is_string($a)) {
                continue;
            }
            if (strpos($a, self::TAG_MARKER) !== false) {
                return $a;
            }
        }

        return null;
    }
}
