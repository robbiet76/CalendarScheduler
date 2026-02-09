<?php
declare(strict_types=1);

namespace CalendarScheduler\Adapter\Calendar;

use RuntimeException;

/**
 * CalendarSnapshot
 *
 * In-memory grouping boundary for calendar provider events (provider-agnostic).
 *
 * HARD RULES:
 * - Snapshot only (no identity, no intent, no hashing, no normalization)
 * - Preserve raw calendar semantics exactly as provided by the calendar source
 * - Replacement semantics for calendar-sourced records only
 * - Groups translated provider rows into SnapshotEvent structures; does not write files
 */
final class CalendarSnapshot
{

    /** @var SnapshotEvent[] */
    private array $snapshotEvents = [];

    public function __construct()
    {
        // Snapshot is provider-agnostic; translation occurs upstream.
    }

    /**
     * Snapshot already-translated calendar provider events into grouped SnapshotEvent objects.
     *
     * @param array $providerEvents Raw calendar provider events (post-translation)
     * @return SnapshotEvent[]
     */
    public function snapshot(array $providerEvents): array
    {
        $eventsByUid = [];
        $cancelledRows = [];
        $overrideRows = [];

        // First pass: create SnapshotEvent instances for rows where parentUid is null or not set
        foreach ($providerEvents as $row) {
            if (isset($row['parentUid']) && $row['parentUid'] !== null) {
                // This is either an override or a cancellation, handle later
                if (isset($row['status']) && $row['status'] === 'cancelled') {
                    $cancelledRows[] = $row;
                } else {
                    $overrideRows[] = $row;
                }
                continue;
            }
            $uid = $row['uid'] ?? null;
            if ($uid === null) {
                continue;
            }
            $eventsByUid[$uid] = new SnapshotEvent($row);
        }

        // Second pass: collect cancelled rows into cancelledDates on the parent SnapshotEvent
        foreach ($cancelledRows as $row) {
            $parentUid = $row['parentUid'] ?? null;
            if ($parentUid === null || !isset($eventsByUid[$parentUid])) {
                throw new RuntimeException("Cancelled event references missing parent UID: " . var_export($parentUid, true));
            }
            $originalStartTime = $row['originalStartTime'] ?? null;
            if ($originalStartTime === null) {
                throw new RuntimeException("Cancelled event missing originalStartTime");
            }
            $eventsByUid[$parentUid]->addCancelledDate($originalStartTime);
            $eventsByUid[$parentUid]->addSourceRow($row);
        }

        // Third pass: collect override rows into OverrideIntent objects attached to the parent SnapshotEvent
        foreach ($overrideRows as $row) {
            $parentUid = $row['parentUid'] ?? null;
            if ($parentUid === null || !isset($eventsByUid[$parentUid])) {
                throw new RuntimeException("Override event references missing parent UID: " . var_export($parentUid, true));
            }
            $overrideIntent = new OverrideIntent($row);
            $eventsByUid[$parentUid]->addOverride($overrideIntent);
            $eventsByUid[$parentUid]->addSourceRow($row);
        }

        $this->snapshotEvents = array_values($eventsByUid);
        return $this->snapshotEvents;
    }

    /**
     * Returns the most recently generated snapshot events.
     *
     * Resolution consumes this to avoid passing arrays across layers.
     *
     * @return SnapshotEvent[]
     */
    public function getSnapshotEvents(): array
    {
        return $this->snapshotEvents;
    }
}
