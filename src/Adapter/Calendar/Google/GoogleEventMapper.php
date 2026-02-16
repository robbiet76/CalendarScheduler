<?php
declare(strict_types=1);

namespace CalendarScheduler\Adapter\Calendar\Google;

use CalendarScheduler\Diff\ReconciliationAction;
use CalendarScheduler\Platform\HolidayResolver;
use RuntimeException;

/**
 * GoogleEventMapper (V2)
 *
 * Pure mapper from resolved ReconciliationAction → Google Calendar mutations.
 *
 * IMPORTANT INVARIANTS:
 * - Resolution output is authoritative.
 * - Each resolved SubEvent is an atomic execution unit.
 * - Grouping is conservative: default is 1 SubEvent → 1 Google event.
 * - No I/O. No API calls. No normalization.
 */
final class GoogleEventMapper
{
    private const MANAGED_FORMAT_VERSION = '2';

    private bool $debugCalendar;
    private ?HolidayResolver $holidayResolver = null;
    private \DateTimeZone $localTimezone;

    /** @var array<string,mixed> */
    private array $diagnostics = [
        'unmappable_skipped' => 0,
        'update_noop_nomappable' => 0,
        'update_format_only' => 0,
        'update_skipped_missing_delete_ids' => 0,
        'unmappable_reasons' => [],
    ];

    public function __construct()
    {
        $this->debugCalendar = getenv('GCS_DEBUG_CALENDAR') === '1';
        $this->localTimezone = $this->resolveLocalTimezone();
        $this->holidayResolver = $this->loadHolidayResolver();
    }

    /**
     * Map a reconciliation action into one or more Google mutations.
     *
     * @return list<GoogleMutation>
     */
    public function mapAction(ReconciliationAction $action, GoogleConfig $config): array
    {
        if ($action->target !== ReconciliationAction::TARGET_CALENDAR) {
            throw new RuntimeException('GoogleEventMapper received non-calendar action');
        }

        $event = $action->event;
        $subEvents = $event['subEvents'] ?? null;

        if (!is_array($subEvents) || $subEvents === []) {
            throw new RuntimeException('Manifest event has no resolved subEvents');
        }

        $calendarId = $config->getCalendarId();

        return match ($action->type) {
            ReconciliationAction::TYPE_CREATE =>
                $this->mapCreate($action, $subEvents, $calendarId),

            ReconciliationAction::TYPE_UPDATE =>
                $this->mapUpdate($action, $subEvents, $calendarId),

            ReconciliationAction::TYPE_DELETE =>
                $this->mapDelete($action, $calendarId),

            default =>
                throw new RuntimeException('Unsupported reconciliation action type'),
        };
    }

    /**
     * CREATE semantics
     *
     * Default: one Google event per SubEvent.
     *
     * @param ReconciliationAction $action
     * @param array<int, array<string,mixed>> $subEvents
     * @param string $calendarId
     * @return list<GoogleMutation>
     */
    private function mapCreate(ReconciliationAction $action, array $subEvents, string $calendarId): array
    {
        $mutations = [];

        foreach ($subEvents as $subEvent) {
            if (!is_array($subEvent)) {
                continue;
            }

            $subEventHash = $this->deriveSubEventHash($subEvent);
            try {
                $payload = $this->buildPayload($action, $subEvent);
            } catch (RuntimeException $e) {
                if (!$this->isUnmappableTimingError($e)) {
                    throw $e;
                }
                $reason = $e->getMessage();
                $this->diagnostics['unmappable_skipped']++;
                if (!isset($this->diagnostics['unmappable_reasons'][$reason])) {
                    $this->diagnostics['unmappable_reasons'][$reason] = 0;
                }
                $this->diagnostics['unmappable_reasons'][$reason]++;
                $this->debug(
                    'GoogleEventMapper: skipping create for unmappable timing ' .
                    'identityHash=' . $action->identityHash .
                    ' subEventHash=' . $subEventHash .
                    ' reason=' . $reason
                );
                continue;
            }

            $mutations[] = new GoogleMutation(
                op: GoogleMutation::OP_CREATE,
                calendarId: $calendarId,
                googleEventId: null,
                payload: $payload,
                manifestEventId: $action->identityHash,
                subEventHash: $subEventHash
            );
        }

        return $mutations;
    }

    /**
     * UPDATE semantics
     *
     * Step C strategy: destructive replace.
     *
     * - Treat update as delete existing + create desired.
     * - Avoid partial patch behavior without persisted provider-state mapping.
     *
     * @param ReconciliationAction $action
     * @param array<int, array<string,mixed>> $subEvents
     * @param string $calendarId
     * @return list<GoogleMutation>
     */
    private function mapUpdate(ReconciliationAction $action, array $subEvents, string $calendarId): array
    {
        $createMutations = $this->mapCreate($action, $subEvents, $calendarId);
        if ($createMutations === []) {
            $formatOnlyUpdates = $this->mapFormatOnlyUpdates($action, $subEvents, $calendarId);
            if ($formatOnlyUpdates !== []) {
                $this->diagnostics['update_format_only'] += count($formatOnlyUpdates);
                $this->debug(
                    'GoogleEventMapper: update mapped as format-only patch(es) ' .
                    'identityHash=' . $action->identityHash .
                    ' count=' . count($formatOnlyUpdates)
                );
                return $formatOnlyUpdates;
            }

            $this->diagnostics['update_noop_nomappable']++;
            $this->debug(
                'GoogleEventMapper: update reduced to no-op (no mappable create payloads) ' .
                'identityHash=' . $action->identityHash
            );
            return [];
        }

        $deleteMutations = [];
        try {
            $deleteMutations = $this->mapDelete($action, $calendarId);
        } catch (RuntimeException $e) {
            // If legacy/current manifest rows do not carry resolvable Google ids,
            // skip update to avoid create-only drift (duplicate/recreated events).
            if (strpos($e->getMessage(), 'no resolvable Google event ids') === false) {
                throw $e;
            }
            $this->diagnostics['update_skipped_missing_delete_ids']++;
            $this->debug(
                'GoogleEventMapper: update skipped (missing delete ids) ' .
                'identityHash=' . $action->identityHash
            );
            return [];
        }

        return [
            ...$deleteMutations,
            ...$createMutations,
        ];
    }

    /**
     * Build format-only update mutations when timing is unmappable.
     *
     * This allows canonical description/metadata normalization to be applied
     * without attempting start/end timing rewrites.
     *
     * @param array<int, array<string,mixed>> $subEvents
     * @return list<GoogleMutation>
     */
    private function mapFormatOnlyUpdates(
        ReconciliationAction $action,
        array $subEvents,
        string $calendarId
    ): array {
        $mutations = [];
        $seenIds = [];

        foreach ($subEvents as $subEvent) {
            if (!is_array($subEvent)) {
                continue;
            }

            $eventId = $this->extractGoogleEventId($action, $subEvent);
            if (!is_string($eventId) || $eventId === '') {
                continue;
            }
            if (isset($seenIds[$eventId])) {
                continue;
            }
            $seenIds[$eventId] = true;

            $payload = $this->buildFormatOnlyPayload($action, $subEvent);
            $subEventHash = $this->deriveSubEventHash($subEvent);

            $mutations[] = new GoogleMutation(
                op: GoogleMutation::OP_UPDATE,
                calendarId: $calendarId,
                googleEventId: $eventId,
                payload: $payload,
                manifestEventId: $action->identityHash,
                subEventHash: $subEventHash
            );
        }

        return $mutations;
    }

    /**
     * Build a payload that only normalizes description + private metadata.
     *
     * @param array<string,mixed> $subEvent
     * @return array<string,mixed>
     */
    private function buildFormatOnlyPayload(ReconciliationAction $action, array $subEvent): array
    {
        $payloadIn = is_array($subEvent['payload'] ?? null) ? $subEvent['payload'] : [];
        $behaviorIn = is_array($subEvent['behavior'] ?? null) ? $subEvent['behavior'] : [];
        $timing = is_array($subEvent['timing'] ?? null) ? $subEvent['timing'] : [];

        $summary = is_string($payloadIn['summary'] ?? null) && trim((string)$payloadIn['summary']) !== ''
            ? (string)$payloadIn['summary']
            : (string)($action->event['identity']['target'] ?? '');
        $descriptionIn = is_string($payloadIn['description'] ?? null)
            ? (string)$payloadIn['description']
            : '';

        $identity = is_array($action->event['identity'] ?? null) ? $action->event['identity'] : [];
        $identityType = is_string($identity['type'] ?? null) ? (string)$identity['type'] : '';
        $enabled = (bool)($behaviorIn['enabled'] ?? ($payloadIn['enabled'] ?? true));
        $repeat = is_string($behaviorIn['repeat'] ?? null)
            ? (string)$behaviorIn['repeat']
            : (string)($payloadIn['repeat'] ?? 'none');
        $stopType = is_string($behaviorIn['stopType'] ?? null)
            ? (string)$behaviorIn['stopType']
            : (string)($payloadIn['stopType'] ?? 'graceful');

        $startTime = is_array($timing['start_time'] ?? null) ? $timing['start_time'] : null;
        $endTime = is_array($timing['end_time'] ?? null) ? $timing['end_time'] : null;
        $description = $this->composeManagedDescription(
            $descriptionIn,
            $identityType,
            $enabled,
            $repeat,
            $stopType,
            $startTime,
            $endTime
        );

        $subEventHash = $this->deriveSubEventHash($subEvent);
        return [
            'summary' => $summary,
            'description' => $description,
            'extendedProperties' => [
                'private' => GoogleEventMetadataSchema::privateMetadata(
                    $action->identityHash,
                    $subEventHash,
                    'google',
                    self::MANAGED_FORMAT_VERSION,
                    $identityType !== '' ? $identityType : null,
                    $enabled,
                    $repeat !== '' ? $repeat : null,
                    $stopType !== '' ? $stopType : null,
                    is_string($timing['start_time']['symbolic'] ?? null)
                        ? trim((string)$timing['start_time']['symbolic'])
                        : null,
                    isset($timing['start_time']['offset']) ? (int)$timing['start_time']['offset'] : null,
                    is_string($timing['end_time']['symbolic'] ?? null)
                        ? trim((string)$timing['end_time']['symbolic'])
                        : null,
                    isset($timing['end_time']['offset']) ? (int)$timing['end_time']['offset'] : null
                ),
            ],
        ];
    }

    private function isUnmappableTimingError(RuntimeException $e): bool
    {
        $msg = $e->getMessage();
        return strpos($msg, 'requires hard start_date') !== false
            || strpos($msg, 'requires hard end_date') !== false
            || strpos($msg, 'does not support symbolic start_time') !== false
            || strpos($msg, 'does not support symbolic end_time') !== false;
    }

    public function emitDiagnosticsSummary(): void
    {
        $unmappableSkipped = (int)($this->diagnostics['unmappable_skipped'] ?? 0);
        $updateNoopNoMappable = (int)($this->diagnostics['update_noop_nomappable'] ?? 0);
        $updateFormatOnly = (int)($this->diagnostics['update_format_only'] ?? 0);
        $updateSkippedMissingDeleteIds = (int)($this->diagnostics['update_skipped_missing_delete_ids'] ?? 0);
        $reasons = is_array($this->diagnostics['unmappable_reasons'] ?? null)
            ? $this->diagnostics['unmappable_reasons']
            : [];

        if (
            $unmappableSkipped === 0
            && $updateNoopNoMappable === 0
            && $updateFormatOnly === 0
            && $updateSkippedMissingDeleteIds === 0
        ) {
            return;
        }

        $reasonParts = [];
        foreach ($reasons as $reason => $count) {
            if (is_string($reason) && is_int($count) && $count > 0) {
                $reasonParts[] = $reason . '=' . $count;
            }
        }
        sort($reasonParts);
        $reasonSummary = $reasonParts !== [] ? ' unmappable_reasons={' . implode(', ', $reasonParts) . '}' : '';

        error_log(
            'GoogleEventMapper summary:' .
            ' unmappable_skipped=' . $unmappableSkipped .
            ' update_noop_nomappable=' . $updateNoopNoMappable .
            ' update_format_only=' . $updateFormatOnly .
            ' update_skipped_missing_delete_ids=' . $updateSkippedMissingDeleteIds .
            $reasonSummary
        );
    }

    private function debug(string $message): void
    {
        if ($this->debugCalendar) {
            error_log($message);
        }
    }

    /**
     * DELETE semantics
     *
     * @param ReconciliationAction $action
     * @param string $calendarId
     * @return list<GoogleMutation>
     */
    private function mapDelete(ReconciliationAction $action, string $calendarId): array
    {
        $event = $action->event ?? null;
        if (!is_array($event)) {
            throw new RuntimeException('Delete action missing manifest event payload');
        }

        $subEvents = $event['subEvents'] ?? null;
        if (!is_array($subEvents) || $subEvents === []) {
            throw new RuntimeException('Delete action missing subEvents');
        }

        $ids = [];

        // Primary correlation id (calendar-originated events)
        $sourceEventUid = $event['correlation']['sourceEventUid'] ?? null;
        if (is_string($sourceEventUid) && $sourceEventUid !== '') {
            $ids[$sourceEventUid] = true;
        }

        foreach ($subEvents as $subEvent) {
            if (!is_array($subEvent)) {
                continue;
            }
            $id = $this->extractGoogleEventId($action, $subEvent);
            if (is_string($id) && $id !== '') {
                $ids[$id] = true;
            }
        }

        $corrIds = $event['correlation']['googleEventIds'] ?? null;
        if (is_array($corrIds)) {
            foreach ($corrIds as $id) {
                if (is_string($id) && $id !== '') {
                    $ids[$id] = true;
                }
            }
        }

        if ($ids === []) {
            throw new RuntimeException(
                'Delete action has no resolvable Google event ids (missing correlation.sourceEventUid and payload googleEventId).'
            );
        }

        $mutations = [];
        foreach (array_keys($ids) as $eventId) {
            $mutations[] = new GoogleMutation(
                op: GoogleMutation::OP_DELETE,
                calendarId: $calendarId,
                googleEventId: $eventId,
                payload: [],
                manifestEventId: $action->identityHash,
                subEventHash: 'delete:' . $eventId
            );
        }

        return $mutations;
    }

    /**
     * Build Google API payload from a single resolved SubEvent.
     *
     * @param ReconciliationAction $action
     * @param array<string,mixed> $subEvent
     * @return array<string,mixed>
     */
    private function buildPayload(ReconciliationAction $action, array $subEvent): array
    {
        $timing = $subEvent['timing'] ?? null;
        $payloadIn = $subEvent['payload'] ?? null;
        $behaviorIn = $subEvent['behavior'] ?? null;

        if (!is_array($timing) || !is_array($payloadIn)) {
            throw new RuntimeException('Invalid SubEvent manifest shape: expected timing+payload arrays');
        }
        $behaviorIn = is_array($behaviorIn) ? $behaviorIn : [];

        $tz = is_string($timing['timezone'] ?? null) && $timing['timezone'] !== ''
            ? $timing['timezone']
            : 'UTC';

        [$start, $end] = $this->buildGoogleStartEndFromTiming($timing, $payloadIn);
        $subEventHash = $this->deriveSubEventHash($subEvent);

        $summary = is_string($payloadIn['summary'] ?? null) && trim((string)$payloadIn['summary']) !== ''
            ? (string)$payloadIn['summary']
            : (string)($action->event['identity']['target'] ?? '');

        $descriptionIn = is_string($payloadIn['description'] ?? null)
            ? (string)$payloadIn['description']
            : '';

        $identity = is_array($action->event['identity'] ?? null) ? $action->event['identity'] : [];
        $identityType = is_string($identity['type'] ?? null) ? (string)$identity['type'] : '';
        $enabled = (bool)($behaviorIn['enabled'] ?? ($payloadIn['enabled'] ?? true));
        $repeat = is_string($behaviorIn['repeat'] ?? null)
            ? (string)$behaviorIn['repeat']
            : (string)($payloadIn['repeat'] ?? 'none');
        $stopType = is_string($behaviorIn['stopType'] ?? null)
            ? (string)$behaviorIn['stopType']
            : (string)($payloadIn['stopType'] ?? 'graceful');

        $description = $this->composeManagedDescription(
            $descriptionIn,
            $identityType,
            $enabled,
            $repeat,
            $stopType,
            is_array($timing['start_time'] ?? null) ? $timing['start_time'] : null,
            is_array($timing['end_time'] ?? null) ? $timing['end_time'] : null
        );

        $payload = [
            'summary'     => $summary,
            'description' => $description,
            'start'       => $this->mapDateTime($start, $tz),
            'end'         => $this->mapDateTime($end, $tz),
            'extendedProperties' => [
                'private' => GoogleEventMetadataSchema::privateMetadata(
                    $action->identityHash,
                    $subEventHash,
                    'google',
                    self::MANAGED_FORMAT_VERSION,
                    $identityType !== '' ? $identityType : null,
                    $enabled,
                    $repeat !== '' ? $repeat : null,
                    $stopType !== '' ? $stopType : null,
                    is_string($timing['start_time']['symbolic'] ?? null)
                        ? trim((string)$timing['start_time']['symbolic'])
                        : null,
                    isset($timing['start_time']['offset']) ? (int)$timing['start_time']['offset'] : null,
                    is_string($timing['end_time']['symbolic'] ?? null)
                        ? trim((string)$timing['end_time']['symbolic'])
                        : null,
                    isset($timing['end_time']['offset']) ? (int)$timing['end_time']['offset'] : null
                ),
            ],
        ];
        $recurrence = $this->buildGoogleRecurrenceFromTiming($timing, $tz);
        if ($recurrence !== []) {
            $payload['recurrence'] = $recurrence;
        }

        return $payload;
    }

    private function composeManagedDescription(
        string $existingDescription,
        string $type,
        bool $enabled,
        string $repeat,
        string $stopType,
        ?array $startTime,
        ?array $endTime
    ): string {
        $startSym = is_string($startTime['symbolic'] ?? null) ? trim((string)$startTime['symbolic']) : '';
        $endSym = is_string($endTime['symbolic'] ?? null) ? trim((string)$endTime['symbolic']) : '';
        $startOffset = isset($startTime['offset']) ? (int)($startTime['offset']) : 0;
        $endOffset = isset($endTime['offset']) ? (int)($endTime['offset']) : 0;

        $settings = [];
        $settings[] = '# Managed by Calendar Scheduler';
        $settings[] = '# Edit values below. Free-form notes can be added at the bottom.';
        $settings[] = '';
        $settings[] = '[settings]';
        $settings[] = '# Edit FPP Scheduler Settings';
        $settings[] = '# Schedule Type: Playlist | Sequence | Command';
        $settings[] = '# Enabled: True | False';
        $settings[] = '# Repeat: None | Immediate | 5 | 10 | 15 | 20 | 30 | 60 (Min.)';
        $settings[] = '# Stop Type: Graceful | Graceful Loop | Hard Stop';
        $settings[] = '';
        $settings[] = 'type = ' . $this->formatTypeForDescription($type);
        $settings[] = 'enabled = ' . ($enabled ? 'True' : 'False');
        $settings[] = 'repeat = ' . $this->formatRepeatForDescription($repeat);
        $settings[] = 'stopType = ' . $this->formatStopTypeForDescription($stopType);
        $settings[] = '';
        $settings[] = '[symbolic_time]';
        $settings[] = '# Edit Symbolic Time Settings';
        $settings[] = '# Start Time/End Time: Dawn | SunRise | SunSet | Dusk';
        $settings[] = '# Start Time/End Time Offset Min: (Enter +/- minutes)';
        $settings[] = '# Leave values blank to use hard clock time from event start/end.';
        $settings[] = '';
        $settings[] = 'start = ' . $startSym;
        $settings[] = 'start_offset = ' . (string)$startOffset;
        $settings[] = 'end = ' . $endSym;
        $settings[] = 'end_offset = ' . (string)$endOffset;
        $settings[] = '';
        $settings[] = '# Notes:';
        $settings[] = '# - Calendar Event Title should match Playlist/Sequence/Command name.';
        $settings[] = '';
        $settings[] = '# -------------------- USER NOTES BELOW --------------------';

        $sections = [implode("\n", $settings)];

        $existingDescription = trim($this->stripManagedSections($existingDescription));
        if ($existingDescription !== '') {
            $sections[] = $existingDescription;
        }

        return implode("\n\n", $sections);
    }

    private function stripManagedSections(string $description): string
    {
        $divider = '# -------------------- USER NOTES BELOW --------------------';
        $pos = strpos($description, $divider);
        if ($pos !== false) {
            $notes = substr($description, $pos + strlen($divider));
            return trim((string)$notes);
        }

        $lines = preg_split('/\r\n|\r|\n/', $description);
        if (!is_array($lines) || $lines === []) {
            return trim($description);
        }

        $out = [];
        $inManaged = false;
        $seenMarker = false;

        foreach ($lines as $line) {
            $trim = trim((string)$line);
            $lower = strtolower($trim);

            if (!$seenMarker && $lower === '# managed by calendar scheduler') {
                $seenMarker = true;
                continue;
            }
            if ($seenMarker && $trim === '# edit values below. free-form notes can be added at the bottom.') {
                continue;
            }

            if (!$inManaged && ($lower === '[settings]' || $lower === '[symbolic_time]')) {
                $inManaged = true;
                continue;
            }

            if ($inManaged) {
                if ($trim === '') {
                    $inManaged = false;
                }
                continue;
            }

            $out[] = (string)$line;
        }

        return trim(implode("\n", $out));
    }

    private function formatTypeForDescription(string $type): string
    {
        return match (strtolower(trim($type))) {
            'sequence' => 'Sequence',
            'command' => 'Command',
            default => 'Playlist',
        };
    }

    private function formatRepeatForDescription(string $repeat): string
    {
        $r = strtolower(trim($repeat));
        if ($r === 'immediate') {
            return 'Immediate';
        }
        if ($r === 'none' || $r === '') {
            return 'None';
        }
        if (preg_match('/^(\d+)min$/', $r, $m) === 1) {
            return $m[1];
        }
        if (ctype_digit($r)) {
            $n = (int)$r;
            if ($n > 0) {
                return (string)$n;
            }
        }

        return 'None';
    }

    private function formatStopTypeForDescription(string $stopType): string
    {
        $v = strtolower(trim($stopType));
        return match ($v) {
            'hard', 'hard_stop', 'hard stop' => 'Hard Stop',
            'graceful_loop', 'graceful loop' => 'Graceful Loop',
            default => 'Graceful',
        };
    }

    /**
     * @param array<string,mixed> $dt
     * @param string $timezone
     * @return array<string,string>
     */
    private function mapDateTime(array $dt, string $timezone): array
    {
        if (!empty($dt['allDay'])) {
            return ['date' => $dt['date']];
        }

        return [
            'dateTime' => $dt['date'] . 'T' . $dt['time'],
            'timeZone' => $timezone,
        ];
    }

    /**
     * @param array<string,mixed> $subEvent
     */
    private function deriveSubEventHash(array $subEvent): string
    {
        $stateHash = $subEvent['stateHash'] ?? null;
        if (is_string($stateHash) && $stateHash !== '') {
            return $stateHash;
        }

        return hash('sha256', json_encode([
            'timing' => $subEvent['timing'] ?? null,
            'payload' => $subEvent['payload'] ?? null,
            'behavior' => $subEvent['behavior'] ?? null,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Resolve a Google event id from manifest event/subevent metadata when available.
     *
     * @param array<string,mixed> $subEvent
     */
    private function extractGoogleEventId(ReconciliationAction $action, array $subEvent): ?string
    {
        $id = $subEvent['payload']['googleEventId'] ?? null;
        if (is_string($id) && $id !== '') {
            return $id;
        }

        $subEventHash = $this->deriveSubEventHash($subEvent);
        $corrIds = $action->event['correlation']['googleEventIds'] ?? null;
        if (is_array($corrIds)) {
            $corrId = $corrIds[$subEventHash] ?? null;
            if (is_string($corrId) && $corrId !== '') {
                return $corrId;
            }
        }

        $corrId = $action->event['correlation']['sourceEventUid'] ?? null;
        if (is_string($corrId) && $corrId !== '') {
            return $corrId;
        }

        return null;
    }

    /**
     * Convert manifest-v2 subEvent timing into Google start/end blocks.
     *
     * @param array<string,mixed> $timing
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    private function buildGoogleStartEndFromTiming(array $timing, array $payload = []): array
    {
        $allDay = (bool)($timing['all_day'] ?? false);
        $startDate = $timing['start_date']['hard'] ?? null;
        $endDate = $timing['end_date']['hard'] ?? null;

        if ((!is_string($startDate) || $startDate === '') || (!is_string($endDate) || $endDate === '')) {
            [$resolvedStart, $resolvedEnd] = $this->resolveSymbolicDateBounds($timing, $payload);
            if (!is_string($startDate) || $startDate === '') {
                $startDate = $resolvedStart;
            }
            if (!is_string($endDate) || $endDate === '') {
                $endDate = $resolvedEnd;
            }
        }

        if (!is_string($startDate) || $startDate === '') {
            throw new RuntimeException('Google mapping requires hard start_date');
        }
        if (!is_string($endDate) || $endDate === '') {
            throw new RuntimeException('Google mapping requires hard end_date');
        }

        if ($allDay) {
            $endExclusive = (new \DateTimeImmutable($endDate, new \DateTimeZone('UTC')))
                ->modify('+1 day')
                ->format('Y-m-d');

            return [
                ['date' => $startDate, 'allDay' => true],
                ['date' => $endExclusive, 'allDay' => true],
            ];
        }

        $startTime = $timing['start_time']['hard'] ?? null;
        $endTime = $timing['end_time']['hard'] ?? null;
        $startSymbolic = $timing['start_time']['symbolic'] ?? null;
        $endSymbolic = $timing['end_time']['symbolic'] ?? null;

        if (is_string($startSymbolic) && $startSymbolic !== '') {
            throw new RuntimeException('Google mapping does not support symbolic start_time');
        }
        if (is_string($endSymbolic) && $endSymbolic !== '') {
            throw new RuntimeException('Google mapping does not support symbolic end_time');
        }

        if (!is_string($startTime) || $startTime === '') {
            $startTime = '00:00:00';
        }
        if (!is_string($endTime) || $endTime === '') {
            $endTime = $startTime;
        }

        // Google rejects timed events where end <= start.
        // Normalize common FPP-style edge cases:
        // - overnight windows encoded on same date (end < start) => roll end +1 day
        // - zero-length windows (end == start) => force minimal +1 minute duration
        $startDt = new \DateTimeImmutable($startDate . ' ' . $startTime, new \DateTimeZone('UTC'));
        $endDt = new \DateTimeImmutable($endDate . ' ' . $endTime, new \DateTimeZone('UTC'));
        if ($endDt <= $startDt) {
            if ($endDate === $startDate && strcmp($endTime, $startTime) < 0) {
                $endDt = $endDt->modify('+1 day');
            } else {
                $endDt = $startDt->modify('+1 minute');
            }
            $endDate = $endDt->format('Y-m-d');
            $endTime = $endDt->format('H:i:s');
        }

        return [
            ['date' => $startDate, 'time' => $startTime, 'allDay' => false],
            ['date' => $endDate, 'time' => $endTime, 'allDay' => false],
        ];
    }

    /**
     * Build Google RRULE recurrence from canonical timing.
     *
     * @param array<string,mixed> $timing
     * @return array<int,string>
     */
    private function buildGoogleRecurrenceFromTiming(array $timing, string $timezone): array
    {
        $startDate = is_string($timing['start_date']['hard'] ?? null) ? trim((string)$timing['start_date']['hard']) : '';
        $endDate = is_string($timing['end_date']['hard'] ?? null) ? trim((string)$timing['end_date']['hard']) : '';
        if ($startDate === '' || $endDate === '') {
            return [];
        }

        $tz = null;
        try {
            $tz = new \DateTimeZone($timezone);
        } catch (\Throwable) {
            $tz = new \DateTimeZone('UTC');
        }

        $untilLocal = new \DateTimeImmutable($endDate . ' 23:59:59', $tz);
        $untilUtc = $untilLocal
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Ymd\THis\Z');

        $days = null;
        if (
            is_array($timing['days'] ?? null)
            && (($timing['days']['type'] ?? null) === 'weekly')
            && is_array($timing['days']['value'] ?? null)
        ) {
            $rawDays = array_values(array_filter(
                $timing['days']['value'],
                static fn($d): bool => is_string($d) && trim($d) !== ''
            ));
            $days = array_map(static fn($d): string => strtoupper(trim((string)$d)), $rawDays);
        }

        $parts = [];
        if (is_array($days) && $days !== []) {
            $parts[] = 'FREQ=WEEKLY';
            $parts[] = 'BYDAY=' . implode(',', $days);
        } else {
            $parts[] = 'FREQ=DAILY';
        }
        $parts[] = 'UNTIL=' . $untilUtc;

        return ['RRULE:' . implode(';', $parts)];
    }

    /**
     * @param array<string,mixed> $timing
     * @param array<string,mixed> $payload
     * @return array{0:?string,1:?string}
     */
    private function resolveSymbolicDateBounds(array $timing, array $payload): array
    {
        if (!($this->holidayResolver instanceof HolidayResolver)) {
            return [null, null];
        }

        $startHard = is_string($timing['start_date']['hard'] ?? null) ? trim((string)$timing['start_date']['hard']) : null;
        $endHard = is_string($timing['end_date']['hard'] ?? null) ? trim((string)$timing['end_date']['hard']) : null;
        $startSym = is_string($timing['start_date']['symbolic'] ?? null) ? trim((string)$timing['start_date']['symbolic']) : '';
        $endSym = is_string($timing['end_date']['symbolic'] ?? null) ? trim((string)$timing['end_date']['symbolic']) : '';

        $hintYear = (isset($payload['date_year_hint']) && is_numeric($payload['date_year_hint']))
            ? (int)$payload['date_year_hint']
            : 0;

        $anchorYear = $hintYear > 0
            ? $hintYear
            : ($this->extractYearFromHardDate($startHard)
                ?? $this->extractYearFromHardDate($endHard)
                ?? (int)(new \DateTimeImmutable('now', $this->localTimezone))->format('Y'));

        $startDerived = false;
        $endDerived = false;

        if (($startHard === null || $startHard === '') && $startSym !== '') {
            $resolved = $this->holidayResolver->dateFromHoliday($startSym, $anchorYear);
            if ($resolved instanceof \DateTimeImmutable) {
                $startHard = $resolved->format('Y-m-d');
                $startDerived = true;
            }
        }

        if (($endHard === null || $endHard === '') && $endSym !== '') {
            $resolved = $this->holidayResolver->dateFromHoliday($endSym, $anchorYear);
            if ($resolved instanceof \DateTimeImmutable) {
                $endHard = $resolved->format('Y-m-d');
                $endDerived = true;
            }
        }

        if (is_string($startHard) && $startHard !== '' && is_string($endHard) && $endHard !== '' && strcmp($endHard, $startHard) < 0) {
            if ($endDerived && $endSym !== '') {
                $startYear = $this->extractYearFromHardDate($startHard);
                if (is_int($startYear) && $startYear > 0) {
                    $resolved = $this->holidayResolver->dateFromHoliday($endSym, $startYear + 1);
                    if ($resolved instanceof \DateTimeImmutable) {
                        $endHard = $resolved->format('Y-m-d');
                    }
                }
            } elseif ($startDerived && $startSym !== '') {
                $endYear = $this->extractYearFromHardDate($endHard);
                if (is_int($endYear) && $endYear > 0) {
                    $resolved = $this->holidayResolver->dateFromHoliday($startSym, $endYear - 1);
                    if ($resolved instanceof \DateTimeImmutable) {
                        $startHard = $resolved->format('Y-m-d');
                    }
                }
            }
        }

        return [
            (is_string($startHard) && $startHard !== '') ? $startHard : null,
            (is_string($endHard) && $endHard !== '') ? $endHard : null,
        ];
    }

    private function extractYearFromHardDate(?string $date): ?int
    {
        if (!is_string($date) || $date === '') {
            return null;
        }
        if (!preg_match('/^(\d{4})-\d{2}-\d{2}$/', $date, $m)) {
            return null;
        }
        $year = (int)$m[1];
        return $year > 0 ? $year : null;
    }

    private function resolveLocalTimezone(): \DateTimeZone
    {
        $envPath = '/home/fpp/media/config/calendar-scheduler/runtime/fpp-env.json';
        if (is_file($envPath)) {
            $raw = @file_get_contents($envPath);
            if (is_string($raw) && $raw !== '') {
                $json = @json_decode($raw, true);
                $tzName = is_array($json) ? ($json['timezone'] ?? null) : null;
                if (is_string($tzName) && trim($tzName) !== '') {
                    try {
                        return new \DateTimeZone(trim($tzName));
                    } catch (\Throwable) {
                        // fall through
                    }
                }
            }
        }

        try {
            return new \DateTimeZone(date_default_timezone_get());
        } catch (\Throwable) {
            return new \DateTimeZone('UTC');
        }
    }

    private function loadHolidayResolver(): ?HolidayResolver
    {
        $envPath = '/home/fpp/media/config/calendar-scheduler/runtime/fpp-env.json';
        if (!is_file($envPath)) {
            return null;
        }

        $raw = @file_get_contents($envPath);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $json = @json_decode($raw, true);
        if (!is_array($json)) {
            return null;
        }

        $holidays = $json['rawLocale']['holidays'] ?? null;
        if (!is_array($holidays)) {
            return null;
        }

        return new HolidayResolver($holidays);
    }
}
