<?php
declare(strict_types=1);

namespace GoogleCalendarScheduler\Inbound;

use GoogleCalendarScheduler\Core\ManifestStore;
use GoogleCalendarScheduler\Core\IdentityBuilder;
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

    public function __construct(
        CalendarTranslator $translator,
        ManifestStore $manifestStore,
        IdentityBuilder $identityBuilder
    ) {
        $this->translator      = $translator;
        $this->manifestStore   = $manifestStore;
        $this->identityBuilder = $identityBuilder;
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
                !isset($event['subEvents'][0]['timing']) ||
                !is_array($event['subEvents'][0]['timing'])
            ) {
                throw new \RuntimeException(
                    'CalendarSnapshot requires each event to have type, target, and base subEvent timing array'
                );
            }

            $identity = $this->identityBuilder->buildCanonical(
                $event['type'],
                $event['target'],
                $event['subEvents'][0]['timing']
            );

            if (!isset($identity['id'])) {
                throw new \RuntimeException('IdentityBuilder did not produce identity.id');
            }

            $event['identity'] = $identity;
            $event['id']       = $identity['id'];

            $manifest = $this->manifestStore->upsertEvent($manifest, $event);
        }

        $this->manifestStore->saveDraft($manifest);
    }
}