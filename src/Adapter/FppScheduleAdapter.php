<?php
declare(strict_types=1);

/**
 * Calendar Scheduler — Source Component
 *
 * File: Adapter/FppScheduleAdapter.php
 * Purpose: Defines the FppScheduleAdapter component used by the Calendar Scheduler Adapter layer.
 */

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
     * Split an FPP time field into canonical hard/symbolic parts.
     *
     * Sun tokens (Dawn, Dusk, SunRise, SunSet) must populate symbolic.
     * Hard clock times (HH:MM:SS) populate hard.
     *
     * @return array{hard: ?string, symbolic: ?string}
     */
    private function splitTimeHardSymbolic(?string $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return ['hard' => null, 'symbolic' => null];
        }

        $normalized = FPPSemantics::normalizeSymbolicTimeToken($value);
        if (FPPSemantics::isSymbolicTime($normalized)) {
            return [
                'hard'     => null,
                'symbolic' => $normalized,
            ];
        }

        return [
            'hard'     => $value,
            'symbolic' => null,
        ];
    }

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

        $yearHints = [];
        $globalYearHint = null;
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $key = $this->deriveEntryIdentityKey($entry);
            if ($key === null) {
                continue;
            }

            $startYear = $this->extractHardYear($entry['startDate'] ?? null);
            $endYear = $this->extractHardYear($entry['endDate'] ?? null);
            $candidateYears = array_values(array_filter([$startYear, $endYear], static fn($y) => is_int($y) && $y > 0));
            if ($candidateYears === []) {
                continue;
            }

            $candidate = min($candidateYears);
            if (!is_int($globalYearHint) || $candidate < $globalYearHint) {
                $globalYearHint = $candidate;
            }
            if (!isset($yearHints[$key]) || $candidate < $yearHints[$key]) {
                $yearHints[$key] = $candidate;
            }
        }

        /** @var array<string,array<string,mixed>> $aggregated */
        $aggregated = [];
        foreach ($raw as $entryIndex => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $key = $this->deriveEntryIdentityKey($entry);
            $yearHint = (is_string($key) && isset($yearHints[$key]))
                ? (int)$yearHints[$key]
                : (is_int($globalYearHint) ? $globalYearHint : null);
            $event = $this->fromScheduleEntry(
                $entry,
                $fppTz,
                $updatedAt,
                $yearHint,
                is_int($entryIndex) ? $entryIndex : null
            );

            $aggregateKey = $this->deriveManifestAggregateKey($event);
            if (!isset($aggregated[$aggregateKey])) {
                $aggregated[$aggregateKey] = $event;
                continue;
            }

            $existingSubs = is_array($aggregated[$aggregateKey]['subEvents'] ?? null)
                ? $aggregated[$aggregateKey]['subEvents']
                : [];
            $newSubs = is_array($event['subEvents'] ?? null)
                ? $event['subEvents']
                : [];
            $aggregated[$aggregateKey]['subEvents'] = array_merge($existingSubs, $newSubs);
        }

        foreach ($aggregated as &$event) {
            $subs = is_array($event['subEvents'] ?? null) ? $event['subEvents'] : [];
            usort($subs, fn(array $a, array $b): int => $this->compareSubEventsForManifest($a, $b));
            $event['subEvents'] = $subs;

            $identityTiming = $this->selectIdentityTiming($subs);
            if ($identityTiming !== []) {
                $event['timing'] = $identityTiming;
            }
        }
        unset($event);

        return array_values($aggregated);
    }

    /**
     * Convert a single FPP schedule entry into a canonical manifest-event array.
     *
     * @param array<string,mixed> $entry
     * @return array<string,mixed> manifest-event
     */
    public function fromScheduleEntry(
        array $entry,
        \DateTimeZone $fppTz,
        int $scheduleUpdatedAt,
        ?int $dateYearHint = null,
        ?int $executionOrder = null
    ): array
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

        $startSplit = $this->splitTimeHardSymbolic($entry['startTime'] ?? null);
        $endSplit   = $this->splitTimeHardSymbolic($entry['endTime'] ?? null);

        $timing = [
            'all_day' => $isAllDay,
            'start_date' => $startDateParts,
            'end_date'   => $endDateParts,
            'start_time' => $isAllDay ? null : [
                'hard'     => $startSplit['hard'],
                'symbolic' => $startSplit['symbolic'],
                'offset'   => (int) ($entry['startTimeOffset'] ?? 0),
            ],
            'end_time'   => $isAllDay ? null : [
                'hard'     => $endSplit['hard'],
                'symbolic' => $endSplit['symbolic'],
                'offset'   => (int) ($entry['endTimeOffset'] ?? 0),
            ],
            'days' => $normalizedDays === null
                ? null
                : [
                    'type'  => 'weekly',
                    'value' => $normalizedDays,
                ],
            'timezone' => $fppTz->getName(),
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
        $behavior = [
            'enabled'  => (bool)$payload['enabled'],
            'repeat'   => (string)$payload['repeat'],
            'stopType' => (string)$payload['stopType'],
        ];
        if (is_int($dateYearHint) && $dateYearHint > 0) {
            $payload['date_year_hint'] = $dateYearHint;
        }

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
            // One FPP schedule row maps to one manifest subevent. Aggregation in
            // loadManifestEvents() merges rows into a single manifest event.
            'subEvents' => [[
                'timing' => $timing,
                'payload' => $payload,
                'behavior' => $behavior,
                'executionOrder' => is_int($executionOrder) && $executionOrder >= 0 ? $executionOrder : 0,
            ]],
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
     * @param array<string,mixed> $entry
     */
    private function deriveEntryIdentityKey(array $entry): ?string
    {
        if (!empty($entry['command'])) {
            $type = 'command';
            $target = (string)($entry['command'] ?? '');
        } else {
            $type = (($entry['sequence'] ?? 0) ? 'sequence' : 'playlist');
            $target = preg_replace('/\.fseq$/i', '', (string)($entry['playlist'] ?? ''));
        }

        $type = FPPSemantics::normalizeType((string)$type);
        $target = trim((string)$target);
        if ($target === '') {
            return null;
        }

        return $type . '|' . $target;
    }

    private function extractHardYear(mixed $date): ?int
    {
        if (!is_string($date)) {
            return null;
        }
        $date = trim($date);
        if (!preg_match('/^(\d{4})-\d{2}-\d{2}$/', $date, $m)) {
            return null;
        }
        $year = (int)$m[1];
        return $year > 0 ? $year : null;
    }

    /**
     * Build a deterministic grouping key so related FPP rows become one manifest event
     * with multiple subEvents.
     *
     * @param array<string,mixed> $event
     */
    private function deriveManifestAggregateKey(array $event): string
    {
        $type = (string)($event['type'] ?? '');
        $target = (string)($event['target'] ?? '');
        $sub = (is_array($event['subEvents'] ?? null) && isset($event['subEvents'][0]) && is_array($event['subEvents'][0]))
            ? $event['subEvents'][0]
            : [];
        $timing = is_array($sub['timing'] ?? null) ? $sub['timing'] : [];
        $payload = is_array($sub['payload'] ?? null) ? $sub['payload'] : [];

        $shape = [
            'type' => $type,
            'target' => $target,
            'all_day' => (bool)($timing['all_day'] ?? false),
            'days' => $timing['days'] ?? null,
        ];

        return hash('sha256', json_encode($shape, JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     */
    private function compareSubEventsByStart(array $a, array $b): int
    {
        $timingA = is_array($a['timing'] ?? null) ? $a['timing'] : [];
        $timingB = is_array($b['timing'] ?? null) ? $b['timing'] : [];
        $dateA = is_array($timingA['start_date'] ?? null) ? $timingA['start_date'] : [];
        $dateB = is_array($timingB['start_date'] ?? null) ? $timingB['start_date'] : [];
        $timeA = is_array($timingA['start_time'] ?? null) ? $timingA['start_time'] : [];
        $timeB = is_array($timingB['start_time'] ?? null) ? $timingB['start_time'] : [];

        $keyA = implode('|', [
            (string)($dateA['hard'] ?? ''),
            (string)($dateA['symbolic'] ?? ''),
            (string)($timeA['hard'] ?? ''),
            (string)($timeA['symbolic'] ?? ''),
            (string)($timeA['offset'] ?? 0),
        ]);
        $keyB = implode('|', [
            (string)($dateB['hard'] ?? ''),
            (string)($dateB['symbolic'] ?? ''),
            (string)($timeB['hard'] ?? ''),
            (string)($timeB['symbolic'] ?? ''),
            (string)($timeB['offset'] ?? 0),
        ]);

        return $keyA <=> $keyB;
    }

    /**
     * Keep explicit scheduler order when available; otherwise default to
     * deterministic chronological ordering.
     *
     * @param array<string,mixed> $a
     * @param array<string,mixed> $b
     */
    private function compareSubEventsForManifest(array $a, array $b): int
    {
        $aOrder = $this->subEventExecutionOrder($a);
        $bOrder = $this->subEventExecutionOrder($b);
        if ($aOrder !== null && $bOrder !== null && $aOrder !== $bOrder) {
            return $aOrder <=> $bOrder;
        }

        $cmp = $this->compareSubEventsByStart($a, $b);
        if ($cmp !== 0) {
            return $cmp;
        }

        $aHash = is_string($a['stateHash'] ?? null) ? (string)$a['stateHash'] : '';
        $bHash = is_string($b['stateHash'] ?? null) ? (string)$b['stateHash'] : '';
        return strcmp($aHash, $bHash);
    }

    /**
     * @param array<string,mixed> $subEvent
     */
    private function subEventExecutionOrder(array $subEvent): ?int
    {
        $value = $subEvent['executionOrder'] ?? null;
        if (is_int($value)) {
            return $value >= 0 ? $value : 0;
        }
        if (is_string($value) && is_numeric($value)) {
            $n = (int)$value;
            return $n >= 0 ? $n : 0;
        }
        return null;
    }

    /**
     * @param array<int,array<string,mixed>> $subEvents
     * @return array<string,mixed>
     */
    private function selectIdentityTiming(array $subEvents): array
    {
        if ($subEvents === []) {
            return [];
        }

        $bestTiming = is_array($subEvents[0]['timing'] ?? null) ? $subEvents[0]['timing'] : [];
        $bestHash = is_string($subEvents[0]['stateHash'] ?? null) ? (string)$subEvents[0]['stateHash'] : '';
        foreach ($subEvents as $subEvent) {
            $timing = is_array($subEvent['timing'] ?? null) ? $subEvent['timing'] : [];
            $hash = is_string($subEvent['stateHash'] ?? null) ? (string)$subEvent['stateHash'] : '';
            $cmp = strcmp(
                $this->timingIdentityKey($timing),
                $this->timingIdentityKey($bestTiming)
            );
            if ($cmp < 0 || ($cmp === 0 && strcmp($hash, $bestHash) < 0)) {
                $bestTiming = $timing;
                $bestHash = $hash;
            }
        }

        return $bestTiming;
    }

    /**
     * @param array<string,mixed> $timing
     */
    private function timingIdentityKey(array $timing): string
    {
        $startDate = is_array($timing['start_date'] ?? null) ? $timing['start_date'] : [];
        $startTime = is_array($timing['start_time'] ?? null) ? $timing['start_time'] : [];
        $hardDate = is_string($startDate['hard'] ?? null) ? trim((string)$startDate['hard']) : '';
        $symDate = is_string($startDate['symbolic'] ?? null) ? trim((string)$startDate['symbolic']) : '';
        $hardTime = is_string($startTime['hard'] ?? null) ? trim((string)$startTime['hard']) : '';
        $symTime = is_string($startTime['symbolic'] ?? null) ? trim((string)$startTime['symbolic']) : '';

        return implode('|', [
            $hardDate !== '' ? $hardDate : '9999-99-99',
            $symDate !== '' ? $symDate : '~',
            $hardTime !== '' ? $hardTime : '99:99:99',
            $symTime !== '' ? $symTime : '~',
            sprintf('%+06d', (int)($startTime['offset'] ?? 0)),
            !empty($timing['all_day']) ? '1' : '0',
        ]);
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

        // v2 contract: reverse mapping uses canonical identity fields.
        $typeNorm = (string) ($identity['type'] ?? '');
        $target   = (string) ($identity['target'] ?? '');

        if ($typeNorm === '' || $typeNorm === 'unknown' || trim($target) === '') {
            throw new \InvalidArgumentException(
                'Manifest event missing canonical identity fields (type/target) for FPP denormalization.'
            );
        }

        // Denormalize type to FPP representation expectations
        $type = FPPSemantics::denormalizeType($typeNorm);

        $enabledSemantic = $behavior['enabled'] ?? ($payload['enabled'] ?? true);
        $repeatSemantic  = $behavior['repeat']  ?? ($payload['repeat']  ?? 'none');
        $stopTypeSemantic = $behavior['stopType'] ?? ($payload['stopType'] ?? null);

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

            /**
             * Reverse mapping symmetry:
             * - command.name -> entry.command
             * - if missing, fall back to summary then identity target
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

                // Canonical multisync keys (preferred)
                if ($k === 'multisyncCommand') {
                    $entry['multisyncCommand'] = (bool) $v;
                    continue;
                }

                if ($k === 'multisyncHosts') {
                    $entry['multisyncHosts'] = (string) $v;
                    continue;
                }

                // Legacy support: multisync / hosts (normalize to FPP keys)
                if ($k === 'multisync') {
                    $entry['multisyncCommand'] = (bool) $v;
                    continue;
                }

                if ($k === 'hosts') {
                    $entry['multisyncHosts'] = (string) $v;
                    continue;
                }

                $entry[$k] = $v;
            }

            // FPP scheduler UI expects command rows to always carry args as an array.
            if (!array_key_exists('args', $entry) || !is_array($entry['args'])) {
                $entry['args'] = [];
            }

            // Ensure FPP-native multisync defaults (never null)
            if (!array_key_exists('multisyncCommand', $entry)) {
                $entry['multisyncCommand'] = false;
            }
            if (!array_key_exists('multisyncHosts', $entry)) {
                $entry['multisyncHosts'] = '';
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

            $symbolicStart = $startTime['symbolic'] ?? null;
            $symbolicEnd   = $endTime['symbolic'] ?? null;

            if (is_string($symbolicStart) && $symbolicStart !== '') {
                $entry['startTime'] = FPPSemantics::normalizeSymbolicTimeToken($symbolicStart);
            } else {
                $entry['startTime'] = $startTime['hard'] ?? null;
            }

            if (is_string($symbolicEnd) && $symbolicEnd !== '') {
                $entry['endTime'] = FPPSemantics::normalizeSymbolicTimeToken($symbolicEnd);
            } else {
                $entry['endTime'] = $endTime['hard'] ?? null;
            }

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
