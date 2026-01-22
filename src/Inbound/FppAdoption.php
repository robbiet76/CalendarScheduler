<?php
declare(strict_types=1);

namespace GoogleCalendarScheduler\Inbound;

use GoogleCalendarScheduler\Platform\FppScheduleTranslator;
use GoogleCalendarScheduler\Core\FileManifestStore;
use GoogleCalendarScheduler\Core\IdentityBuilder;
use RuntimeException;

/**
 * FppAdoption
 *
 * Orchestrates adoption of existing FPP scheduler entries into the Manifest.
 *
 * RESPONSIBILITY:
 * - Read FPP schedule.json
 * - Translate each scheduler entry into exactly one Manifest SubEvent
 * - Wrap each SubEvent into a standalone Manifest Event
 * - Persist Events into the ManifestStore
 *
 * ADOPTION RULES:
 * - Each FPP scheduler entry is translated into one or more SubEvents
 * - Each SubEvent becomes its own Manifest Event
 * - Exactly one SubEvent per adopted Manifest Event
 * - No grouping, inference, or consolidation
 * - No calendar correlation
 * - No planner, diff, or apply logic
 *
 * This class is intentionally one-directional and used only during
 * initial adoption / bootstrap.
 */
final class FppAdoption
{
    private FileManifestStore $manifestStore;
    private FppScheduleTranslator $translator;
    private IdentityBuilder $identityBuilder;

    public function __construct(
        FppScheduleTranslator $translator,
        FileManifestStore $manifestStore,
        IdentityBuilder $identityBuilder
    ) {
        $this->translator    = $translator;
        $this->manifestStore = $manifestStore;
        $this->identityBuilder = $identityBuilder;
    }

    /**
     * Adopt FPP scheduler entries into the Manifest.
     *
     * @param string $schedulePath Absolute path to schedule.json
     */
    public function adopt(string $schedulePath): void
    {
        // Adoption must be 1:1 FPP entry → Manifest event
        $subEvents = $this->translator->scheduleToSubEvents($schedulePath);

        // Clear previous adopted events (replacement-style adoption)
        $manifest = $this->manifestStore->loadDraft();
        $manifest = $this->removeAllFppSourcedEvents($manifest);

        foreach ($subEvents as $subEvent) {
            if (!is_array($subEvent)) {
                continue;
            }

            $type   = $subEvent['type']   ?? null;
            $target = $subEvent['target'] ?? null;
            $timing = $subEvent['timing'] ?? null;

            if (!is_string($type) || $type === '') {
                throw new RuntimeException('Adoption subEvent missing type');
            }
            if (!is_string($target) || $target === '') {
                throw new RuntimeException('Adoption subEvent missing target');
            }
            if (!is_array($timing)) {
                throw new RuntimeException('Adoption subEvent missing timing array');
            }

            $identity = $this->identityBuilder->buildCanonical($type, $target, $timing);

            if (!isset($identity['id']) || !is_string($identity['id'])) {
                throw new RuntimeException('IdentityBuilder did not produce identity.id');
            }

            $event = [
                'id' => $identity['id'],
                'identity' => $identity,
                'type' => $type,
                'target' => $target,
                'ownership' => [
                    'managed' => false,
                    'controller' => 'manual',
                    'locked' => false,
                ],
                'correlation' => [
                    'source' => null,
                    'externalId' => null,
                ],
                'provenance' => [
                    'source' => 'fpp',
                    'provider' => null,
                    'imported_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                ],
                'subEvents' => [
                    $this->stripTypeTargetForManifest($subEvent),
                ],
            ];

            $manifest = $this->manifestStore->upsertEvent($manifest, $identity, $event);
        }

        $this->manifestStore->saveDraft($manifest);
    }

    /**
     * Remove all events that were previously adopted from FPP.
     *
     * @param array<string,mixed> $manifest
     * @return array<string,mixed>
     */
    private function removeAllFppSourcedEvents(array $manifest): array
    {
        if (!isset($manifest['events']) || !is_array($manifest['events'])) {
            return $manifest;
        }

        foreach ($manifest['events'] as $eventId => $event) {
            if (
                isset($event['provenance']['source']) &&
                $event['provenance']['source'] === 'fpp'
            ) {
                unset($manifest['events'][$eventId]);
            }
        }

        return $manifest;
    }

    /**
     * Strip type/target fields from a SubEvent before storing in the Manifest.
     *
     * @param array<string,mixed> $subEvent
     * @return array<string,mixed>
     */
    private function stripTypeTargetForManifest(array $subEvent): array
    {
        unset($subEvent['type'], $subEvent['target']);
        return $subEvent;
    }
}
