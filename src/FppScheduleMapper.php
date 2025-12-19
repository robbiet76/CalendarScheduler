<?php

/**
 * Maps consolidated range intents into native FPP scheduler entries.
 *
 * PHASE 11 CONTRACT:
 * - Every entry MUST include a GCS identity tag
 * - Identity is NOT optional
 * - Mapper fails closed if tag is missing
 */
final class GcsFppScheduleMapper
{
    /**
     * Map a consolidated range intent into an FPP scheduler entry.
     *
     * @param array<string,mixed> $ri
     * @return array<string,mixed>|null
     */
    public static function mapRangeIntentToSchedule(array $ri): ?array
    {
        // -----------------------------------------------------------------
        // Enforce identity contract
        // -----------------------------------------------------------------
        $tag = $ri['tag'] ?? null;
        if (!is_string($tag) || $tag === '') {
            GcsLog::warn('Skipping schedule entry without GCS tag', [
                'uid'     => $ri['uid'] ?? null,
                'type'    => $ri['type'] ?? null,
                'target'  => $ri['target'] ?? null,
            ]);
            return null;
        }

        // -----------------------------------------------------------------
        // Required base fields
        // -----------------------------------------------------------------
        $type   = (string)($ri['type'] ?? '');
        $start  = $ri['start'] ?? null;
        $end    = $ri['end'] ?? null;

        if (!$start instanceof DateTime || !$end instanceof DateTime) {
            GcsLog::warn('Skipping schedule entry with invalid start/end', [
                'tag' => $tag,
            ]);
            return null;
        }

        // -----------------------------------------------------------------
        // Build common scheduler fields
        // -----------------------------------------------------------------
        $entry = [
            'enabled'     => !empty($ri['enabled']) ? 1 : 0,
            'startTime'   => $start->format('H:i'),
            'endTime'     => $end->format('H:i'),
            'startDate'   => (string)($ri['startDate'] ?? ''),
            'endDate'     => (string)($ri['endDate'] ?? ''),
            'weekdayMask' => (int)($ri['weekdayMask'] ?? 0),
            'stopType'    => (string)($ri['stopType'] ?? 'graceful'),
            'repeat'      => (string)($ri['repeat'] ?? 'none'),
            'tag'         => $tag, // 🔒 IDENTITY GUARANTEED HERE
        ];

        // -----------------------------------------------------------------
        // Type-specific mapping
        // -----------------------------------------------------------------
        switch ($type) {

            case 'playlist':
                $target = (string)($ri['target'] ?? '');
                if ($target === '') {
                    GcsLog::warn('Playlist entry missing target', [
                        'tag' => $tag,
                    ]);
                    return null;
                }

                $entry['type']   = 'playlist';
                $entry['target'] = $target;
                break;

            case 'sequence':
                $target = (string)($ri['target'] ?? '');
                if ($target === '') {
                    GcsLog::warn('Sequence entry missing target', [
                        'tag' => $tag,
                    ]);
                    return null;
                }

                $entry['type']   = 'sequence';
                $entry['target'] = $target;
                break;

            case 'command':
                $command = (string)($ri['command'] ?? '');
                if ($command === '') {
                    GcsLog::warn('Command entry missing command', [
                        'tag' => $tag,
                    ]);
                    return null;
                }

                $entry['type']    = 'command';
                $entry['command'] = $command;
                $entry['args']    = is_array($ri['args'] ?? null) ? $ri['args'] : [];
                $entry['multisyncCommand'] = !empty($ri['multisyncCommand']) ? 1 : 0;
                break;

            default:
                GcsLog::warn('Unknown scheduler entry type', [
                    'type' => $type,
                    'tag'  => $tag,
                ]);
                return null;
        }

        return $entry;
    }
}
