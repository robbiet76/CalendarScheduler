<?php
declare(strict_types=1);

namespace CalendarScheduler\Intent;

/**
 * RawEvent
 *
 * Canonical, source-agnostic input into IntentNormalizer.
 * Produced ONLY by adapters.
 *
 * Invariants:
 * - Fully normalized to FPP timezone
 * - No provider-specific semantics
 * - No defaults applied later
 */
final class RawEvent
{
    public function __construct(
        public readonly string $source,              // 'fpp' | 'calendar'
        public readonly string $type,                // command | playlist | sequence
        public readonly string $target,
        public readonly array  $timing,              // Canonical timing array
        public readonly array  $payload,             // Fully normalized execution payload
        public readonly array  $ownership,            // managed/controller/locked
        public readonly array  $correlation,          // raw source linkage
        public readonly int    $sourceUpdatedAt       // authority timestamp (epoch)
    ) {}
}