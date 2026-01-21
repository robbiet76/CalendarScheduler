<?php

declare(strict_types=1);

namespace GoogleCalendarScheduler\Platform;

use GoogleCalendarScheduler\Planner\PlannedEntry;

/**
 * FppScheduleEntryAdapter
 *
 * Platform-layer adapter that converts a fully-normalized PlannedEntry
 * into an FPP-compatible schedule entry structure.
 *
 * IMPORTANT:
 * This adapter reflects the *actual* PlannedEntry API.
 * It does NOT assume additional semantic getters that do not exist.
 *
 * CONTRACT ASSUMPTIONS:
 * - identity_hash is canonical and immutable
 * - timing is fully resolved (no symbolic times, no holidays)
 * - All scheduler semantics are already embedded in target + timing
 * - ordering_key is carried through but not interpreted here
 *
 * NON-GOALS:
 * - No validation beyond structural sanity
 * - No date/time math
 * - No inference
 * - No logging
 * - No I/O
 */
final class FppScheduleEntryAdapter
{
    /**
     * Adapt a PlannedEntry into an FPP schedule entry array.
     *
     * @return array<string,mixed>
     */
    public static function adapt(PlannedEntry $entry): array
    {
        $out = [
            // Canonical identity
            'identity_hash' => $entry->identityHash(),

            // Managed entries are always explicit in V2
            'managed' => true,

            // Fully-resolved timing (dtstart / dtend / rrule, etc.)
            'timing' => $entry->timing(),

            // Fully-resolved target payload (playlist / sequence / command)
            'target' => $entry->target(),

            // Ordering is preserved for Apply / writers,
            // but never interpreted here.
            'ordering_key' => $entry->orderingKey()->toScalar(),
        ];

        /*
         * Optional traceability fields.
         * These are redundant and non-authoritative.
         */
        if ($entry->eventId() !== null) {
            $out['event_id'] = $entry->eventId();
        }

        if ($entry->subEventId() !== null) {
            $out['sub_event_id'] = $entry->subEventId();
        }

        return $out;
    }
}
