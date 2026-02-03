<?php
declare(strict_types=1);

namespace CalendarScheduler\Adapter;

use CalendarScheduler\Platform\FPPSemantics;

/**
 * FppScheduleAdapter
 *
 * Bidirectional adapter between:
 *  - FPP schedule.json entry arrays (platform shape)
 *  - Canonical manifest-event arrays (internal shape)
 *
 * IMPORTANT:
 * - This adapter MUST preserve the existing mapping logic (type/target, all-day detection,
 *   guard date stripping, days/repeat/stopType normalization, command payload extraction).
 * - This adapter does NOT compute identityHash/stateHash. That is upstream (Normalizer/Planner).
 * - RawEvent is intentionally not used here.
 */
final class FppScheduleAdapter
{
    /**
     * Convert a single FPP schedule entry into a canonical manifest-event array.
     *
     * @param array<string,mixed> $entry
     * @return array<string,mixed> manifest-event
     */
    public function fromScheduleEntry(array $entry, \DateTimeZone $fppTz, int $scheduleUpdatedAt): array
    {
        // --- Type / target ---
        if (!empty($entry['command'])) {
            $type = 'command';
            $target = (string) $entry['command'];
        } else {
            $type = ($entry['sequence'] ?? 0) ? 'sequence' : 'playlist';
            $target = preg_replace('/\.fseq$/i', '', (string) ($entry['playlist'] ?? ''));
        }
        $type = FPPSemantics::normalizeType($type);

        // --- All-day detection (FPP convention) ---
        $isAllDay =
            ($entry['startTime'] ?? null) === '00:00:00'
            && ($entry['endTime'] ?? null) === '24:00:00'
            && (int) ($entry['startTimeOffset'] ?? 0) === 0
            && (int) ($entry['endTimeOffset'] ?? 0) === 0;

        // --- Guard date stripping (FPP scheduler uses far-future endDate sentinel) ---
        $endDate = $entry['endDate'] ?? null;
        if (
            is_string($endDate)
            && FPPSemantics::isSchedulerGuardDate(
                $endDate,
                new \DateTimeImmutable('now', $fppTz)
            )
        ) {
            $endDate = null;
        }

        // --- Timing (canonical) ---
        $timing = [
            'all_day' => $isAllDay,
            'start_date' => ['hard' => $entry['startDate'] ?? null, 'symbolic' => null],
            'end_date'   => ['hard' => $endDate, 'symbolic' => null],
            'start_time' => $isAllDay ? null : [
                'hard'     => $entry['startTime'] ?? null,
                'symbolic' => null,
                'offset'   => (int) ($entry['startTimeOffset'] ?? 0),
            ],
            'end_time'   => $isAllDay ? null : [
                'hard'     => $entry['endTime'] ?? null,
                'symbolic' => null,
                'offset'   => (int) ($entry['endTimeOffset'] ?? 0),
            ],
            'days' => FPPSemantics::normalizeDays($entry['day'] ?? null),
        ];

        // --- Payload ---
        $repeatNumeric = FPPSemantics::normalizeRepeat($entry['repeat'] ?? null);
        $stopType = FPPSemantics::stopTypeToSemantic(
            FPPSemantics::stopTypeToEnum($entry['stopType'] ?? null)
        );

        $payload = [
            'enabled'  => FPPSemantics::normalizeEnabled($entry['enabled'] ?? true),
            'repeat'   => FPPSemantics::repeatToSemantic($repeatNumeric),
            'stopType' => $stopType,
        ];

        if ($type === 'command') {
            // Preserve the previously agreed command extraction behavior.
            $payload['command'] = [
                'args'      => $entry['args'] ?? [],
                'multisync' => (bool) ($entry['multisync'] ?? false),
                'hosts'     => $entry['hosts'] ?? null,
            ];
        }

        // Canonical manifest-event shape (hashes computed upstream)
        return [
            'source' => 'fpp',
            'type' => $type,
            'target' => $target,
            'timing' => $timing,
            'payload' => $payload,
            'ownership' => [
                'managed'    => true,
                'controller' => 'fpp',
                'locked'     => false,
            ],
            'correlation' => [
                'source' => 'fpp',
                'raw'    => $entry,
            ],
            'sourceUpdatedAt' => $scheduleUpdatedAt,
        ];
    }

    /**
     * Convert a canonical manifest-event back into an FPP schedule entry array.
     *
     * @param array<string,mixed> $event manifest-event
     * @return array<string,mixed> schedule.json entry
     */
    public function toScheduleEntry(array $event): array
    {
        $typeNorm = (string) ($event['type'] ?? '');
        $target   = (string) ($event['target'] ?? '');

        // Denormalize type to FPP representation expectations
        $type = FPPSemantics::denormalizeType($typeNorm);

        $payload = is_array($event['payload'] ?? null) ? (array) $event['payload'] : [];
        $timing  = is_array($event['timing'] ?? null) ? (array) $event['timing'] : [];

        $entry = [
            'enabled'  => FPPSemantics::denormalizeEnabled((bool) ($payload['enabled'] ?? true)),
            'repeat'   => FPPSemantics::semanticToRepeat($payload['repeat'] ?? null),
            'stopType' => FPPSemantics::stopTypeToEnum($payload['stopType'] ?? null),
            'day'      => FPPSemantics::denormalizeDays($timing['days'] ?? null),
        ];

        // --- Type-specific fields ---
        if ($type === 'command') {
            $entry['command'] = $target;
            $cmd = is_array($payload['command'] ?? null) ? (array) $payload['command'] : [];
            $entry['args']      = $cmd['args']      ?? [];
            // FPP commonly stores multisync as int; preserve old behavior of accepting bool-like.
            $entry['multisync'] = (int) ($cmd['multisync'] ?? 0);
            $entry['hosts']     = $cmd['hosts']     ?? null;
        } else {
            $entry['playlist'] = $target;
            $entry['sequence'] = ($type === 'sequence') ? 1 : 0;
        }

        // --- Timing ---
        $allDay = (bool) ($timing['all_day'] ?? false);

        if ($allDay) {
            $entry['startTime'] = '00:00:00';
            $entry['endTime']   = '24:00:00';
            $entry['startTimeOffset'] = 0;
            $entry['endTimeOffset']   = 0;
        } else {
            $startTime = is_array($timing['start_time'] ?? null) ? (array) $timing['start_time'] : [];
            $endTime   = is_array($timing['end_time'] ?? null) ? (array) $timing['end_time'] : [];

            $entry['startTime']       = $startTime['hard'] ?? null;
            $entry['endTime']         = $endTime['hard']   ?? null;
            $entry['startTimeOffset'] = (int) ($startTime['offset'] ?? 0);
            $entry['endTimeOffset']   = (int) ($endTime['offset']   ?? 0);
        }

        $startDate = is_array($timing['start_date'] ?? null) ? (array) $timing['start_date'] : [];
        $endDate   = is_array($timing['end_date'] ?? null) ? (array) $timing['end_date'] : [];

        $entry['startDate'] = $startDate['hard'] ?? null;
        $entry['endDate']   = $endDate['hard']   ?? null;

        return $entry;
    }
}