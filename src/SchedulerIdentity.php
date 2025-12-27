<?php

/**
 * Scheduler identity helper.
 *
 * IDENTITY RULE (Phase 17+):
 * - Prefer canonical v1 tag stored in args[]:
 *   |GCS:v1|uid=<uid>|range=<start..end>|days=<shortdays>
 *
 * Legacy compatibility:
 * - Older code may have used $entry['tag'] with:
 *   gcs:v1:<uid>
 *
 * Phase 17.2 unifies identity extraction here so callers do not implement their own parsing.
 */
final class GcsSchedulerIdentity
{
    /**
     * Legacy tag prefix (deprecated): gcs:v1:<uid>
     */
    public const TAG_PREFIX = 'gcs:v1:';

    /**
     * Canonical v1 prefix stored in args[]:
     * |GCS:v1|uid=...|range=...|days=...
     */
    public const V1_PREFIX = '|GCS:v1|';

    /**
     * Extract canonical v1 identity key from a scheduler entry (existing or desired).
     *
     * Key format (exactly the substring after V1_PREFIX):
     *   uid=<uid>|range=<start..end>|days=<shortdays>
     *
     * @param array<string,mixed> $entry
     */
    public static function extractKey(array $entry): ?string
    {
        // Canonical: stored in args[]
        $v1 = self::findV1TagInArgs($entry);
        if ($v1 !== null) {
            // Return substring after prefix to form a stable map key
            $key = substr($v1, strlen(self::V1_PREFIX));
            return ($key !== '') ? $key : null;
        }

        // Legacy: stored in tag field (uid-only)
        $uid = self::extractLegacyUid($entry);
        if ($uid !== null) {
            // Legacy entries cannot supply range/days; return a key that is stable for uid-only
            return 'uid=' . $uid;
        }

        return null;
    }

    /**
     * Extract UID (best-effort) from entry.
     *
     * - If v1 args tag exists, parse uid=<uid> from it.
     * - Else fallback to legacy tag field (gcs:v1:<uid>).
     *
     * @param array<string,mixed> $entry
     */
    public static function extractUid(array $entry): ?string
    {
        $v1 = self::findV1TagInArgs($entry);
        if ($v1 !== null) {
            $parts = self::parseV1Tag($v1);
            $uid = $parts['uid'] ?? null;
            return (is_string($uid) && $uid !== '') ? $uid : null;
        }

        return self::extractLegacyUid($entry);
    }

    /**
     * Parse canonical v1 tag into parts.
     *
     * Input: |GCS:v1|uid=...|range=...|days=...
     *
     * Output:
     *   ['uid' => '...', 'range' => 'YYYY-MM-DD..YYYY-MM-DD', 'days' => 'Sa']
     *
     * @return array<string,string>
     */
    public static function parseV1Tag(string $tag): array
    {
        if (strpos($tag, self::V1_PREFIX) !== 0) {
            return [];
        }

        $rest = substr($tag, strlen(self::V1_PREFIX));
        if ($rest === '') {
            return [];
        }

        $out = [];
        $chunks = explode('|', $rest);

        foreach ($chunks as $chunk) {
            if ($chunk === '') continue;

            $pos = strpos($chunk, '=');
            if ($pos === false) continue;

            $k = substr($chunk, 0, $pos);
            $v = substr($chunk, $pos + 1);

            $k = trim((string)$k);
            $v = trim((string)$v);

            if ($k === '' || $v === '') continue;

            $out[$k] = $v;
        }

        return $out;
    }

    /**
     * Find canonical v1 tag string in entry args[].
     *
     * @param array<string,mixed> $entry
     */
    private static function findV1TagInArgs(array $entry): ?string
    {
        $args = $entry['args'] ?? null;
        if (!is_array($args)) {
            return null;
        }

        foreach ($args as $a) {
            if (!is_string($a)) continue;

            // allow tag to appear at start (canonical) or embedded (defensive)
            if (strpos($a, self::V1_PREFIX) === 0) {
                return $a;
            }
            if (strpos($a, self::V1_PREFIX) !== false) {
                // If embedded, attempt to extract starting at prefix
                $pos = strpos($a, self::V1_PREFIX);
                $sub = substr($a, (int)$pos);
                return ($sub !== '') ? $sub : null;
            }
        }

        return null;
    }

    /**
     * Extract UID from legacy tag field format: gcs:v1:<uid>
     *
     * @param array<string,mixed> $entry
     */
    private static function extractLegacyUid(array $entry): ?string
    {
        $tag = $entry['tag'] ?? null;
        if (!is_string($tag)) {
            return null;
        }

        if (strpos($tag, self::TAG_PREFIX) !== 0) {
            return null;
        }

        $uid = substr($tag, strlen(self::TAG_PREFIX));
        return $uid !== '' ? $uid : null;
    }
}
