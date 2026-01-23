<?php
declare(strict_types=1);

namespace GoogleCalendarScheduler\Resolution;

/**
 * Normalized event-level object used exclusively by the resolver.
 * This is NOT a ManifestEvent and is never persisted.
 */
final class ResolvableEvent
{
    public static function fromCalendarEvent(
        array $calendarEvent,
        array $canonicalIdentity,
        string $identityHash,
        bool $managed,
        ?string $externalKey = null
    ): self {
        if (!isset($calendarEvent['type'], $calendarEvent['target'], $calendarEvent['timing'])) {
            throw new \InvalidArgumentException('Calendar event missing required fields');
        }

        $baseSubEvent = [
            'timing'        => $calendarEvent['timing'],
            'behavior'      => $calendarEvent['behavior'] ?? [],
            'payload'       => $calendarEvent['payload'] ?? null,
            'identity'      => $canonicalIdentity,
            'identity_hash' => $identityHash,
        ];

        $event = $calendarEvent;
        $event['subEvents'] = [$baseSubEvent];

        return new self(
            $identityHash,
            $canonicalIdentity,
            $event,
            'calendar',
            $managed,
            $externalKey
        );
    }

    public static function fromManifestEvent(
        array $manifestEvent,
        bool $managed
    ): self {
        if (!isset($manifestEvent['id'], $manifestEvent['identity'])) {
            throw new \InvalidArgumentException('Manifest event missing identity or id');
        }

        $identityHash = $manifestEvent['id'];
        $identity     = $manifestEvent['identity'];

        return new self(
            $identityHash,
            $identity,
            $manifestEvent,
            'manifest',
            $managed,
            $manifestEvent['correlation']['externalId'] ?? null
        );
    }

    public static function fromFppScheduleEntry(
        array $scheduleEntry,
        array $canonicalIdentity,
        string $identityHash,
        bool $managed
    ): self {
        $baseSubEvent = [
            'timing'        => $canonicalIdentity['timing'],
            'behavior'      => $scheduleEntry['behavior'] ?? [],
            'payload'       => $scheduleEntry['payload'] ?? null,
            'identity'      => $canonicalIdentity,
            'identity_hash' => $identityHash,
        ];

        $event = [
            'type'      => $canonicalIdentity['type'],
            'target'    => $canonicalIdentity['target'],
            'timing'    => $canonicalIdentity['timing'],
            'subEvents' => [$baseSubEvent],
        ];

        return new self(
            $identityHash,
            $canonicalIdentity,
            $event,
            'fpp',
            $managed
        );
    }

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

    private function __construct(
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