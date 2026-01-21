<?php
declare(strict_types=1);

namespace GoogleCalendarScheduler\Outbound;

use GoogleCalendarScheduler\Platform\FppScheduleTranslator;
use GoogleCalendarScheduler\Core\ManifestStore;
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

    public function __construct(
        FppScheduleTranslator $translator,
        ManifestStore $manifestStore
    ) {
        $this->translator    = $translator;
        $this->manifestStore = $manifestStore;
    }

    /**
     * Adopt FPP scheduler entries into the Manifest.
     *
     * @param string $schedulePath Absolute path to schedule.json
     */
    public function adopt(string $schedulePath): void
    {
        $subEventRecords = $this->translator->scheduleToSubEvents($schedulePath);

        $manifest = $this->manifestStore->load();

        foreach ($subEventRecords as $record) {
            $event = $this->wrapSubEventAsEvent($record);
            $manifest = $this->manifestStore->upsertEvent($manifest, $event);
        }

        $this->manifestStore->save($manifest);
    }

    /**
     * Wrap a single SubEvent into a standalone Manifest Event.
     *
     * This is the ONLY place where the "1 SubEvent = 1 Event" rule applies.
     *
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    private function wrapSubEventAsEvent(array $record): array
    {
        if (
            !isset($record['type']) ||
            !isset($record['target']) ||
            !isset($record['subEvent'])
        ) {
            throw new RuntimeException(
                'Invalid SubEvent record produced by FppScheduleTranslator'
            );
        }

        return [
            // Identity intentionally absent; assigned later
            'id' => null,

            // Event-level execution target
            'type'   => $record['type'],
            'target' => $record['target'],

            // Adoption-mode ownership
            'ownership' => [
                'managed'    => false,
                'controller' => 'manual',
                'locked'     => false,
            ],

            // No calendar correlation during adoption
            'correlation' => [
                'source'     => null,
                'externalId' => null,
            ],

            // Explicit provenance for auditability
            'provenance' => [
                'source'      => 'fpp',
                'provider'    => null,
                'imported_at' => date('c'),
            ],

            // Exactly one base SubEvent
            'subEvents' => [
                $record['subEvent'] + [
                    'role' => 'base',
                ],
            ],
        ];
    }
}