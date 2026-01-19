<?php
declare(strict_types=1);

namespace GCS\Core;

/**
 * IdentityCanonicalizer (Authoritative)
 *
 * Canonical identity fields (identity-defining):
 * - type
 * - target
 * - timing.days
 * - timing.start_time
 * - timing.end_time
 *
 * Explicitly excluded (non-identity / mutable / execution-specific):
 * - stopType, repeat, enabled flags
 * - ordering/index/position
 * - provider UID
 * - derived ids/hashes
 * - any date fields (start_date/end_date, DatePattern, symbolic date tokens, etc.)
 *
 * NOTE (v2.3):
 * - Start/end dates exist in intent + date pattern fields, but are NOT identity.
 * - Identity must be invariant under date-resolution changes.
 */
final class IdentityCanonicalizer
{
    /**
     * Canonicalize a semantic IdentityObject for hashing.
     *
     * - Validates required fields exist
     * - Enforces forbidden fields are absent
     * - Produces stable key ordering (recursive ksort)
     *
     * @param array $identity Raw identity object (semantic identity only)
     * @return array Canonical identity
     */
    public static function canonicalize(array $identity): array
    {
        if ($identity === []) {
            throw IdentityInvariantViolation::fail(
                IdentityInvariantViolation::IDENTITY_MISSING,
                'Identity is missing or empty'
            );
        }

        // Required fields
        foreach (['type', 'target', 'timing'] as $k) {
            if (!array_key_exists($k, $identity)) {
                throw IdentityInvariantViolation::fail(
                    IdentityInvariantViolation::IDENTITY_REQUIRED_FIELD_MISSING,
                    "Identity missing required field: {$k}",
                    ['missing' => $k]
                );
            }
        }

        // type validation
        $type = $identity['type'];
        if (!is_string($type) || !in_array($type, ['playlist', 'command', 'sequence'], true)) {
            throw IdentityInvariantViolation::fail(
                IdentityInvariantViolation::IDENTITY_TYPE_INVALID,
                'Identity.type must be one of: playlist | command | sequence',
                ['type' => $type]
            );
        }

        // timing validation
        if (!is_array($identity['timing'])) {
            throw IdentityInvariantViolation::fail(
                IdentityInvariantViolation::IDENTITY_TIMING_INVALID,
                "Identity.timing must be an object/array",
                ['timing' => $identity['timing']]
            );
        }
        foreach (['days', 'start_time', 'end_time'] as $k) {
            if (!array_key_exists($k, $identity['timing'])) {
                throw IdentityInvariantViolation::fail(
                    IdentityInvariantViolation::IDENTITY_REQUIRED_FIELD_MISSING,
                    "Identity.timing missing required field: {$k}",
                    ['missing' => "timing.{$k}"]
                );
            }
        }

        // Forbidden keys at top-level (and common forbidden timing keys)
        $forbiddenTop = ['start_date', 'end_date', 'date_pattern', 'stopType', 'repeat', 'enabled', 'status', 'uid', 'hash', 'id'];
        foreach ($forbiddenTop as $k) {
            if (array_key_exists($k, $identity)) {
                throw IdentityInvariantViolation::fail(
                    IdentityInvariantViolation::IDENTITY_FORBIDDEN_FIELD_PRESENT,
                    "Identity includes forbidden field: {$k}",
                    ['field' => $k]
                );
            }
        }

        // Ensure no date fields inside timing
        foreach (['start_date', 'end_date', 'date_pattern', 'startDate', 'endDate'] as $k) {
            if (array_key_exists($k, $identity['timing'])) {
                throw IdentityInvariantViolation::fail(
                    IdentityInvariantViolation::IDENTITY_FORBIDDEN_FIELD_PRESENT,
                    "Identity.timing includes forbidden field: {$k}",
                    ['field' => "timing.{$k}"]
                );
            }
        }

        // Stable key ordering
        $canonical = $identity;
        self::ksortRecursive($canonical);

        return $canonical;
    }

    /**
     * @param array<mixed> $data
     */
    private static function ksortRecursive(array &$data): void
    {
        ksort($data);
        foreach ($data as &$v) {
            if (is_array($v)) {
                self::ksortRecursive($v);
            }
        }
    }
}

