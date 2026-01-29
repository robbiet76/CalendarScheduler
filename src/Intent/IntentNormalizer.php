<?php
declare(strict_types=1);

namespace GoogleCalendarScheduler\Intent;

use GoogleCalendarScheduler\Intent\CalendarRawEvent;
use GoogleCalendarScheduler\Intent\FppRawEvent;
use GoogleCalendarScheduler\Intent\NormalizationContext;
use GoogleCalendarScheduler\Platform\IniMetadata;
use GoogleCalendarScheduler\Platform\HolidayResolver;

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
     * Normalize raw calendar data into Intent.
     *
     * Inputs are raw, source-shaped data.
     * Output MUST fully conform to the locked Intent schema.
     * No downstream component may reinterpret semantics.
     */
    public function fromCalendar(
        CalendarRawEvent $raw,
        NormalizationContext $context
    ): Intent {
        // --- INI-driven type resolution ---
        $meta = IniMetadata::fromDescription($raw->description);
        $settings = $meta['settings'] ?? [];
        $symbolicTime = $meta['symbolic_time'] ?? [];
        $commandMeta  = $meta['command'] ?? [];
        $type = $settings['type'] ?? null;
        $type = \GoogleCalendarScheduler\Platform\FPPSemantics::normalizeType($type);

        // --- Metadata validation ---
        $this->validateSettingsSection($settings);
        $this->validateSymbolicTimeSection($symbolicTime);
        $this->validateCommandSection($commandMeta, $type);

        // --- Identity (human intent) ---
        // All-day normalization is intentional and occurs ONLY at the Intent layer.
        // Raw calendar data must never coerce or invent times.
        // Handle all-day events explicitly:
        // For all-day intents, times are null because the event spans entire days without specific start/end times.
        // This preserves the intent without coercing times to 23:59:59 or 24:00:00.
        // Date normalization happens here (IntentNormalizer) to ensure consistent interpretation,
        // rather than in earlier layers like CalendarTranslator.
        $draftTiming = $this->draftTimingFromCalendar($raw, $context, $symbolicTime);
        $canonicalTiming = $this->normalizeTiming($draftTiming, $context);


        // --- Recurrence handling (NO expansion) ---
        // FPP scheduler operates on date ranges. We NEVER generate one subEvent per day.
        // RRULE is used ONLY to compute the intent window (end_date).
        // All timing logic is now handled by CanonicalTiming.

        // --- Execution payload (FPP semantics) ---
        // Calendar INI may override, but defaults ALWAYS come from FPPSemantics.
        $defaults = \GoogleCalendarScheduler\Platform\FPPSemantics::defaultBehavior();

        $payload = [
            'enabled'  => \GoogleCalendarScheduler\Platform\FPPSemantics::normalizeEnabled(
                $settings['enabled'] ?? $defaults['enabled']
            ),
            'stopType' => $settings['stopType'] ?? 'graceful',
        ];

        if (isset($settings['repeat']) && is_string($settings['repeat'])) {
            $payload['repeat'] = strtolower(trim($settings['repeat']));
        } else {
            $defaultNumeric = \GoogleCalendarScheduler\Platform\FPPSemantics::defaultRepeatForType($type);
            $payload['repeat'] = \GoogleCalendarScheduler\Platform\FPPSemantics::repeatToSemantic($defaultNumeric);
        }

        if ($type === 'command' && is_array($commandMeta) && $commandMeta !== []) {
            $payload['command'] = $commandMeta;
        }


        // --- Ownership ---
        $ownership = [
            'managed'    => true,
            'controller' => 'calendar',
            'locked'     => false,
        ];

        // --- Correlation / provenance ---
        $correlation = [
            'source' => 'calendar',
            'uid'    => $raw->provenance['uid'] ?? null,
        ];

        return $this->buildIntent(
            $type,
            $raw->summary,
            $canonicalTiming,
            $payload,
            $ownership,
            $correlation,
            $context
        );
    }

    /**
     * Normalize raw FPP scheduler data into Intent.
     *
     * Inputs are raw, source-shaped data.
     * Output MUST fully conform to the locked Intent schema.
     * No downstream component may reinterpret semantics.
     */
    public function fromFpp(
        FppRawEvent $raw,
        NormalizationContext $context
    ): Intent {
        $d = $raw->data;
        // FPP all-day is explicitly encoded as 00:00 → 24:00 with zero offsets.
        // Detect it ONCE here and propagate via DraftTiming.
        $isAllDay =
            ($d['startTime'] ?? null) === '00:00:00'
            && ($d['endTime'] ?? null) === '24:00:00'
            && ((int)($d['startTimeOffset'] ?? 0)) === 0
            && ((int)($d['endTimeOffset'] ?? 0)) === 0;

        // --- Required fields validation (fail fast) ---
        foreach (['playlist', 'startDate', 'endDate', 'startTime', 'endTime'] as $k) {
            if (!isset($d[$k])) {
                throw new \RuntimeException("FPP raw event missing required field: {$k}");
            }
        }

        // --- Type normalization ---
        if (!empty($d['command'])) {
            $type = 'command';
        } else {
            $type = ($d['sequence'] ?? 0) ? 'sequence' : 'playlist';
        }
        $type = \GoogleCalendarScheduler\Platform\FPPSemantics::normalizeType($type);

        // --- Target normalization ---
        if ($type === 'command') {
            $target = (string) $d['command'];
        } else {
            $target = (string) $d['playlist'];
            $target = preg_replace('/\.fseq$/i', '', $target);
        }

        // Normalize guard dates in FPP start/end dates
        $startDateRaw = $d['startDate'] ?? null;
        $endDateRaw   = $d['endDate'] ?? null;
        if (is_string($endDateRaw)) {
            $endDateObj = \DateTimeImmutable::createFromFormat('Y-m-d', $endDateRaw);
            if ($endDateObj !== false
                && \GoogleCalendarScheduler\Platform\FPPSemantics::isSchedulerGuardDate(
                    $endDateObj->format('Y-m-d'),
                    new \DateTimeImmutable('now', $context->timezone)
                )
            ) {
                $endDateRaw = null;
            }
        }

        $draftTiming = new DraftTiming(
            $startDateRaw,
            $endDateRaw,
            $isAllDay ? null : ($d['startTime'] ?? null),
            $isAllDay ? null : ($d['endTime'] ?? null),
            (int)($d['startTimeOffset'] ?? 0),
            (int)($d['endTimeOffset'] ?? 0),
            $d['day'] ?? null,
            $isAllDay,
            ['source' => 'fpp']
        );
        $canonicalTiming = $this->normalizeTiming($draftTiming, $context);


        // --- Behavior (fully explicit) ---
        if (isset($d['repeat'])) {
            $repeatNumeric = \GoogleCalendarScheduler\Platform\FPPSemantics::normalizeRepeat($d['repeat']);
        } else {
            $repeatNumeric = \GoogleCalendarScheduler\Platform\FPPSemantics::defaultRepeatForType($type);
        }
        $repeatSemantic = \GoogleCalendarScheduler\Platform\FPPSemantics::repeatToSemantic($repeatNumeric);

        if (isset($d['stopType'])) {
            $stopTypeValue = $d['stopType'];
        } else {
            $stopTypeValue = null;
        }

        if (is_int($stopTypeValue)) {
            $stopTypeSemantic = match ($stopTypeValue) {
                \GoogleCalendarScheduler\Platform\FPPSemantics::STOP_TYPE_HARD => 'hard',
                \GoogleCalendarScheduler\Platform\FPPSemantics::STOP_TYPE_GRACEFUL_LOOP => 'graceful_loop',
                default => 'graceful',
            };
        } elseif (is_string($stopTypeValue)) {
            $stopTypeSemantic = strtolower(trim($stopTypeValue));
        } else {
            $stopTypeSemantic = 'graceful';
        }

        $payload = [
            'enabled'  => \GoogleCalendarScheduler\Platform\FPPSemantics::normalizeEnabled($d['enabled'] ?? true),
            'repeat'   => $repeatSemantic,
            'stopType' => $stopTypeSemantic,
        ];
        if ($type === 'command') {
            // Construct command payload, copying all entries except excluded fields
            $command = [];
            $exclude = [
                'enabled', 'sequence', 'day', 'startTime', 'startTimeOffset', 'endTime', 'endTimeOffset',
                'repeat', 'startDate', 'endDate', 'stopType', 'playlist', 'command'
            ];
            foreach ($d as $k => $v) {
                if (!in_array($k, $exclude, true)) {
                    $command[$k] = $v;
                }
            }
            $command['name'] = (string) $d['command'];
            $payload['command'] = $command;
        }

        // --- Ownership ---
        $ownership = [
            'source' => 'fpp',
        ];

        // --- Correlation ---
        $correlation = [
            'fpp' => [
                'raw' => $d,
            ],
        ];

        return $this->buildIntent(
            $type,
            $target,
            $canonicalTiming,
            $payload,
            $ownership,
            $correlation,
            $context
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
                    $tz
                );
            }
        }

        // If no RRULE exists at all and no end date was provided, treat as single-day event
        if ($endDateRaw === null && $raw->rrule === null) {
            $endDateRaw = $startDateRaw;
        }

        $startTimeOffset = $startTimeOffset ?? 0;
        $endTimeOffset   = $endTimeOffset ?? 0;
        $provenance = [];

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
        \DateTimeZone $tz
    ): ?string {
        $parsed = $this->parseRRuleUntil($untilRaw, $tz);
        if ($parsed === null) {
            return null;
        }

        [$untilDt, $isDateOnly] = $parsed;
        $untilDt = $untilDt->setTimezone($tz);

        // DATE-only UNTIL already represents the last allowed DTSTART date.
        $candidateDate = $untilDt->format('Y-m-d');

        // For date-time UNTIL, ensure the last DTSTART instant (time-of-day) is <= UNTIL.
        // If the UNTIL time-of-day is earlier than the event start time-of-day, the last DTSTART date is the previous day.
        if ($isDateOnly === false && $isAllDay === false && is_string($startTimeRaw) && $startTimeRaw !== '') {
            $startTime = \DateTimeImmutable::createFromFormat('H:i:s', $startTimeRaw, $tz)
                ?: \DateTimeImmutable::createFromFormat('H:i', $startTimeRaw, $tz);
            if ($startTime instanceof \DateTimeImmutable) {
                $untilSeconds = ((int)$untilDt->format('H')) * 3600 + ((int)$untilDt->format('i')) * 60 + (int)$untilDt->format('s');
                $startSeconds = ((int)$startTime->format('H')) * 3600 + ((int)$startTime->format('i')) * 60 + (int)$startTime->format('s');
                if ($untilSeconds < $startSeconds) {
                    $candidateDate = $untilDt->modify('-1 day')->format('Y-m-d');
                }
            }
        }

        // If this is a WEEKLY rule with BYDAY, snap the candidate end date backwards to the nearest matching day.
        if (is_array($daysRaw) && $daysRaw !== []) {
            $validDays = ['SU','MO','TU','WE','TH','FR','SA'];
            $allowed = array_values(array_filter($daysRaw, fn($d) => in_array($d, $validDays, true)));
            if ($allowed !== []) {
                $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $candidateDate, $tz);
                if ($dt instanceof \DateTimeImmutable) {
                    // Walk back up to 14 days (covers weekly schedules safely) to find a matching BYDAY.
                    for ($i = 0; $i < 14; $i++) {
                        $token = strtoupper($dt->format('D'));
                        $map = ['SUN'=>'SU','MON'=>'MO','TUE'=>'TU','WED'=>'WE','THU'=>'TH','FRI'=>'FR','SAT'=>'SA'];
                        $byday = $map[$token] ?? null;
                        if ($byday !== null && in_array($byday, $allowed, true)) {
                            return $dt->format('Y-m-d');
                        }
                        $dt = $dt->modify('-1 day');
                    }
                }
            }
        }

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

        // Canonicalize midnight end-time to previous day for timed intents.
        // For non-all-day intents, an end_time of 00:00:00 represents the start of the next day
        // and must not extend the inclusive intent window.
        if (
            $timing['all_day'] === false
            && isset($timing['end_time']['hard'])
            && $timing['end_time']['hard'] === '00:00:00'
            && isset($timing['end_date']['hard'])
            && is_string($timing['end_date']['hard'])
        ) {
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $timing['end_date']['hard']);
            if ($dt instanceof \DateTimeImmutable) {
                $timing['end_date']['hard'] = $dt->modify('-1 day')->format('Y-m-d');
            }
        }

        // Calendar recurrence semantics: calendar-derived end_date represents the first inactive day.
        // Convert to inclusive intent end date for recurring, timed calendar intents.
        if (
            $timing['all_day'] === false
            && ($draft->provenance['source'] ?? 'calendar') === 'calendar'
            && isset($timing['end_date']['hard'])
            && is_string($timing['end_date']['hard'])
        ) {
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $timing['end_date']['hard']);
            if ($dt instanceof \DateTimeImmutable) {
                $timing['end_date']['hard'] = $dt->modify('-1 day')->format('Y-m-d');
            }
        }

        return new CanonicalTiming($timing);
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
            $days = \GoogleCalendarScheduler\Platform\FPPSemantics::normalizeDays($raw);
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

        $subEvents = [[
            'timing'  => $timingArr,
            'payload' => $payload,
        ]];

        // Identity hash: canonicalize for hashing so symbolic dates are preferred.
        $hashInput = $this->canonicalizeForHash($identity, $subEvents);

        $hashJson = json_encode($hashInput, JSON_THROW_ON_ERROR);

        // TEMP DEBUG — capture exact hash preimage (remove after hash parity is verified)
        $source =
            $ownership['controller']
            ?? ($ownership['source'] ?? 'unknown');

        file_put_contents(
            '/tmp/gcs-hash-preimage-' . $source . '.json',
            $hashJson . PHP_EOL,
            FILE_APPEND
        );

        $identityHash = hash('sha256', $hashJson);

        return new Intent(
            $identityHash,
            $identity,
            $ownership,
            $correlation,
            $subEvents
        );
    }

    /**
     * Canonicalize identity and subEvents for hashing.
     *
     * RULE:
     * - If symbolic date exists → hash symbolic ONLY
     * - Else → hash hard ONLY
     * - NEVER hash both
     *
     * This affects hashing only. Identity storage is untouched.
     */
    /**
     * Build explicit, minimal hash preimage.
     *
     * RULES:
     * - Only identity + timing + payload semantics that define intent identity
     * - Prefer symbolic dates if present, otherwise hard
     * - Never include both symbolic and hard
     * - Exclude ownership, correlation, provenance, raw payload noise
     */
    private function canonicalizeForHash(array $identity, array $subEvents): array
    {
        $pickDate = function (?array $date): ?array {
            if ($date === null) {
                return null;
            }
            if (!empty($date['symbolic'])) {
                return ['symbolic' => $date['symbolic']];
            }
            if (!empty($date['hard'])) {
                return ['hard' => $date['hard']];
            }
            return null;
        };

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

        $timing = $identity['timing'];

        $canonicalTiming = [
            'all_day'    => (bool)$timing['all_day'],
            'start_date' => $pickDate($timing['start_date'] ?? null),
            'end_date'   => $pickDate($timing['end_date'] ?? null),
            'start_time' => $pickTime($timing['start_time'] ?? null),
            'end_time'   => $pickTime($timing['end_time'] ?? null),
            'days'       => $timing['days'] ?? null,
        ];

        $canonicalSubEvent = $subEvents[0];

        return [
            'identity' => [
                'type'   => $identity['type'],
                'target' => $identity['target'],
                'timing' => $canonicalTiming,
            ],
            'subEvents' => [[
                'timing'  => $canonicalTiming,
                'payload' => [
                    'enabled'  => (bool)$canonicalSubEvent['payload']['enabled'],
                    'repeat'   => (string)$canonicalSubEvent['payload']['repeat'],
                    'stopType' => (string)$canonicalSubEvent['payload']['stopType'],
                ],
            ]],
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
