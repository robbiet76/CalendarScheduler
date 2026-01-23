<?php
declare(strict_types=1);

namespace GoogleCalendarScheduler\Resolution;

/**
 * Normalized event-level object used exclusively by the resolver.
 * This is NOT a ManifestEvent and is never persisted.
 */
final class ResolvableEvent
{
    public string $identityHash;

    /** Canonical identity object (NO id field) */
    public array $identity;

    /** Fully normalized manifest-style event shape */
    public array $event;

    /** calendar | fpp | manifest */
    public string $source;

    /** Optional external correlation key (calendar UID, etc) */
    public ?string $externalKey;

    /** Derived from ownership */
    public bool $managed;

    /** Non-fatal warnings attached during normalization */
    public array $warnings = [];

    public function __construct(
        string $identityHash,
        array $identity,
        array $event,
        string $source,
        bool $managed,
        ?string $externalKey = null
    ) {
        $this->identityHash = $identityHash;
        $this->identity     = $identity;
        $this->event        = $event;
        $this->source       = $source;
        $this->managed      = $managed;
        $this->externalKey  = $externalKey;
    }
}