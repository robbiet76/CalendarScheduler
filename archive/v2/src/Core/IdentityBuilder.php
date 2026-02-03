<?php
declare(strict_types=1);

namespace GoogleCalendarScheduler\Core;

final class IdentityBuilder
{
    private IdentityCanonicalizer $canonicalizer;
    private IdentityHasher $hasher;

    public function __construct(IdentityCanonicalizer $canonicalizer, IdentityHasher $hasher)
    {
        $this->canonicalizer = $canonicalizer;
        $this->hasher = $hasher;
    }

    /**
     * Build a stable identityHash from the identity-defining fields.
     *
     * @param 'playlist'|'sequence'|'command' $type
     * @param array<string,mixed> $timing
     */
    public function build(string $type, string $target, array $timing): string
    {
        // Fail fast with a crisp error if caller violates contract
        if (!is_array($timing)) {
            throw new \RuntimeException('IdentityBuilder::build() requires timing array');
        }

        $identity = [
            'type' => $type,
            'target' => $target,
            'timing' => $timing,
        ];

        $canonical = $this->canonicalizer->canonicalize($identity);
        return $this->hasher->hash($canonical);
    }

    /**
     * Build and return the canonical (unhashed) identity array.
     *
     * This is used by identity-keyed manifest storage.
     *
     * @param 'playlist'|'sequence'|'command' $type
     * @param string $target
     * @param array<string,mixed> $timing
     * @return array<string,mixed>
     */
    public function buildCanonical(string $type, string $target, array $timing): array
    {
        if (!is_array($timing)) {
            throw new \RuntimeException('IdentityBuilder::buildCanonical() requires timing array');
        }

        $identity = [
            'type' => $type,
            'target' => $target,
            'timing' => $timing,
        ];

        return $this->canonicalizer->canonicalize($identity);
    }
}
