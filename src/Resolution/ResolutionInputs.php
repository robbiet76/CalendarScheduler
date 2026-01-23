<?php
declare(strict_types=1);

namespace GoogleCalendarScheduler\Resolution;

use InvalidArgumentException;

final class ResolutionInputs
{
    /** @var array<string, ResolvableEvent> */
    public array $sourceByHash;

    /** @var array<string, ResolvableEvent> */
    public array $existingByHash;

    public ResolutionPolicy $policy;

    /** @var array */
    public array $context;

    private function __construct(
        array $sourceByHash,
        array $existingByHash,
        ResolutionPolicy $policy,
        array $context
    ) {
        $this->sourceByHash   = $sourceByHash;
        $this->existingByHash = $existingByHash;
        $this->policy         = $policy;
        $this->context        = $context;
    }

    /**
     * Option A: manifest-to-manifest resolution.
     *
     * @param array $sourceManifest    CalendarSnapshot manifest (expects ['events'] map)
     * @param array $existingManifest  Scheduler manifest (expects ['events'] map)
     */
    public static function fromManifests(
        array $sourceManifest,
        array $existingManifest,
        ?ResolutionPolicy $policy = null,
        array $context = []
    ): self {
        $policy = $policy ?? new ResolutionPolicy();

        if (!isset($sourceManifest['events']) || !is_array($sourceManifest['events'])) {
            throw new InvalidArgumentException('Source manifest missing events map');
        }
        if (!isset($existingManifest['events']) || !is_array($existingManifest['events'])) {
            throw new InvalidArgumentException('Existing manifest missing events map');
        }

        $sourceByHash = self::indexManifestEvents($sourceManifest['events']);
        $existingByHash = self::indexManifestEvents($existingManifest['events']);

        return new self($sourceByHash, $existingByHash, $policy, $context);
    }

    /**
     * @param array $eventsMap map<string, array> (manifest.events)
     * @return array<string, ResolvableEvent>
     */
    private static function indexManifestEvents(array $eventsMap): array
    {
        $out = [];
        foreach ($eventsMap as $key => $event) {
            if (!is_array($event)) {
                throw new InvalidArgumentException('Manifest events map must contain event objects');
            }

            // Prefer actual event id/hash over map key; but accept either.
            if (!isset($event['id']) && is_string($key) && $key !== '') {
                $event['id'] = $key;
            }

            $re = ResolvableEvent::fromManifestEvent($event);
            $out[$re->identityHash] = $re;
        }
        return $out;
    }
}