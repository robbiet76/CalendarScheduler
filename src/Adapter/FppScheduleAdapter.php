<?php
declare(strict_types=1);

namespace CalendarScheduler\Adapter;

use CalendarScheduler\Intent\NormalizationContext;
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
    /** @var array<string,bool> */
    private const COMMAND_EXCLUDE_KEYS = [
        'enabled' => true,
        'sequence' => true,
        'day' => true,
        'startTime' => true,
        'startTimeOffset' => true,
        'endTime' => true,
        'endTimeOffset' => true,
        'repeat' => true,
        'startDate' => true,
        'endDate' => true,
        'stopType' => true,
        'playlist' => true,
        'command' => true,
        // If FPP ever adds additional scheduler keys, add them here (adapter-only).
    ];

    /**
     * Split an FPP date field into canonical hard/symbolic parts.
     *
     * FPP allows:
     *  - Hard dates: YYYY-MM-DD (including date-masking patterns with 0000 year / 00 month / 00 day)
     *  - Symbolic dates: holiday tokens like "Thanksgiving", "Epiphany", "Christmas", etc.
     *
     * Canonical contract:
     *  - hard is either a YYYY-MM-DD string (including 0000/00 masking) or null
     *  - symbolic is either a non-empty token (case preserved) or null
     *
     * @param mixed $value
     * @return array{hard: ?string, symbolic: ?string}
     */
    private function splitDateHardSymbolic(mixed $value): array
    {
        if (!is_string($value)) {
            return ['hard' => null, 'symbolic' => null];
        }

        $s = trim($value);
        if ($s === '') {
            return ['hard' => null, 'symbolic' => null];
        }

        // Accept YYYY-MM-DD including "date masking" (0000 year / 00 month / 00 day).
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) === 1) {
            return ['hard' => $s, 'symbolic' => null];
        }

        // Otherwise treat as symbolic token (holiday name).
        return ['hard' => null, 'symbolic' => $s];
    }

    /**
     * Parse minimal INI-like settings from a calendar description.
     * We only care about:
     *  - [settings] type = playlist|sequence|command
     */
    private function parseSettingsTypeFromDescription(?string $description): ?string
    {
        if (!is_string($description) || trim($description) === '') {
            return null;
        }

        $section = null;
        $lines = preg_split('/\r\n|\r|\n/', $description) ?: [];
        foreach ($lines as $line) {
            $t = trim($line);
            if ($t === '' || str_starts_with($t, '#') || str_starts_with($t, ';')) {
                continue;
            }
            if (preg_match('/^\[(.+)\]$/', $t, $m) === 1) {
                $section = strtolower(trim($m[1]));
                continue;
            }
            if ($section !== 'settings') {
                continue;
            }
            if (preg_match('/^type\s*=\s*(.+)$/i', $t, $m) === 1) {
                $v = strtolower(trim($m[1]));
                if ($v === 'playlist' || $v === 'sequence' || $v === 'command') {
                    return $v;
                }
            }
        }

        return null;
    }

    /**
     * Parse a minimal INI-like [command] block from description.
     * Supports keys like:
     *  - args[] = 100
     *  - multisync = true
     *  - hosts = 192.168.1.2
     *
     * Returns a map that can be merged directly into an FPP schedule entry.
     *
     * @return array<string,mixed>
     */
    private function parseCommandBlockFromDescription(?string $description): array
    {
        if (!is_string($description) || trim($description) === '') {
            return [];
        }

        $section = null;
        $out = [];
        $lines = preg_split('/\r\n|\r|\n/', $description) ?: [];
        foreach ($lines as $line) {
            $t = trim($line);
            if ($t === '' || str_starts_with($t, '#') || str_starts_with($t, ';')) {
                continue;
            }
            if (preg_match('/^\[(.+)\]$/', $t, $m) === 1) {
                $section = strtolower(trim($m[1]));
                continue;
            }
            if ($section !== 'command') {
                continue;
            }
            // key = value
            if (preg_match('/^([^=]+?)\s*=\s*(.*)$/', $t, $m) !== 1) {
                continue;
            }
            $key = trim($m[1]);
            $valRaw = trim($m[2]);

            // basic bool parsing
            $valLower = strtolower($valRaw);
            $val = $valRaw;
            if ($valLower === 'true') {
                $val = true;
            } elseif ($valLower === 'false') {
                $val = false;
            }

            // array keys like args[]
            if (str_ends_with($key, '[]')) {
                if (!isset($out[$key]) || !is_array($out[$key])) {
                    $out[$key] = [];
                }
                $out[$key][] = $val;
                continue;
            }

            $out[$key] = $val;
        }

        return $out;
    }

    /**
     * Load and convert all FPP schedule entries into canonical manifest-event arrays.
     *
     * @param \DateTimeZone $fppTz
     * @param string $schedulePath Absolute path to schedule.json
     * @return array<int,array<string,mixed>> manifest-events
     */
    public function loadManifestEvents(
        NormalizationContext $context,
        string $schedulePath
    ): array {
        $fppTz = $context->timezone;
        if (!is_file($schedulePath)) {
            throw new \RuntimeException("FPP schedule not found: {$schedulePath}");
        }

        $raw = json_decode(
            file_get_contents($schedulePath),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        if (!is_array($raw)) {
            throw new \RuntimeException('Invalid FPP schedule.json');
        }

        $mtime = filemtime($schedulePath);
        $updatedAt = is_int($mtime) ? $mtime : time();

        $events = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $events[] = $this->fromScheduleEntry($entry, $fppTz, $updatedAt);
        }

        return $events;
    }

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
        $normalizedDays = FPPSemantics::normalizeDays($entry['day'] ?? null);

        $startDateParts = $this->splitDateHardSymbolic($entry['startDate'] ?? null);
        $endDateParts   = $this->splitDateHardSymbolic($endDate);

        $timing = [
            'all_day' => $isAllDay,
            'start_date' => $startDateParts,
            'end_date'   => $endDateParts,
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
            'days' => $normalizedDays === null
                ? null
                : [
                    'type'  => 'weekly',
                    'value' => $normalizedDays,
                ],
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
            /**
             * IMPORTANT (restored behavior):
             * Preserve *all* non-scheduler keys for command entries, not only args/multisync/hosts.
             * This prevents state drift and matches the old adapter.
             */
            $command = [];
            foreach ($entry as $k => $v) {
                if (isset(self::COMMAND_EXCLUDE_KEYS[$k])) {
                    continue;
                }
                $command[$k] = $v;
            }
            $command['name'] = (string) ($entry['command'] ?? '');
            $payload['command'] = $command;
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
            // IMPORTANT: calendar-scheduler consumes updatedAtEpoch for authority.
            'updatedAtEpoch'  => $scheduleUpdatedAt,
            // Keep for any older call sites that still read this name.
            'sourceUpdatedAt' => $scheduleUpdatedAt,
        ];
    }

    /**
     * Convert a list of canonical manifest-events into schedule.json entries.
     *
     * @param array<int,array<string,mixed>> $events
     * @return array<int,array<string,mixed>>
     */
    public function toScheduleEntries(array $events): array
    {
        $out = [];
        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }
            $out[] = $this->toScheduleEntry($event);
        }
        return $out;
    }

    /**
     * Convert a canonical manifest-event back into an FPP schedule entry array.
     *
     * @param array<string,mixed> $event manifest-event
     * @return array<string,mixed> schedule.json entry
     */
    public function toScheduleEntry(array $event): array
    {
        // Current manifest shape (v2): identity + subEvents (no legacy support)
        if (!isset($event['identity']) || !isset($event['subEvents'][0])) {
            throw new \InvalidArgumentException('Invalid manifest event shape for FPP adapter.');
        }

        $identity = is_array($event['identity']) ? $event['identity'] : [];
        $subEvent = is_array($event['subEvents'][0]) ? $event['subEvents'][0] : [];
        $payload = is_array($subEvent['payload'] ?? null) ? (array) $subEvent['payload'] : [];
        $timing  = is_array($subEvent['timing'] ?? null) ? (array) $subEvent['timing'] : [];
        $behavior = is_array($subEvent['behavior'] ?? null) ? (array) $subEvent['behavior'] : [];

        $summary = is_string($payload['summary'] ?? null) ? (string) $payload['summary'] : '';
        $description = is_string($payload['description'] ?? null) ? (string) $payload['description'] : null;

        // Prefer explicit identity when it exists, otherwise infer from payload.
        $typeNorm = (string) ($identity['type'] ?? '');
        $target   = (string) ($identity['target'] ?? '');

        if ($typeNorm === '' || $typeNorm === 'unknown') {
            $inferred = $this->parseSettingsTypeFromDescription($description);
            if (is_string($inferred) && $inferred !== '') {
                $typeNorm = $inferred;
            }
        }

        // If still unknown, default to playlist (most common).
        if ($typeNorm === '' || $typeNorm === 'unknown') {
            $typeNorm = 'playlist';
        }

        // If target is missing, infer from summary.
        if (trim($target) === '') {
            $target = $summary;
        }

        // Denormalize type to FPP representation expectations
        $type = FPPSemantics::denormalizeType($typeNorm);

        $enabledSemantic = $behavior['enabled'] ?? ($payload['enabled'] ?? true);
        $repeatSemantic  = $behavior['repeat']  ?? ($payload['repeat']  ?? 'none');
        $stopTypeSemantic = $behavior['stopType'] ?? ($payload['stopType'] ?? null);

        // Determine RRULE metadata (if present)
        $rrule = is_array($payload['rrule'] ?? null) ? $payload['rrule'] : null;
        $rruleFreq = is_string($rrule['freq'] ?? null) ? strtoupper($rrule['freq']) : null;
        $weeklyDays = null;

        if (
            is_array($timing['days'] ?? null)
            && ($timing['days']['type'] ?? null) === 'weekly'
            && is_array($timing['days']['value'] ?? null)
        ) {
            $weeklyDays = $timing['days']['value'];
        }

        // Default repeat/day from semantic behavior
        $repeatValue = FPPSemantics::semanticToRepeat((string) $repeatSemantic);
        $dayValue = FPPSemantics::denormalizeDays($weeklyDays);

        /**
         * If weeklyDays metadata exists (Resolution populated timing.days),
         * force FPP weekly repeat + proper day mask.
         *
         * Do NOT rely solely on RRULE freq here — manifest timing is authoritative.
         */
        if (is_array($weeklyDays) && $weeklyDays !== []) {
            $repeatValue = 1; // FPP weekly
            $dayValue = FPPSemantics::denormalizeDays($weeklyDays);
        }

        $entry = [
            'enabled'  => FPPSemantics::denormalizeEnabled((bool) $enabledSemantic),
            'repeat'   => $repeatValue,
            'stopType' => FPPSemantics::stopTypeToEnum($stopTypeSemantic),
            'day'      => $dayValue,
        ];

        // --- Type-specific fields ---
        if ($type === 'command') {
            $cmd = is_array($payload['command'] ?? null) ? (array) $payload['command'] : [];

            // If command details are not yet structured, parse them from description.
            if (empty($cmd) && is_string($description) && trim($description) !== '') {
                $cmd = $this->parseCommandBlockFromDescription($description);
            }

            /**
             * Reverse mapping symmetry:
             * - command.name -> entry.command
             * - if missing, fall back to summary (common authoring pattern) then identity target
             * - all other command keys pass through to the schedule entry
             */
            $entry['command'] = isset($cmd['name'])
                ? (string) $cmd['name']
                : (trim($summary) !== '' ? $summary : $target);

            // FPP contract: command entries must explicitly set sequence=0 and playlist=""
            $entry['sequence'] = 0;
            $entry['playlist'] = '';

            foreach ($cmd as $k => $v) {
                if ($k === 'name') {
                    continue;
                }
                if (isset(self::COMMAND_EXCLUDE_KEYS[$k])) {
                    continue;
                }

                // Normalize args[] -> args for FPP schedule.json shape
                if ($k === 'args[]') {
                    $entry['args'] = is_array($v) ? array_values($v) : [$v];
                    continue;
                }

                $entry[$k] = $v;
            }
        } else {
            $name = trim($target) !== '' ? $target : $summary;
            $entry['playlist'] =
                ($type === 'sequence' && !str_ends_with($name, '.fseq'))
                    ? $name . '.fseq'
                    : $name;

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

            $entry['startTime'] =
                ($startTime['symbolic'] ?? null)
                    ?: ($startTime['hard'] ?? null);

            $entry['endTime'] =
                ($endTime['symbolic'] ?? null)
                    ?: ($endTime['hard'] ?? null);

            $entry['startTimeOffset'] = (int) ($startTime['offset'] ?? 0);
            $entry['endTimeOffset']   = (int) ($endTime['offset']   ?? 0);
        }

        $startDate = is_array($timing['start_date'] ?? null) ? (array) $timing['start_date'] : [];
        $endDate   = is_array($timing['end_date'] ?? null) ? (array) $timing['end_date'] : [];

        $entry['startDate'] =
            ($startDate['symbolic'] ?? null)
                ?: ($startDate['hard'] ?? null);

        $entry['endDate'] =
            ($endDate['symbolic'] ?? null)
                ?: ($endDate['hard'] ?? null);

        return $entry;
    }
}
