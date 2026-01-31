<?php
declare(strict_types=1);

namespace CalendarScheduler\Adapter;

/**
 * FppScheduleTranslator
 *
 * Translates between FPP schedule.json entries and Manifest SubEvents.
 *
 * This class encapsulates ALL Falcon Player–specific scheduler semantics.
 * It is intentionally agnostic about direction:
 *   - schedule.json → Manifest SubEvents (adoption / export)
 *   - Manifest SubEvents → schedule.json (apply)
 *
 * HARD RULES:
 * - No planning logic
 * - No diffing logic
 * - No identity inference or healing
 * - No calendar logic
 * - No ordering logic
 *
 * One FPP scheduler entry === one Manifest SubEvent (identity and grouping into Events is handled elsewhere)
 */
final class FppScheduleTranslator
{
    /**
     * Translate schedule.json into a list of Manifest Events.
     *
     * @param string $schedulePath Absolute path to schedule.json
     * @return array<int,array<string,mixed>> List of Manifest Events
     */
    public function scheduleToEvents(string $schedulePath): array
    {
        if (!is_file($schedulePath)) {
            throw new \RuntimeException("schedule.json not found: {$schedulePath}");
        }

        $raw = file_get_contents($schedulePath);
        if ($raw === false) {
            throw new \RuntimeException("Failed to read schedule.json");
        }

        $entries = json_decode($raw, true);
        if (!is_array($entries)) {
            throw new \RuntimeException("Invalid JSON in schedule.json");
        }

        $events = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $type = $this->detectType($entry);
            $target = $this->extractTarget($type, $entry);
            $subEvent = $this->buildBaseSubEvent($type, $entry);

            if (
                !isset($subEvent['timing']) ||
                !is_array($subEvent['timing'])
            ) {
                throw new \RuntimeException(
                    'FPP schedule entry could not be translated to a valid timing object'
                );
            }

            $key = $type . '|' . $target;
            if (!isset($events[$key])) {
                $events[$key] = [
                    'type' => $type,
                    'target' => $target,
                    'subEvents' => [],
                ];
            }
            $events[$key]['subEvents'][] = $subEvent;
        }

        return array_values($events);
    }

    /**
     * Translate schedule.json into a flat list of Manifest SubEvents.
     *
     * This is used by adoption and identity building, where grouping
     * into Events happens elsewhere.
     *
     * @param string $schedulePath
     * @return array<int,array<string,mixed>>
     */
    public function scheduleToSubEvents(string $schedulePath): array
    {
        if (!is_file($schedulePath)) {
            throw new \RuntimeException("schedule.json not found: {$schedulePath}");
        }

        $raw = file_get_contents($schedulePath);
        if ($raw === false) {
            throw new \RuntimeException("Failed to read schedule.json");
        }

        $entries = json_decode($raw, true);
        if (!is_array($entries)) {
            throw new \RuntimeException("Invalid JSON in schedule.json");
        }

        $out = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $type = $this->detectType($entry);
            $target = $this->extractTarget($type, $entry);

            $subEvent = $this->buildBaseSubEvent($type, $entry);
            $subEvent['type'] = $type;
            $subEvent['target'] = $target;

            if (
                !isset($subEvent['timing']) ||
                !is_array($subEvent['timing'])
            ) {
                throw new \RuntimeException(
                    'FPP schedule entry could not be translated to a valid timing object'
                );
            }

            $out[] = $subEvent;
        }

        return $out;
    }

    /**
     * Build a Manifest SubEvent record from one FPP scheduler entry.
     *
     * @param array<string,mixed> $e
     * @return array<string,mixed>
     */
    private function buildSubEventRecord(array $e): array
    {
        return $this->buildBaseSubEvent($this->detectType($e), $e);
    }

    /**
     * Determine scheduler entry type.
     *
     * @return 'playlist'|'sequence'|'command'
     */
    private function detectType(array $e): string
    {
        if (isset($e['command'])) {
            return 'command';
        }
        if (isset($e['sequence']) && $e['sequence'] === 1) {
            return 'sequence';
        }
        return 'playlist';
    }

    /**
     * Extract target value based on type.
     */
    private function extractTarget(string $type, array $e): string
    {
        if ($type === 'command') {
            return (string)$e['command'];
        }
        // For playlist and sequence, use 'playlist'
        $target = isset($e['playlist']) ? (string)$e['playlist'] : '';
        // Remove trailing .fseq extension (case-insensitive) if present
        $target = preg_replace('/\.fseq$/i', '', $target);
        return $target;
    }

    /**
     * Build base SubEvent from scheduler entry (canonical timing + execution payload).
     *
     * @param string $type
     * @param array<string,mixed> $e
     * @return array<string,mixed>
     */
    private function buildBaseSubEvent(string $type, array $e): array
    {
        $subEvent = [
            'timing' => $this->buildCanonicalTiming($e),
            // Execution controls live in payload (shared shape across sources)
            'payload' => [
                'enabled'  => (bool)($e['enabled'] ?? false),
                'repeat'   => (int)($e['repeat'] ?? 0),
                'stopType' => (int)($e['stopType'] ?? 0),
            ],
        ];

        // Command payload is opaque and preserved verbatim (excluding core scheduler fields)
        if ($type === 'command') {
            $subEvent['payload'] = array_merge(
                $subEvent['payload'],
                $this->extractCommandPayload($e)
            );
        }

        return $subEvent;
    }

    /**
     * Extract opaque command payload.
     *
     * @param array<string,mixed> $e
     * @return array<string,mixed>
     */
    private function extractCommandPayload(array $e): array
    {
        // Core scheduler fields that should NOT be treated as command options.
        $core = [
            'enabled',
            'sequence',
            'day',
            'startTime',
            'startTimeOffset',
            'endTime',
            'endTimeOffset',
            'repeat',
            'startDate',
            'endDate',
            'stopType',
            'playlist',
            'command',
            'args',
        ];

        $command = [
            'name' => (string)($e['command'] ?? ''),
            'args' => $e['args'] ?? null,
        ];

        // Preserve any additional command-specific options verbatim (flattened).
        foreach ($e as $key => $value) {
            if (in_array($key, $core, true)) {
                continue;
            }
            $command[$key] = $value;
        }

        return [
            'command' => $command,
        ];
    }
    /**
     * Build canonical timing object for identity and manifest use.
     *
     * @param array<string,mixed> $e
     * @return array<string,mixed>
     */
    private function buildCanonicalTiming(array $e): array
    {
        return [
            'start_date' => [
                'symbolic' => null,
                'hard'     => $e['startDate'] ?? null,
            ],
            'end_date' => [
                'symbolic' => null,
                'hard'     => $e['endDate'] ?? null,
            ],
            'start_time' => [
                'symbolic' => null,
                'hard'     => $e['startTime'] ?? null,
                'offset'   => (int)($e['startTimeOffset'] ?? 0),
            ],
            'end_time' => [
                'symbolic' => null,
                'hard'     => $e['endTime'] ?? null,
                'offset'   => (int)($e['endTimeOffset'] ?? 0),
            ],
            'days' => (int)($e['day'] ?? 0),
        ];
    }
}