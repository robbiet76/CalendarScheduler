<?php
declare(strict_types=1);

/**
 * SchedulerIdentity
 *
 * Canonical identity and ownership helper for scheduler entries.
 *
 * Ownership rules (Phase 29+):
 * - A scheduler entry is considered GCS-managed if and ONLY if
 *   args[] contains the internal GCS ownership tag:
 *
 *     |GCS:v1|<uid>
 *
 * - args[] may contain arbitrary values (command arguments, other plugins)
 * - meta[] MUST NOT be used for ownership detection
 * - DISPLAY markers (e.g. |M|) are OPTIONAL and NON-AUTHORITATIVE
 *
 * Identity rules:
 * - Identity is UID ONLY
 * - Planner semantics (ranges, days, ordering) MUST NOT leak
 *   into scheduler identity or apply logic
 *
 * Tag format (internal, authoritative):
 *   |GCS:v1|<uid>
 *
 * Optional display prefix (ignored by logic):
 *   |M|
 *
 * This class is the single source of truth for:
 * - scheduler ownership
 * - identity extraction
 * - tag construction
 */
final class SchedulerIdentity
{
    /**
     * Optional human-visible managed marker (non-authoritative)
     */
    public const DISPLAY_TAG = '|M|';

    /**
     * Internal ownership/version marker (AUTHORITATIVE)
     */
    public const INTERNAL_TAG = '|GCS:v1|';

    /**
     * Extract the canonical GCS identity key (UID) from a scheduler entry.
     *
     * @param array<string,mixed> $entry
     * @return string|null UID if managed, otherwise null
     */
    public static function extractKey(array $entry): ?string
    {
        if (!isset($entry['args']) || !is_array($entry['args'])) {
            return null;
        }

        foreach ($entry['args'] as $arg) {
            if (!is_string($arg)) {
                continue;
            }

            // Look for authoritative INTERNAL tag anywhere in the arg
            $pos = strpos($arg, self::INTERNAL_TAG);
            if ($pos === false) {
                continue;
            }

            $uid = substr($arg, $pos + strlen(self::INTERNAL_TAG));
            return ($uid !== '') ? $uid : null;
        }

        return null;
    }

    /**
     * Compatibility alias for extractKey().
     *
     * @deprecated Use extractKey() instead.
     */
    public static function extractUid(array $entry): ?string
    {
        return self::extractKey($entry);
    }

    /**
     * Determine whether a scheduler entry is managed by GCS.
     *
     * IMPORTANT:
     * - Only INTERNAL_TAG is authoritative
     * - DISPLAY_TAG and meta[] are ignored
     */
    public static function isGcsManaged(array $entry): bool
    {
        return self::extractKey($entry) !== null;
    }

    /**
     * Build args[] tag for a managed scheduler entry.
     *
     * The display marker is included for human visibility but
     * MUST NOT be relied upon by logic.
     *
     * @param string $uid Canonical calendar UID
     * @return string Tag suitable for args[]
     */
    public static function buildArgsTag(string $uid): string
    {
        return self::DISPLAY_TAG . self::INTERNAL_TAG . $uid;
    }
}