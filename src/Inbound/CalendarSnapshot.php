<?php
declare(strict_types=1);

namespace GoogleCalendarScheduler\Inbound;

use GoogleCalendarScheduler\Core\ManifestStore;
use GoogleCalendarScheduler\Platform\CalendarTranslator;
use GoogleCalendarScheduler\Intent\NormalizationContext;
use GoogleCalendarScheduler\Platform\FPPSemantics;
use DateTimeZone;

/**
 * CalendarSnapshot
 *
 * Inbound snapshot ingestion from a calendar provider (currently ICS).
 *
 * HARD RULES:
 * - Snapshot only (no identity, no intent, no hashing, no normalization)
 * - Preserve raw calendar semantics exactly as provided by the calendar source
 * - Replacement semantics for calendar-sourced records only
 * - Writes only `calendar_events` as raw provider records (no other manifest data is modified)
 */
final class CalendarSnapshot
{
    private CalendarTranslator $translator;
    private ManifestStore $manifestStore;

    public function __construct(
        CalendarTranslator $translator,
        ManifestStore $manifestStore
    ) {
        $this->translator    = $translator;
        $this->manifestStore = $manifestStore;
    }

    /**
     * Build NormalizationContext from FPP runtime environment.
     *
     * This is the authoritative ingestion boundary where environmental
     * facts (timezone, semantics) are captured for downstream intent
     * normalization.
     */
    private function buildNormalizationContext(): NormalizationContext
    {
        $envPath = __DIR__ . '/../../runtime/fpp-env.json';

        if (!is_file($envPath)) {
            throw new \RuntimeException('Missing fpp-env.json at ' . $envPath);
        }

        $env = json_decode(file_get_contents($envPath), true);
        if (!is_array($env) || !isset($env['timezone'])) {
            throw new \RuntimeException('Invalid fpp-env.json: missing timezone');
        }

        return new NormalizationContext(
            new DateTimeZone($env['timezone']),
            new FPPSemantics(),
            [
                'latitude'  => $env['latitude']  ?? null,
                'longitude' => $env['longitude'] ?? null,
                'locale'    => $env['rawLocale'] ?? null,
            ]
        );
    }

    /**
     * Snapshot calendar source into the draft manifest.
     *
     * Only writes raw provider calendar events under `calendar_events`.
     * No identity resolution, intent extraction, hashing, or normalization occurs here.
     *
     * @param string $icsSource URL (http/https) or local file path
     */
    public function snapshot(string $icsSource): void
    {
        $manifest = $this->manifestStore->loadDraft();

        // Defensive guard: ensure $manifest is an array before mutation
        if (!is_array($manifest)) {
            $manifest = [];
        }

        // Replacement-style ingestion: calendar snapshot replaces all calendar_events
        // Only raw provider records are written here.
        $manifest['calendar_events'] = [];

        $events = $this->translator->translateIcsSourceToCalendarEvents($icsSource);

        foreach ($events as $event) {
            if (!isset($event['uid'])) {
                throw new \RuntimeException('CalendarSnapshot requires each event to have a uid');
            }

            $manifest['calendar_events'][$event['uid']] = $event;
        }

        $this->manifestStore->saveDraft($manifest);
    }
}