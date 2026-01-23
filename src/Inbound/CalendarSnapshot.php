<?php
declare(strict_types=1);

namespace GoogleCalendarScheduler\Inbound;

use GoogleCalendarScheduler\Core\ManifestStore;
use GoogleCalendarScheduler\Core\IdentityBuilder;
use GoogleCalendarScheduler\Core\IdentityHasher;
use GoogleCalendarScheduler\Platform\CalendarTranslator;

/**
 * CalendarSnapshot
 *
 * Inbound snapshot ingestion from a calendar provider (currently ICS).
 *
 * HARD RULES:
 * - Snapshot only (no identity, no diff, no apply)
 * - Replacement semantics: remove all prior calendar-sourced events, then re-add
 * - Writes draft manifest only (loadDraft/saveDraft)
 */
final class CalendarSnapshot
{
    private CalendarTranslator $translator;
    private ManifestStore $manifestStore;
    private IdentityBuilder $identityBuilder;
    private IdentityHasher $hasher;

    public function __construct(
        CalendarTranslator $translator,
        ManifestStore $manifestStore,
        IdentityBuilder $identityBuilder,
        IdentityHasher $hasher
    ) {
        $this->translator      = $translator;
        $this->manifestStore   = $manifestStore;
        $this->identityBuilder = $identityBuilder;
        $this->hasher = $hasher;
    }

    /**
     * Snapshot calendar source into the draft manifest.
     *
     * @param string $icsSource URL (http/https) or local file path
     */
    public function snapshot(string $icsSource): void
    {
        $manifest = $this->manifestStore->loadDraft();

        // Replacement-style ingestion: remove all previously imported calendar events
        if (isset($manifest['events']) && is_array($manifest['events'])) {
            foreach ($manifest['events'] as $eventId => $event) {
                if (($event['provenance']['source'] ?? null) === 'calendar') {
                    unset($manifest['events'][$eventId]);
                }
            }
        }

        $events = $this->translator->translateIcsSourceToManifestEvents($icsSource);

        foreach ($events as $event) {
            if (
                !isset($event['type']) ||
                !isset($event['target']) ||
                !isset($event['timing']) ||
                !is_array($event['timing'])
            ) {
                throw new \RuntimeException(
                    'CalendarSnapshot requires each event to have type, target, and event-level timing array'
                );
            }

            // Build a base subEvent from event-level data
            $event['subEvents'] = [[
                'timing'   => $event['timing'],
                'behavior' => $event['behavior'] ?? [],
                'payload'  => null,
            ]];

            $identity = $this->identityBuilder->buildCanonical(
                $event['type'],
                $event['target'],
                $event['timing']
            );

            $id = $this->hasher->hash($identity);
            $event['identity'] = $identity;
            $event['id']       = $id;

            $manifest = $this->manifestStore->upsertEvent($manifest, $event);
        }

        $this->manifestStore->saveDraft($manifest);
    }
}