<?php
declare(strict_types=1);

namespace GoogleCalendarScheduler\Core;

final class IdentityBuilder
{
    private IdentityCanonicalizer $canonicalizer;
    private IdentityHasher $hasher;

    public function __construct(
        $canonicalizerOrHasher = null,
        ?IdentityHasher $hasher = null
    ) {
        $canonicalizer = null;

        // Back-compat convenience: allow passing hasher as the first argument.
        if ($canonicalizerOrHasher instanceof IdentityHasher) {
            $hasher = $canonicalizerOrHasher;
        } elseif ($canonicalizerOrHasher instanceof IdentityCanonicalizer) {
            $canonicalizer = $canonicalizerOrHasher;
        } elseif ($canonicalizerOrHasher !== null) {
            throw new \InvalidArgumentException(
                'IdentityBuilder::__construct expects IdentityCanonicalizer or IdentityHasher as the first argument'
            );
        }

        $this->canonicalizer = $canonicalizer ?? new IdentityCanonicalizer();
        $this->hasher        = $hasher ?? new Sha256IdentityHasher();
    }

    /**
     * Build a deterministic identity for a single event + subEvent pair.
     *
     * @param array<string,mixed> $event
     * @param array<string,mixed> $subEvent
     */
    public function build(array $event, array $subEvent): string
    {
        $identityMaterial = [
            'type'   => $event['type']   ?? null,
            'target' => $event['target'] ?? null,

            // SubEvent defines executable intent
            'timing'   => $subEvent['timing']   ?? null,
            'behavior' => $subEvent['behavior'] ?? null,
            'payload'  => $subEvent['payload']  ?? null,
        ];

        // Canonicalize intent (ordering, null handling, normalization)
        $canonical = $this->canonicalizer->canonicalize($identityMaterial);

        // Hash canonical intent
        return $this->hasher->hash($canonical);
    }
}