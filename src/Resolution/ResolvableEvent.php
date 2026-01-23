<?php
declare(strict_types=1);

namespace GoogleCalendarScheduler\Resolution;

use InvalidArgumentException;

final class ResolvableEvent
{
    /** @var string */
    public string $identityHash;

    /** @var array */
    public array $identity;

    /** @var array */
    public array $ownership;

    /** @var array */
    public array $correlation;

    /** @var array */
    public array $subEvents;

    private function __construct(
        string $identityHash,
        array $identity,
        array $ownership,
        array $correlation,
        array $subEvents
    ) {
        $this->identityHash = $identityHash;
        $this->identity     = $identity;
        $this->ownership    = $ownership;
        $this->correlation  = $correlation;
        $this->subEvents    = $subEvents;
    }

    /**
     * Manifest event → ResolvableEvent
     *
     * Expected:
     * - id (preferred) OR identity_hash
     * - identity (array)
     * - ownership (array)
     * - correlation (array)
     * - subEvents (array)
     */
    public static function fromManifestEvent(array $event): self
    {
        $hash = null;
        if (isset($event['id']) && is_string($event['id']) && $event['id'] !== '') {
            $hash = $event['id'];
        } elseif (isset($event['identity_hash']) && is_string($event['identity_hash']) && $event['identity_hash'] !== '') {
            $hash = $event['identity_hash'];
        }

        if (!is_string($hash) || $hash === '') {
            throw new InvalidArgumentException('Manifest event missing required id/identity_hash');
        }
        if (!isset($event['identity']) || !is_array($event['identity'])) {
            throw new InvalidArgumentException('Manifest event missing required identity object');
        }

        $ownership   = (isset($event['ownership']) && is_array($event['ownership'])) ? $event['ownership'] : [];
        $correlation = (isset($event['correlation']) && is_array($event['correlation'])) ? $event['correlation'] : [];
        $subEvents   = (isset($event['subEvents']) && is_array($event['subEvents'])) ? $event['subEvents'] : [];

        return new self($hash, $event['identity'], $ownership, $correlation, $subEvents);
    }

    public function isLocked(): bool
    {
        return isset($this->ownership['locked']) && (bool)$this->ownership['locked'] === true;
    }
}