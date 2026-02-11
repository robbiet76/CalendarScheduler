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
     * Convert a canonical manifest-event back into an FPP schedule entry array.
     *
     * V2 Manifest aware:
     * - Root contains identity metadata
     * - Real schedule entries live inside subEvents[]
     */
    public function toScheduleEntries(array $event): array
    {
        // ----------------------------
        // v2 manifest shape detection
        // ----------------------------
        $identity = is_array($event['identity'] ?? null) ? (array) $event['identity'] : null;
        $subEvents = is_array($event['subEvents'] ?? null) ? (array) $event['subEvents'] : null;

        // Helper to render a single row
        $renderOne = function (string $typeNorm, string $target, array $timing, array $payload): array {
            $type = FPPSemantics::denormalizeType($typeNorm);

            $entry = [
                'enabled'  => FPPSemantics::denormalizeEnabled((bool) ($payload['enabled'] ?? true)),
                'repeat'   => FPPSemantics::semanticToRepeat((string) ($payload['repeat'] ?? 'none')),
                'stopType' => FPPSemantics::stopTypeToEnum($payload['stopType'] ?? null),
                'day' => FPPSemantics::denormalizeDays(
                    is_array($timing['days'] ?? null)
                        && ($timing['days']['type'] ?? null) === 'weekly'
                        ? ($timing['days']['value'] ?? null)
                        : null
                ),
            ];

            // --- Type-specific fields ---
            if ($type === 'command') {
                $cmd = is_array($payload['command'] ?? null) ? (array) $payload['command'] : [];
                $entry['command'] = isset($cmd['name']) ? (string) $cmd['name'] : $target;

                foreach ($cmd as $k => $v) {
                    if ($k === 'name') {
                        continue;
                    }
                    if (isset(self::COMMAND_EXCLUDE_KEYS[$k])) {
                        continue;
                    }
                    $entry[$k] = $v;
                }
            } else {
                $entry['playlist'] =
                    ($type === 'sequence' && !str_ends_with($target, '.fseq'))
                        ? $target . '.fseq'
                        : $target;

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

            $entry['startDate'] = ($startDate['hard'] ?? null)
                ?: (($startDate['symbolic'] ?? null) ?: null);

            $entry['endDate']   = ($endDate['hard'] ?? null)
                ?: (($endDate['symbolic'] ?? null) ?: null);

            return $entry;
        };

        // ----------------------------
        // v2 manifest: identity + subEvents
        // ----------------------------
        if ($identity !== null && is_array($subEvents)) {
            $typeNorm = (string) ($identity['type'] ?? 'unknown');
            $target   = (string) ($identity['target'] ?? '');

            $rows = [];

            foreach ($subEvents as $sub) {
                if (!is_array($sub)) {
                    continue;
                }

                $timing = is_array($sub['timing'] ?? null)
                    ? (array) $sub['timing']
                    : (is_array($identity['timing'] ?? null) ? (array) $identity['timing'] : []);

                $payload = is_array($sub['payload'] ?? null) ? (array) $sub['payload'] : [];
                $behavior = is_array($sub['behavior'] ?? null) ? (array) $sub['behavior'] : [];

                if (!array_key_exists('enabled', $payload) && array_key_exists('enabled', $behavior)) {
                    $payload['enabled'] = $behavior['enabled'];
                }
                if (!array_key_exists('repeat', $payload) && array_key_exists('repeat', $behavior)) {
                    $payload['repeat'] = $behavior['repeat'];
                }
                if (!array_key_exists('stopType', $payload) && array_key_exists('stopType', $behavior)) {
                    $payload['stopType'] = $behavior['stopType'];
                }

                $rows[] = $renderOne($typeNorm, $target, $timing, $payload);
            }

            if ($rows === []) {
                $timing = is_array($identity['timing'] ?? null) ? (array) $identity['timing'] : [];
                $rows[] = $renderOne($typeNorm, $target, $timing, []);
            }

            return $rows;
        }

        // ----------------------------
        // Legacy flat event shape
        // ----------------------------
        $typeNorm = (string) ($event['type'] ?? 'unknown');
        $target   = (string) ($event['target'] ?? '');
        $payload  = is_array($event['payload'] ?? null) ? (array) $event['payload'] : [];
        $timing   = is_array($event['timing'] ?? null) ? (array) $event['timing'] : [];

        return [$renderOne($typeNorm, $target, $timing, $payload)];
    }

    /**
     * Convert a single normalized event into FPP schedule format.
     *
     * @param array<string,mixed> $event manifest-event
     * @return array<string,mixed> schedule.json entry
     */
    public function toScheduleEntry(array $event): array
    {
        $entries = $this->toScheduleEntries($event);

        return $entries[0] ?? [
            'enabled' => 1,
            'repeat' => 0,
            'stopType' => 0,
            'day' => 7,
            'playlist' => '',
            'sequence' => 0,
            'startTime' => null,
            'endTime' => null,
            'startTimeOffset' => 0,
            'endTimeOffset' => 0,
            'startDate' => null,
            'endDate' => null,
        ];
    }
}
