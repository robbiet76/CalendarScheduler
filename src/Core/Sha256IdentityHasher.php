<?php
declare(strict_types=1);

namespace GCS\Core;

/**
 * Sha256IdentityHasher
 *
 * Hashes canonical identity arrays via SHA-256.
 *
 * Guard:
 * - We do a *small* sanity check to ensure the array is plausibly canonical
 *   (top-level key order).
 * - We do NOT attempt to canonicalize here. Canonicalization is a separate step.
 */
final class Sha256IdentityHasher implements IdentityHasher
{
    public function hash(array $canonicalIdentity): string
    {
        // Small guard: ensure top-level keys are already sorted.
        $keys = array_keys($canonicalIdentity);
        $sorted = $keys;
        sort($sorted);
        if ($keys !== $sorted) {
            throw IdentityInvariantViolation::fail(
                IdentityInvariantViolation::IDENTITY_CANONICALIZATION_FAILED,
                'Hasher requires canonical identity input (sorted keys).',
                ['keys' => $keys]
            );
        }

        $json = json_encode($canonicalIdentity, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw IdentityInvariantViolation::fail(
                IdentityInvariantViolation::IDENTITY_CANONICALIZATION_FAILED,
                'Failed to encode canonical identity to JSON.'
            );
        }

        return hash('sha256', $json);
    }
}

