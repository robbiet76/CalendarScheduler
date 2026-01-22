<?php
declare(strict_types=1);

namespace GoogleCalendarScheduler\Platform;

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
     * Translate schedule.json into a list of Manifest SubEvents.
     *
     * @param string $schedulePath Absolute path to schedule.json
     * @return array<int,array<string,mixed>> List of SubEvent records
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

        $subEvents = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $subEvents[] = $this->buildSubEventRecord($entry);
        }

        return $subEvents;
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
        if (!empty($e['command'])) {
            return 'command';
        }

        if (!empty($e['playlist'])) {
            return 'playlist';
        }

        return 'sequence';
    }

    /**
     * Extract target value based on type.
     */
    private function extractTarget(string $type, array $e): string
    {
        return match ($type) {
            'command'  => (string)$e['command'],
            'playlist' => (string)$e['playlist'],
            'sequence' => (string)($e['playlist'] ?? ''),
        };
    }

    /**
     * Build base SubEvent from scheduler entry.
     *
     * @param string $type
     * @param array<string,mixed> $e
     * @return array<string,mixed>
     */
    private function buildBaseSubEvent(string $type, array $e): array
    {
        $subEvent = [

            'timing' => [
                'start_date' => [
                    'symbolic' => $e['startDate'] ?? null,
                    'hard'     => null,
                ],
                'end_date' => [
                    'symbolic' => $e['endDate'] ?? null,
                    'hard'     => null,
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
            ],

            'behavior' => [
                'enabled'  => (int)($e['enabled'] ?? 0),
                'repeat'   => (int)($e['repeat'] ?? 0),
                'stopType' => (int)($e['stopType'] ?? 0),
            ],
        ];

        // Command payload is opaque and preserved verbatim
        if ($type === 'command') {
            $subEvent['payload'] = $this->extractCommandPayload($e);
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
        $payload = [];

        foreach ($e as $key => $value) {
            if (in_array($key, [
                'command',
                'args',
                'multisyncCommand',
                'multisyncHosts',
            ], true)) {
                $payload[$key] = $value;
            }
        }

        return $payload;
    }
}