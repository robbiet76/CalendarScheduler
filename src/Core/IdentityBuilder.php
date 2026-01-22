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
}
