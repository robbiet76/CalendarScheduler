<?php
declare(strict_types=1);

namespace CalendarScheduler\Intent;

use CalendarScheduler\Intent\RawEvent;
use CalendarScheduler\Intent\NormalizationContext;
use CalendarScheduler\Platform\HolidayResolver;

// TODO(v3): Remove debug hash preimage logging once diff parity is proven
/**
 * IntentNormalizer
 *
 * Single authoritative boundary where raw inputs
 * are converted into canonical Intent.
 *
 * HARD RULES:
 * - No fetching
 * - No parsing
 * - No heuristics
 * - No resolution
 * - No policy
 * - No mutation
 *
 * Failure to normalize MUST throw.
 *
 * This is the ONLY place where:
 * - defaults are applied,
 * - symbolic → concrete expansion occurs,
 * - calendar/FPP semantic differences are reconciled.
 *
 * Resolution MUST NEVER see raw events.
 *
 * IntentNormalizer output MUST be stable and comparable.
 */
final class IntentNormalizer
{
    public function __construct()
    {
        // Intentionally empty.
        // Dependencies will be explicit when added.
    }


    /**
     * Normalize a source-agnostic RawEvent into Intent.
     *
     * RawEvent MUST already be:
     * - timezone-normalized (FPP timezone)
     * - semantically canonical
     * - free of provider-specific quirks
     *
     * This method performs ONLY:
     * - identity construction
     * - state hashing
     * - invariant enforcement
     */
    public function fromRaw(
        RawEvent $raw,
        NormalizationContext $context
    ): Intent {
        $timingArr = $this->applyHolidaySymbolics(
            $raw->timing,
            $context->holidayResolver
        );

        /**
         * Command timing normalization:
         * - Commands are point-in-time unless repeating
         */
        if ($raw->type === 'command' && ($raw->payload['repeat'] ?? 'none') === 'none') {
            $timingArr['end_time'] = $timingArr['start_time'] ?? null;
        }

        $identity = [
            'type'   => $raw->type,
            'target' => $raw->target,
            'timing' => $timingArr,
        ];

        $subEvent = [
            'type'    => $raw->type,
            'target'  => $raw->target,
            'timing'  => $timingArr,
            'payload' => $raw->payload,
        ];

        // Identity hash
        $identityHashInput = $this->canonicalizeForIdentityHash($identity);
        $identityHashJson  = json_encode($identityHashInput, JSON_THROW_ON_ERROR);
        $identityHash      = hash('sha256', $identityHashJson);

        // SubEvent state hash
        $stateHashInput = $this->canonicalizeForStateHash($subEvent);
        $stateHashJson  = json_encode($stateHashInput, JSON_THROW_ON_ERROR);
        $subEvent['stateHash'] = hash('sha256', $stateHashJson);

        // Event-level state hash (single subEvent for now)
        $eventStateHash = hash(
            'sha256',
            json_encode([$subEvent['stateHash']], JSON_THROW_ON_ERROR)
        );

        return new Intent(
            $identityHash,
            $identity,
            $raw->ownership,
            $raw->correlation,
            [$subEvent],
            $eventStateHash
        );
    }

    // ===============================
    // Shared normalization helpers
    // ===============================

    private function draftTimingFromCalendar(
        CalendarRawEvent $raw,
        NormalizationContext $context,
        array $symbolicTime = []
    ): DraftTiming
    {
        $provenance = [
            'source' => 'calendar',
        ];
        $isAllDay = ($raw->isAllDay ?? false) === true;
        $tz = $context->timezone;

        $startDateRaw = null;
        $endDateRaw   = null;
        $startTimeRaw = null;
        $endTimeRaw   = null;
        $daysRaw      = null;

        if (is_string($raw->dtstart) && trim($raw->dtstart) !== '') {
            $dtstart = trim($raw->dtstart);
            $startDt = null;

            // RFC 5545 / ISO variants — no fallback to "now"
            $formats = [
                'Y-m-d\TH:i:s\Z', // UTC
                'Y-m-d\TH:i:s',   // local datetime
                'Y-m-d H:i:s',    // space-separated
                'Y-m-d',          // date-only
            ];

            foreach ($formats as $fmt) {
                $candidate = \DateTimeImmutable::createFromFormat(
                    $fmt,
                    $dtstart,
                    str_ends_with($fmt, '\Z')
                        ? new \DateTimeZone('UTC')
                        : $tz
                );
                if ($candidate !== false) {
                    $startDt = $candidate;
                    break;
                }
            }

            if ($startDt instanceof \DateTimeImmutable) {
                $startDt = $startDt->setTimezone($tz);
                $startDateRaw = $startDt->format('Y-m-d');

                // Only set time if DTSTART explicitly contained a time component
                if (!$isAllDay && preg_match('/T\d{2}:\d{2}:\d{2}| \d{2}:\d{2}:\d{2}$/', $dtstart)) {
                    $startTimeRaw = $startDt->format('H:i:s');
                }
            }
        }

        // Parse DTEND (calendar-provided end time/date)
        if (is_string($raw->dtend) && trim($raw->dtend) !== '') {
            $dtend = trim($raw->dtend);
            $endDt = null;

            $formats = [
                'Y-m-d\TH:i:s\Z', // UTC
                'Y-m-d\TH:i:s',   // local datetime
                'Y-m-d H:i:s',    // space-separated
                'Y-m-d',          // date-only
            ];

            foreach ($formats as $fmt) {
                $candidate = \DateTimeImmutable::createFromFormat(
                    $fmt,
                    $dtend,
                    str_ends_with($fmt, '\Z')
                        ? new \DateTimeZone('UTC')
                        : $tz
                );
                if ($candidate !== false) {
                    $endDt = $candidate;
                    break;
                }
            }

            if ($endDt instanceof \DateTimeImmutable) {
                $endDt = $endDt->setTimezone($tz);
                // Only set time if DTEND explicitly includes a time component
                if (
                    !$isAllDay
                    && preg_match('/T\d{2}:\d{2}:\d{2}| \d{2}:\d{2}:\d{2}$/', $dtend)
                ) {
                    $endTimeRaw = $endDt->format('H:i:s');
                }
            }
            // Record provenance of end_date source as 'dtend'
            $provenance['end_date_source'] = 'dtend';
        }

        // Calendar DATE-only DTEND represents per-occurrence span, not intent window.
        // For all-day events with RRULE, DATE DTEND must NOT define end_date.
        if (
            $isAllDay === true
            && is_array($raw->rrule)
            && isset($raw->rrule['FREQ'])
        ) {
            $endDateRaw = null;
        }

        // Symbolic time overrides with user-controlled offsets
        if (isset($symbolicTime['start']) && is_string($symbolicTime['start'])) {
            $startTimeRaw = $symbolicTime['start'];
            if (isset($symbolicTime['start_offset'])) {
                $startTimeOffset = (int) $symbolicTime['start_offset'];
            }
        }

        if (isset($symbolicTime['end']) && is_string($symbolicTime['end'])) {
            $endTimeRaw = $symbolicTime['end'];
            if (isset($symbolicTime['end_offset'])) {
                $endTimeOffset = (int) $symbolicTime['end_offset'];
            }
        }

        // RRULE rule-based timing semantics
        if (is_array($raw->rrule)) {
            $rrule = $raw->rrule;
            // Handle FREQ
            if (isset($rrule['FREQ'])) {
                $freq = strtoupper((string)$rrule['FREQ']);
                if ($freq === 'DAILY') {
                    // Explicitly set daysRaw to null for DAILY
                    $daysRaw = null;
                } elseif ($freq === 'WEEKLY') {
                    // Require BYDAY
                    if (!isset($rrule['BYDAY'])) {
                        throw new \RuntimeException("RRULE:FREQ=WEEKLY requires BYDAY");
                    }
                } else {
                    throw new \RuntimeException("Unsupported RRULE FREQ: {$freq}");
                }
            }
            // Extract BYDAY from RRULE (calendar side only, no expansion)
            if (isset($rrule['BYDAY'])) {
                $byday = $rrule['BYDAY'];
                if (is_string($byday)) {
                    $daysRaw = array_values(
                        array_filter(
                            array_map('trim', explode(',', $byday))
                        )
                    );
                } elseif (is_array($byday)) {
                    $daysRaw = array_values($byday);
                }

                // Validation guard: BYDAY must not be empty
                if ($daysRaw === []) {
                    throw new \RuntimeException(
                        'Calendar RRULE with BYDAY must specify at least one day'
                    );
                }

                // Validation guard: invalid BYDAY tokens
                $validDays = ['SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA'];
                foreach ($daysRaw as $d) {
                    if (!in_array($d, $validDays, true)) {
                        throw new \RuntimeException(
                            "Invalid BYDAY token '{$d}' in calendar RRULE"
                        );
                    }
                }
            }

            // Validation guard: BYDAY requires FREQ=WEEKLY (calendar semantics)
            if ($daysRaw !== null) {
                if (isset($rrule['FREQ']) && strtoupper((string) $rrule['FREQ']) !== 'WEEKLY') {
                    throw new \RuntimeException(
                        'Calendar RRULE with BYDAY must use FREQ=WEEKLY'
                    );
                }
            }
            // Handle UNTIL
            if (isset($rrule['UNTIL'])) {
                $endDateRaw = $this->computeIntentEndDateFromRRuleUntil(
                    $rrule['UNTIL'],
                    $startDt instanceof \DateTimeImmutable ? $startDt : null,
                    $startTimeRaw,
                    $isAllDay,
                    $daysRaw,
                    $tz,
                    $raw->provenance['uid'] ?? null,
                    $raw->summary ?? null
                );
                // Record provenance of end_date source as 'rrule'
                $provenance['end_date_source'] = 'rrule';
                $provenance['end_date_final']  = true;
            }
        }

        // If no RRULE exists at all and no end date was provided, treat as single-day event
        if ($endDateRaw === null && $raw->rrule === null) {
            $endDateRaw = $startDateRaw;
        }

        $startTimeOffset = $startTimeOffset ?? 0;
        $endTimeOffset   = $endTimeOffset ?? 0;

        return new DraftTiming(
            $startDateRaw,
            $endDateRaw,
            $startTimeRaw,
            $endTimeRaw,
            $startTimeOffset,
            $endTimeOffset,
            $daysRaw,
            $isAllDay,
            $provenance
        );
    }

    /**
     * Compute the inclusive intent end date (Y-m-d) from an RRULE UNTIL value.
     *
     * ICS note:
     * - DTEND is per-occurrence and exclusive.
     * - UNTIL bounds the set of DTSTART instants (last DTSTART must be <= UNTIL).
     *
     * For identity intent windows, we want the DATE of the last occurrence DTSTART.
     */
    private function computeIntentEndDateFromRRuleUntil(
        mixed $untilRaw,
        ?\DateTimeImmutable $dtstart,
        ?string $startTimeRaw,
        bool $isAllDay,
        ?array $daysRaw,
        \DateTimeZone $tz,
        ?string $calendarUid = null,
        ?string $calendarSummary = null
    ): ?string {
        $parsed = $this->parseRRuleUntil($untilRaw, $tz);
        // --- DEBUG: initialize debug record ---
        $debug = [
            'stage' => 'enter',
            'until_raw' => $untilRaw,
            'parsed' => $parsed !== null,
        ];
        if ($parsed === null) {
            $debug['correlation'] = [
                'uid' => $calendarUid,
                // DEBUG (temporary): end-date calculation tracing for calendar UNTIL semantics.
                // Remove once behavior is proven stable across time zones.
                // -------------------------------------------------------------------------
                'summary' => $calendarSummary,
                'start_date_raw' => $dtstart?->format('Y-m-d'),
                'start_time_raw' => $startTimeRaw,
                'source' => 'calendar'
            ];
            file_put_contents(
                '/tmp/gcs-enddate-debug.log',
                json_encode($debug, JSON_THROW_ON_ERROR) . PHP_EOL,
                FILE_APPEND
                // DEBUG (temporary): append is intentional for trace correlation.
                // This log should be removed once confidence is established.
                // -------------------------------------------------------------------------
            );
            return null;
        }

        [$untilDt, $isDateOnly] = $parsed;
        $untilDt = $untilDt->setTimezone($tz);
        $debug['is_date_only'] = $isDateOnly;
        $debug['until_date'] = $untilDt->format('Y-m-d');
        $debug['until_time'] = $untilDt->format('H:i:s');
        $debug['is_all_day'] = $isAllDay;
        $debug['start_time_raw'] = $startTimeRaw;
        $debug['days_raw'] = $daysRaw;
        $debug['rules'] = [];

        // Base candidate is UNTIL date
        $candidateDate = $untilDt->format('Y-m-d');

        // RFC 5545 semantics (timezone-safe):
        // UNTIL is an exclusive upper bound on valid DTSTART instants.
        // The final occurrence date is the DATE of the last DTSTART such that:
        //
        //   DTSTART (converted to local timezone) <= UNTIL (converted to local timezone)
        //
        // This comparison MUST be done in local time and MUST NOT rely on hardcoded
        // cutoff clock values, as FPP users span all time zones (including DST and
        // non-hour offsets).
        //
        // Practically:
        // - If the event is timed (not all-day)
        // - And the normal DTSTART time-of-day would occur *after* the UNTIL instant
        //   on the UNTIL calendar date
        // - Then no occurrence can exist on that date, and the intent end date must
        //   be the previous calendar day.
        //
        // All-day events are date-bounded and do not apply this rule.
        if ($isAllDay === false && $dtstart instanceof \DateTimeImmutable) {
            // RFC 5545: UNTIL is an exclusive upper bound on valid DTSTART instants.
            // Compare the local DTSTART time-of-day against the UNTIL instant.
            $debug['rules'][] = 'timed_until_exclusive_check';

            // Construct a candidate DTSTART on the UNTIL calendar date
            $candidateStart = $dtstart
                ->setDate(
                    (int)$untilDt->format('Y'),
                    (int)$untilDt->format('m'),
                    (int)$untilDt->format('d')
                );

            // If that DTSTART would occur AFTER the UNTIL instant,
            // then no occurrence exists on the UNTIL date.
            if ($candidateStart > $untilDt) {
                $debug['rules'][] = 'timed_until_exclusive_rollback';
                $candidateDate = $untilDt->modify('-1 day')->format('Y-m-d');
            }
        }

        // IMPORTANT:
        // For intent windows we preserve the calendar's UNTIL date intent.
        // We do NOT snap back to the last matching BYDAY occurrence date.

        $debug['final_candidate'] = $candidateDate;
        $debug['correlation'] = [
            'uid' => $calendarUid,
            // DEBUG (temporary): end-date calculation tracing for calendar UNTIL semantics.
            // Remove once behavior is proven stable across time zones.
            // -------------------------------------------------------------------------
            'summary' => $calendarSummary,
            'start_date_raw' => $dtstart?->format('Y-m-d'),
            'start_time_raw' => $startTimeRaw,
            'source' => 'calendar'
        ];
        file_put_contents(
            '/tmp/gcs-enddate-debug.log',
            json_encode($debug, JSON_THROW_ON_ERROR) . PHP_EOL,
            FILE_APPEND
            // DEBUG (temporary): append is intentional for trace correlation.
            // This log should be removed once confidence is established.
            // -------------------------------------------------------------------------
        );
        return $candidateDate;
    }

    /**
     * Parse RFC 5545 UNTIL values.
     * Returns [DateTimeImmutable, bool $isDateOnly] or null.
     */
    private function parseRRuleUntil(mixed $untilRaw, \DateTimeZone $tz): ?array
    {
        if (!is_string($untilRaw) || $untilRaw === '') {
            return null;
        }

        // RFC 5545: UNTIL can be either YYYYMMDD (date) or YYYYMMDDTHHMMSSZ (UTC date-time)
        if (preg_match('/^\d{8}$/', $untilRaw)) {
            $dt = \DateTimeImmutable::createFromFormat('Ymd', $untilRaw, $tz);
            return ($dt instanceof \DateTimeImmutable) ? [$dt, true] : null;
        }

        if (preg_match('/^\d{8}T\d{6}Z$/', $untilRaw)) {
            $dt = \DateTimeImmutable::createFromFormat('Ymd\THis\Z', $untilRaw, new \DateTimeZone('UTC'));
            return ($dt instanceof \DateTimeImmutable) ? [$dt, false] : null;
        }

        return null;
    }


    private function normalizeTiming(
        DraftTiming $draft,
        NormalizationContext $context
        /**
         * INVARIANT:
         * All cross-source timing semantics MUST converge here.
         * No calendar-specific or FPP-specific date logic is allowed below this point.
         */
    ): CanonicalTiming {
        // Guard: prevent 00:00–24:00 time window from leaking (all-day must be normalized)
        if (
            $draft->startTimeRaw === '00:00:00'
            && $draft->endTimeRaw === '24:00:00'
            && $draft->startTimeOffset === 0
            && $draft->endTimeOffset === 0
            && $draft->isAllDay === false
        ) {
            throw new \RuntimeException(
                'Invalid timing: 00:00–24:00 must be represented as all-day intent'
            );
        }
        $holidayResolver = $context->holidayResolver;

        $timing = [
            'all_day' => $draft->isAllDay === true,
            'start_date' => $this->normalizeDateField(
                $draft->startDateRaw,
                $holidayResolver
            ),
            'end_date' => $this->normalizeDateField(
                $draft->endDateRaw,
                $holidayResolver
            ),
            'start_time' => $this->normalizeTimeField(
                $draft->startTimeRaw,
                $draft->startTimeOffset
            ),
            'end_time' => $this->normalizeTimeField(
                $draft->endTimeRaw,
                $draft->endTimeOffset
            ),
            'days' => $this->normalizeDays(
                $draft->daysRaw,
                $draft->provenance['source'] ?? 'calendar'
            ),
        ];

        // Consolidated calendar end-date policy resolver.
        if (($draft->provenance['end_date_final'] ?? false) !== true) {
            $timing = $this->resolveCalendarEndDate($draft, $timing);
        }

        // IMPORTANT (RFC 5545):
        // Calendar intent end_date MUST already be inclusive by this stage.
        // Inclusive end dates for calendar events are computed ONLY from RRULE + UNTIL
        // inside computeIntentEndDateFromRRuleUntil().
        // normalizeTiming() must never shift calendar end dates.

        return new CanonicalTiming($timing);
    }

    /**
     * Resolve the final inclusive intent end date for calendar events.
     *
     * RULES:
     * - RRULE-derived end dates are already inclusive and MUST NOT be shifted.
     * - DTEND-derived end dates are per-occurrence and exclusive at midnight.
     * - No other layer may adjust end dates.
     */
    private function resolveCalendarEndDate(
        DraftTiming $draft,
        array $timing
    ): array {
        // Only calendar-sourced timings participate
        if (($draft->provenance['source'] ?? null) !== 'calendar') {
            return $timing;
        }

        // Only DTEND-derived end dates may be shifted
        if (
            ($draft->provenance['end_date_source'] ?? null) !== 'dtend'
            || $draft->isAllDay === true
            || $draft->endTimeRaw !== '00:00:00'
            || empty($timing['end_date']['hard'])
        ) {
            return $timing;
        }

        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $timing['end_date']['hard']);
        if ($dt instanceof \DateTimeImmutable) {
            $timing['end_date']['hard'] = $dt->modify('-1 day')->format('Y-m-d');
        }

        return $timing;
    }

    private function normalizeDateField(
        ?string $raw,
        HolidayResolver $resolver
    ): array {
        if ($raw === null || $raw === '') {
            return [
                'hard'     => null,
                'symbolic' => null,
            ];
        }

        // ISO-8601 hard date (YYYY-MM-DD)
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return [
                'hard'     => $raw,
                'symbolic' => null,
            ];
        }

        // Symbolic date (holiday or named marker)
        if ($resolver->isSymbolic($raw)) {
            return [
                'hard'     => null,
                'symbolic' => $raw,
            ];
        }

        // Unknown symbolic value – preserve intent, do not resolve
        return [
            'hard'     => null,
            'symbolic' => $raw,
        ];
    }

    private function normalizeTimeField(
        ?string $raw,
        int $offset
    ): array {
        if ($raw === null || $raw === '') {
            return [
                'hard'     => null,
                'symbolic' => null,
                'offset'   => $offset,
            ];
        }

        // Hard clock time (HH:MM or HH:MM:SS)
        if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $raw)) {
            return [
                'hard'     => $raw,
                'symbolic' => null,
                'offset'   => $offset,
            ];
        }

        // Symbolic time marker (e.g. Dusk, Dawn)
        return [
            'hard'     => null,
            'symbolic' => $raw,
            'offset'   => $offset,
        ];
    }

    private function normalizeDays(
        array|int|null $raw,
        string $source
    ): ?array {
        file_put_contents(
            '/tmp/gcs-days-debug.log',
            json_encode([
                'stage' => 'input',
                'source' => $source,
                'type' => gettype($raw),
                'raw' => $raw,
            ], JSON_THROW_ON_ERROR) . PHP_EOL,
            FILE_APPEND
        );
        if ($raw === null) {
            return null;
        }

        $order = ['SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA'];

        // Helper to unwrap leaked scheduler metadata: ["weekly", [...]] (idempotent)
        $unwrapWeekly = function(array $v): array {
            while (
                array_is_list($v)
                && count($v) === 2
                && $v[0] === 'weekly'
                && is_array($v[1])
            ) {
                $v = $v[1];
            }
            return $v;
        };

        // Calendar or FPP side: normalize array shapes
        if (is_array($raw)) {
            $raw = $unwrapWeekly($raw);
            // Also unwrap if associative array with type/value keys and possible nested weekly
            if (isset($raw['type'], $raw['value']) && $raw['type'] === 'weekly' && is_array($raw['value'])) {
                $raw['value'] = $unwrapWeekly($raw['value']);
            }

            // Replace logic for days array construction
            if (array_is_list($raw)) {
                $days = array_values(array_unique($raw));
            } elseif (isset($raw['type'], $raw['value']) && $raw['type'] === 'weekly' && is_array($raw['value'])) {
                $v = $unwrapWeekly($raw['value']);
                $days = array_values(array_unique($v));
            } else {
                throw new \RuntimeException('Invalid days array shape in normalizeDays');
            }

            // Full week or empty → every day
            if ($days === [] || count($days) === 7) {
                file_put_contents(
                    '/tmp/gcs-days-debug.log',
                    json_encode([
                        'stage' => 'output',
                        'source' => $source,
                        'normalized' => null,
                    ], JSON_THROW_ON_ERROR) . PHP_EOL,
                    FILE_APPEND
                );
                return null;
            }

            // Canonical ordering
            usort($days, fn($a, $b) =>
                array_search($a, $order, true) <=> array_search($b, $order, true)
            );

            file_put_contents(
                '/tmp/gcs-days-debug.log',
                json_encode([
                    'stage' => 'output',
                    'source' => $source,
                    'normalized' => [
                        'type' => 'weekly',
                        'value' => $days,
                    ],
                ], JSON_THROW_ON_ERROR) . PHP_EOL,
                FILE_APPEND
            );
            return [
                'type'  => 'weekly',
                'value' => $days,
            ];
        }

        // FPP side: numeric day index / bitmask
        if (is_int($raw)) {
            $days = \CalendarScheduler\Platform\FPPSemantics::normalizeDays($raw);
            if (is_array($days)) {
                $days = $unwrapWeekly($days);
            }

            if ($days === null || $days === [] || count($days) === 7) {
                file_put_contents(
                    '/tmp/gcs-days-debug.log',
                    json_encode([
                        'stage' => 'output',
                        'source' => $source,
                        'normalized' => null,
                    ], JSON_THROW_ON_ERROR) . PHP_EOL,
                    FILE_APPEND
                );
                return null;
            }

            // Canonical ordering
            usort($days, fn($a, $b) =>
                array_search($a, $order, true) <=> array_search($b, $order, true)
            );

            file_put_contents(
                '/tmp/gcs-days-debug.log',
                json_encode([
                    'stage' => 'output',
                    'source' => $source,
                    'normalized' => [
                        'type' => 'weekly',
                        'value' => $days,
                    ],
                ], JSON_THROW_ON_ERROR) . PHP_EOL,
                FILE_APPEND
            );
            return [
                'type'  => 'weekly',
                'value' => $days,
            ];
        }

        throw new \RuntimeException(
            'Invalid days value in normalizeDays'
        );
    }
    private function validateSettingsSection(array $settings): void
    {
        $allowedKeys = ['type', 'enabled', 'repeat', 'stopType'];
        foreach ($settings as $k => $_) {
            if (!in_array($k, $allowedKeys, true)) {
                throw new \RuntimeException("Invalid key '{$k}' in [settings] section");
            }
        }
    }

    private function validateSymbolicTimeSection(array $symbolicTime): void
    {
        $allowedKeys = ['start', 'end', 'start_offset', 'end_offset'];
        foreach ($symbolicTime as $k => $v) {
            if (!in_array($k, $allowedKeys, true)) {
                throw new \RuntimeException("Invalid key '{$k}' in [symbolic_time] section");
            }
            if (in_array($k, ['start', 'end'], true)) {
                if (!is_string($v) || trim($v) === '') {
                    throw new \RuntimeException("Symbolic time '{$k}' must be a non-empty string");
                }
            }
            if (str_ends_with($k, '_offset') && !is_int($v) && !ctype_digit((string)$v)) {
                throw new \RuntimeException("Symbolic time offset '{$k}' must be an integer");
            }
        }
    }

    private function validateCommandSection(array $commandMeta, string $type): void
    {
        if ($commandMeta === []) {
            return;
        }

        if ($type !== 'command') {
            throw new \RuntimeException("[command] section is only valid for type=command");
        }

        foreach ($commandMeta as $k => $v) {
            if (!is_scalar($v) && !is_array($v)) {
                throw new \RuntimeException("Invalid value for command field '{$k}'");
            }
        }
    }

    private function buildIntent(
        string $type,
        string $target,
        CanonicalTiming $timing,
        array $payload,
        array $ownership,
        array $correlation,
        NormalizationContext $context
    ): Intent {
        $timingArr = $this->applyHolidaySymbolics(
            $timing->toArray(),
            $context->holidayResolver
        );

        /**
         * Command timing normalization:
         * - Commands are point-in-time operations.
         * - Calendar represents them as +1 minute, but Intent MUST NOT.
         * - If repeat === 'none', end_time MUST equal start_time.
         */
        if ($type === 'command' && ($payload['repeat'] ?? 'none') === 'none') {
            if (isset($timingArr['start_time'])) {
                $timingArr['end_time'] = $timingArr['start_time'];
            }
        }

        $identity = [
            'type'   => $type,
            'target' => $target,
            'timing' => $timingArr,
        ];

        // Base SubEvent (1:1 with an FPP scheduler entry)
        $subEvent = [
            'type'     => $type,
            'target'   => $target,
            'timing'   => $timingArr,
            // NOTE: payload contains execution settings (enabled/repeat/stopType) and optional command metadata.
            'payload'  => $payload,
        ];

        // Identity hash: canonicalize for hashing so symbolic values are stable.
        $identityHashInput = $this->canonicalizeForIdentityHash($identity);
        $identityHashJson  = json_encode($identityHashInput, JSON_THROW_ON_ERROR);

        /**
         * DEBUG (temporary):
         * Capture exact identity hash preimage for calendar/FPP parity verification.
         * MUST be removed once hash parity is proven stable.
         */
        $source =
            $ownership['controller']
            ?? ($ownership['source'] ?? 'unknown');

        $identityPreimagePath = '/tmp/gcs-hash-preimage-' . $source . '.json';

        // DEBUG (temporary): keep only the most recent hash preimage
        // Prevent unbounded file growth during repeated sync runs.
        if (file_exists($identityPreimagePath)) {
            unlink($identityPreimagePath);
        }

        file_put_contents(
            $identityPreimagePath,
            $identityHashJson . PHP_EOL,
            FILE_APPEND
        );

        $identityHash = hash('sha256', $identityHashJson);

        // State hash (SubEvent-level): canonicalize the full executable state to detect updates.
        // This is provider-agnostic and MUST remain stable across calendar/FPP sources.
        $stateHashInput = $this->canonicalizeForStateHash($subEvent);
        $stateHashJson  = json_encode($stateHashInput, JSON_THROW_ON_ERROR);

        /**
         * DEBUG (temporary):
         * Capture exact state hash preimage for calendar/FPP parity verification.
         * MUST be removed once state parity is proven stable.
         */
        $statePreimagePath = '/tmp/gcs-state-preimage-' . $source . '.json';

        // DEBUG (temporary): keep only the most recent state hash preimage
        // Prevent unbounded file growth during repeated sync runs.
        if (file_exists($statePreimagePath)) {
            unlink($statePreimagePath);
        }

        file_put_contents(
            $statePreimagePath,
            $stateHashJson . PHP_EOL,
            FILE_APPEND
        );

        $subEvent['stateHash'] = hash('sha256', $stateHashJson);

        $subEvents = [$subEvent];

        // Event-level state hash: aggregated from SubEvent state hashes
        // Deterministic, order-stable, and provider-agnostic
        $eventStateHashInput = array_map(
            fn(array $se) => (string)($se['stateHash'] ?? ''),
            $subEvents
        );
        sort($eventStateHashInput, SORT_STRING);
        $eventStateHash = hash(
            'sha256',
            json_encode($eventStateHashInput, JSON_THROW_ON_ERROR)
        );

        return new Intent(
            $identityHash,
            $identity,
            $ownership,
            $correlation,
            $subEvents,
            // Aggregated state hash for the full Manifest Event
            $eventStateHash
        );
    }

    /**
     * Canonicalize Manifest Event identity for identityHash computation.
     *
     * Identity answers: “Does this intent already exist?”
     *
     * RULES:
     * - Identity is stable across time
     * - Identity excludes dates (start/end date)
     * - Identity excludes execution state (repeat, stopType, enabled)
     * - Identity includes start_time to prevent collapsing distinct daily intents
     *
     * Changing identity implies create/delete, never update.
     */
    private function canonicalizeForIdentityHash(array $identity): array
    {
        $timing = $identity['timing'];

        $pickTime = function (?array $time): ?array {
            if ($time === null) {
                return null;
            }

            $symbolic = $time['symbolic'] ?? null;
            if (is_string($symbolic)) {
                $symbolic = strtolower(trim($symbolic));
            }

            return [
                'hard'     => $time['hard'] ?? null,
                'symbolic' => $symbolic,
                'offset'   => (int)($time['offset'] ?? 0),
            ];
        };

        return [
            'type'   => $identity['type'],
            'target' => $identity['target'],
            'timing' => [
                'all_day'    => (bool)$timing['all_day'],
                'start_time' => $pickTime($timing['start_time'] ?? null),
                'days'       => $timing['days'] ?? null,
            ],
        ];
    }

    /**
     * STATE HASH CONTRACT
     *
     * The stateHash represents the full executable state of a single SubEvent.
     *
     * Rules:
     * - Provider-agnostic
     * - Deterministic across calendar and FPP sources
     * - Includes timing, behavior, and execution payload
     * - Excludes identity-only semantics
     *
     * stateHash is used exclusively to detect UPDATE conditions during Diff.
     * Any change to this canonicalization MUST be paired with a spec update.
     */
    private function canonicalizeForStateHash(array $subEvent): array
    {
        $timing = is_array($subEvent['timing'] ?? null) ? $subEvent['timing'] : [];
        $payload = is_array($subEvent['payload'] ?? null) ? $subEvent['payload'] : [];

        $lowerOrNull = function ($v): ?string {
            if (!is_string($v)) {
                return null;
            }
            $v = trim($v);
            return $v === '' ? null : strtolower($v);
        };

        $canonDate = function ($v): array {
            if (!is_array($v)) {
                return ['hard' => null, 'symbolic' => null];
            }
            return [
                'hard'     => $v['hard'] ?? null,
                'symbolic' => isset($v['symbolic']) && is_string($v['symbolic'])
                    ? trim((string)$v['symbolic'])
                    : ($v['symbolic'] ?? null),
            ];
        };

        $canonTime = function ($v) use ($lowerOrNull): ?array {
            if ($v === null) {
                return null;
            }
            if (!is_array($v)) {
                return [
                    'hard'     => null,
                    'symbolic' => null,
                    'offset'   => 0,
                ];
            }

            // Normalize symbolic times to lowercase for stable hashing.
            $symbolic = $v['symbolic'] ?? null;
            $symbolic = $lowerOrNull($symbolic);

            return [
                'hard'     => $v['hard'] ?? null,
                'symbolic' => $symbolic,
                'offset'   => (int)($v['offset'] ?? 0),
            ];
        };

        $canonDays = function ($v): ?array {
            if ($v === null) {
                return null;
            }
            if (!is_array($v)) {
                return null;
            }
            if (($v['type'] ?? null) !== 'weekly' || !is_array($v['value'] ?? null)) {
                return null;
            }
            return [
                'type'  => 'weekly',
                'value' => array_values($v['value']),
            ];
        };

        // Execution behavior fields must be stable across sources.
        $enabled = \CalendarScheduler\Platform\FPPSemantics::normalizeEnabled($payload['enabled'] ?? true);
        $repeat  = isset($payload['repeat']) && is_string($payload['repeat'])
            ? strtolower(trim($payload['repeat']))
            : (\CalendarScheduler\Platform\FPPSemantics::repeatToSemantic(
                \CalendarScheduler\Platform\FPPSemantics::defaultRepeatForType((string)($subEvent['type'] ?? 'playlist'))
            ));

        $stopType = isset($payload['stopType']) && is_string($payload['stopType'])
            ? strtolower(trim($payload['stopType']))
            : 'graceful';

        // Preserve command metadata (if present) as part of state.
        $command = null;
        if (isset($payload['command']) && is_array($payload['command'])) {
            $command = $payload['command'];
        }

        return [
            'type'   => (string)($subEvent['type'] ?? ''),
            'target' => (string)($subEvent['target'] ?? ''),
            'timing' => [
                'all_day'    => (bool)($timing['all_day'] ?? false),
                'start_date' => $canonDate($timing['start_date'] ?? null),
                'end_date'   => $canonDate($timing['end_date'] ?? null),
                'start_time' => $canonTime($timing['start_time'] ?? null),
                'end_time'   => $canonTime($timing['end_time'] ?? null),
                'days'       => $canonDays($timing['days'] ?? null),
            ],
            'behavior' => [
                'enabled'  => (bool)$enabled,
                'repeat'   => (string)$repeat,
                'stopType' => (string)$stopType,
            ],
            'payload' => [
                'command' => $command,
            ],
        ];
    }


    /**
     * Apply holiday symbolic resolution ONCE, in the shared flow, prior to hashing.
     *
     * Rule:
     * - If hard date exists and symbolic is null, attempt to infer symbolic from FPP holiday map.
     * - Never overwrite an explicitly-provided symbolic value.
     */
    private function applyHolidaySymbolics(array $timing, HolidayResolver $resolver): array
    {
        foreach (['start_date', 'end_date'] as $k) {
            if (!isset($timing[$k]) || !is_array($timing[$k])) {
                continue;
            }

            $hard = $timing[$k]['hard'] ?? null;
            $sym  = $timing[$k]['symbolic'] ?? null;

            if (is_string($hard) && $hard !== '' && ($sym === null || $sym === '')) {
                $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $hard);
                if ($dt instanceof \DateTimeImmutable) {
                    $resolved = $resolver->holidayFromDate($dt);
                    if (is_string($resolved) && $resolved !== '') {
                        $timing[$k]['symbolic'] = $resolved;
                    }
                }
            }
        }

        return $timing;
    }
}


final class DraftTiming
{
    public function __construct(
        public readonly ?string $startDateRaw,
        public readonly ?string $endDateRaw,
        public readonly ?string $startTimeRaw,
        public readonly ?string $endTimeRaw,
        public readonly int $startTimeOffset,
        public readonly int $endTimeOffset,
        public readonly array|int|null $daysRaw,
        public readonly bool $isAllDay,
        public readonly array $provenance = []
    ) {}
}

final class CanonicalTiming
{
    public function __construct(
        private array $timing
    ) {}

    public function toArray(): array
    {
        return $this->timing;
    }
}
