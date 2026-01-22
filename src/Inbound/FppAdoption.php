<?php
declare(strict_types=1);

namespace GoogleCalendarScheduler\Inbound;

use GoogleCalendarScheduler\Platform\FppScheduleTranslator;
use GoogleCalendarScheduler\Core\ManifestStore;
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
 * - 1 FPP scheduler entry = 1 Manifest Event
 * - Exactly one base SubEvent per Event
 * - No grouping, inference, or consolidation
 * - No calendar correlation
 * - No planner, diff, or apply logic
 *
 * This class is intentionally one-directional and used only during
 * initial adoption / bootstrap.
 */
final class FppAdoption
{
    private FppScheduleTranslator $translator;
    private ManifestStore $manifestStore;
    private IdentityBuilder $identityBuilder;

    public function __construct(
        FppScheduleTranslator $translator,
        ManifestStore $manifestStore,
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
        $events = $this->translator->scheduleToEvents($schedulePath);
        /** @var array<int,array<string,mixed>> $events */

        $manifest = $this->manifestStore->loadDraft();

        // Remove all previously adopted FPP events (replacement-style adoption)
        if (isset($manifest['events']) && is_array($manifest['events'])) {
            foreach ($manifest['events'] as $eventId => $event) {
                if (
                    isset($event['provenance']['source']) &&
                    $event['provenance']['source'] === 'fpp'
                ) {
                    unset($manifest['events'][$eventId]);
                }
            }
        }

        /** @var array<string,mixed> $event */
        foreach ($events as $event) {
            if (
                !isset($event['subEvents'][0]['timing']) ||
                !is_array($event['subEvents'][0]['timing'])
            ) {
                throw new RuntimeException(
                    'FPP adoption requires exactly one subEvent with timing'
                );
            }

            foreach ($event['subEvents'] as $subEvent) {
                /** @var array<string,mixed> $subEvent */
                if (
                    !isset($subEvent['timing']) ||
                    !is_array($subEvent['timing'])
                ) {
                    throw new RuntimeException(
                        'FPP adoption requires each subEvent to have timing'
                    );
                }

                /** @var array{type:string,target:string,subEvents:array<int,array<string,mixed>>} $event */
                $type   = (string) $event['type'];
                $target = (string) $event['target'];

                $timing = $subEvent['timing'] ?? null;

                // Some translators may emit timing as a JSON string; normalize here.
                if (is_string($timing)) {
                    $decoded = json_decode($timing, true);
                    if (is_array($decoded)) {
                        $timing = $decoded;
                    }
                }

                if (!is_array($timing)) {
                    throw new RuntimeException(
                        'FPP adoption identity requires timing to be an array; got ' . gettype($timing)
                    );
                }

                $identityInput = [
                    'type'   => $type,
                    'target' => $target,
                    'timing' => $timing,
                ];

                $event['id'] = $this->identityBuilder->build($identityInput, []);
                $manifest = $this->manifestStore->upsertEvent(
                    $manifest,
                    $event
                );
            }
        }

        $this->manifestStore->saveDraft($manifest);
    }
}