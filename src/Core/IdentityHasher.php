<?php
declare(strict_types=1);

namespace GoogleCalendarScheduler\Core;

/**
 * IdentityHasher
 *
 * Produces a deterministic hash for a *canonical identity structure*.
 *
 * IMPORTANT:
 * - Hashers must NOT accept raw identity input.
 * - Hashers must require canonicalized input produced by IdentityCanonicalizer.
 *
 * Rationale:
 * - Prevents drift (multiple places "kind of" canonicalize).
 * - Ensures hash stability across runs and environments.
 */
interface IdentityHasher
{
    /**
     * Hash a canonicalized identity array.
     *
     * @param array $canonicalIdentity MUST be the output of IdentityCanonicalizer::canonicalize()
     */
    public function hash(array $canonicalIdentity): string;
}

