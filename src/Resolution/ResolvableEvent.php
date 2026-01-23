<?php
declare(strict_types=1);

namespace GoogleCalendarScheduler\Resolution;

/**
 * Normalized event-level object used exclusively by the resolver.
 * This is NOT a ManifestEvent and is never persisted.
 * Resolver inputs must be pre-normalized (calendar snapshot / manifest shape).
 */
final class ResolvableEvent
{
    public static function fromManifestEvent(
        array $manifestEvent,
        bool $managed
    ): self {
        if (!isset($manifestEvent['id'], $manifestEvent['identity'])) {
            throw new \InvalidArgumentException('Manifest event missing identity or id');
        }

        $identityHash = $manifestEvent['id'] ?? ($manifestEvent['identity_hash'] ?? null);
        if (!is_string($identityHash) || $identityHash === '') {
            throw new \InvalidArgumentException('Manifest event missing resolvable identity hash');
        }
        $identity = $manifestEvent['identity'];

        return new self(
            $identityHash,
            $identity,
            $manifestEvent,
            'manifest',
            $managed,
            $manifestEvent['correlation']['externalId'] ?? null
        );
    }

    /**
     * Alias for calendar snapshot normalization.
     * Required by ResolutionInputs for explicit intent.
     */
    public static function fromCalendarManifestEvent(
        array $calendarEvent,
        bool $managed = true
    ): self {
        return self::fromCalendarManifest($calendarEvent, $managed);
    }

    /**
     * Normalize an FPP schedule.json entry that already contains identity and identity_hash.
     * This is the expected V2 shape coming from CalendarSnapshot-derived schedules.
     */
    public static function fromFppScheduleEntrySimple(
        array $scheduleEntry,
        bool $managed
    ): self {
        if (!isset($scheduleEntry['identity'], $scheduleEntry['identity_hash'])) {
            throw new \InvalidArgumentException('FPP schedule entry missing identity or identity_hash');
        }

        $identity = $scheduleEntry['identity'];
        if (isset($identity['id'])) {
            throw new \InvalidArgumentException('FPP schedule identity must not include id');
        }

        $identityHash = $scheduleEntry['identity_hash'];

        $event = [
            'type'      => $identity['type'],
            'target'    => $identity['target'],
            'timing'    => $identity['timing'],
            'subEvents' => [$scheduleEntry],
        ];

        return new self(
            $identityHash,
            $identity,
            $event,
            'fpp',
            $managed
        );
    }

    public static function fromFppScheduleEntry(
        array $scheduleEntry,
        array $canonicalIdentity,
        string $identityHash,
        bool $managed
    ): self {
        if (!isset($canonicalIdentity['type'], $canonicalIdentity['target'], $canonicalIdentity['timing'])) {
            throw new \InvalidArgumentException('Canonical identity missing required fields: type, target, or timing');
        }

        $baseSubEvent = self::buildBaseSubEvent(
            $canonicalIdentity['timing'],
            $canonicalIdentity,
            $identityHash,
            $scheduleEntry['behavior'] ?? [],
            $scheduleEntry['payload'] ?? null
        );

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

    public static function fromCalendarManifest(
        array $calendarEvent,
        bool $managed
    ): self {
        if (!isset($calendarEvent['id'], $calendarEvent['identity'], $calendarEvent['subEvents'])) {
            throw new \InvalidArgumentException('Calendar manifest event missing required fields: id, identity, or subEvents');
        }

        $identityHash = $calendarEvent['id'];
        $identity = $calendarEvent['identity'];
        if (isset($identity['id'])) {
            throw new \InvalidArgumentException('Calendar manifest identity must not include id');
        }
        $externalKey = $calendarEvent['correlation']['externalId'] ?? null;

        return new self(
            $identityHash,
            $identity,
            $calendarEvent,
            'calendar',
            $managed,
            $externalKey
        );
    }

    private static function buildBaseSubEvent(array $timing, array $identity, string $identityHash, array $behavior = [], $payload = null): array
    {
        return [
            'timing'        => $timing,
            'behavior'      => $behavior,
            'payload'       => $payload,
            'identity'      => $identity,
            'identity_hash' => $identityHash,
        ];
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
        // Safety: canonical identity must never include an id field
        if (isset($identity['id'])) {
            throw new \RuntimeException('ResolvableEvent.identity must not include id');
        }

        $this->identityHash = $identityHash;
        $this->identity     = $identity;
        $this->event        = $event;
        $this->source       = $source;
        $this->managed      = $managed;
        $this->externalKey  = $externalKey;
    }
}