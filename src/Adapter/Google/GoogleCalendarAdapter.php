<?php
declare(strict_types=1);

namespace CalendarScheduler\Adapter\Google;

use CalendarScheduler\Intent\NormalizationContext;
use CalendarScheduler\Platform\FPPSemantics;
use CalendarScheduler\Platform\HolidayResolver;
use CalendarScheduler\Platform\IniMetadata;

/**
 * GoogleCalendarAdapter
 *
 * Google-specific adapter that converts a provider-native Google event array
 * into a canonical, source-agnostic manifest event array.
 *
 * HARD RULES:
 * - This adapter owns all provider quirks (DTEND exclusivity, RRULE UNTIL semantics, BYDAY rules, etc.)
 * - Output MUST be timezone-normalized to FPP timezone ($context->timezone)
 * - Output MUST be semantically canonical and MUST NOT require downstream fixes
 *
 * Any failure to adapt MUST throw.
 */
final class GoogleCalendarAdapter
{
    /**
     * Load and normalize all Google calendar events from a snapshot file, returning manifest event arrays.
     *
     * @param NormalizationContext $context
     * @param string $snapshotPath
     * @return array Manifest event arrays
     */
    public function loadManifestEvents(NormalizationContext $context, string $snapshotPath): array
    {
        if (!is_file($snapshotPath)) {
            throw new \RuntimeException("Calendar snapshot not found: {$snapshotPath}");
        }

        $data = json_decode(file_get_contents($snapshotPath), true);
        if (!is_array($data)) {
            throw new \RuntimeException("Invalid calendar snapshot JSON");
        }

        $manifestEvents = [];
        foreach ($data as $event) {
            $manifestEvents[] = $this->toManifestEvent($event, $context);
        }
        return $manifestEvents;
    }

    /**
     * Convert a single Google event into a manifest event array.
     * Adapter-internal only.
     */
    private function toManifestEvent(
        array $googleEvent,
        NormalizationContext $context
    ): array {
        // Cast to object for property-style access
        $raw = (object)$googleEvent;
        // --- INI-driven type/payload semantics (existing logic) ---
        $meta        = IniMetadata::fromDescription($raw->description);
        $settings    = $meta['settings'] ?? [];
        $symbolic    = $meta['symbolic_time'] ?? [];
        $commandMeta = $meta['command'] ?? [];

        $type = $settings['type'] ?? null;
        $type = FPPSemantics::normalizeType($type);

        $this->validateSettingsSection($settings);
        $this->validateSymbolicTimeSection($symbolic);
        $this->validateCommandSection($commandMeta, $type);

        // --- Timing (Google/RFC5545 quirks handled here) ---
        $draft = $this->draftTimingFromCalendar($googleEvent, $context, $symbolic);
        $timing = $this->normalizeTiming($draft, $context);

        // --- Execution payload (FPP semantics) ---
        $defaults = FPPSemantics::defaultBehavior();

        $payload = [
            'enabled'  => FPPSemantics::normalizeEnabled($settings['enabled'] ?? $defaults['enabled']),
            'stopType' => $settings['stopType'] ?? 'graceful',
        ];

        if (isset($settings['repeat']) && is_string($settings['repeat'])) {
            $payload['repeat'] = strtolower(trim($settings['repeat']));
        } else {
            $defaultNumeric = FPPSemantics::defaultRepeatForType($type);
            $payload['repeat'] = FPPSemantics::repeatToSemantic($defaultNumeric);
        }

        if ($type === 'command' && is_array($commandMeta) && $commandMeta !== []) {
            $payload['command'] = $commandMeta;
        }

        // --- Target ---
        // For calendar, we use the summary as the target (consistent with existing code)
        $target = (string)($raw->summary ?? '');
        $target = trim($target);
        if ($target === '') {
            throw new \RuntimeException('Calendar raw event missing summary/target');
        }

        // --- Ownership ---
        $ownership = [
            'managed'    => true,
            'controller' => 'calendar',
            'locked'     => false,
        ];

        // --- Correlation ---
        $correlation = [
            'source' => 'calendar',
            'uid'    => $raw->provenance['uid'] ?? null,
        ];

        // --- Source updated timestamp (authority) ---
        // IcsParser is authoritative for timestamps.
        // Prefer provenance.updatedAtEpoch, which already accounts for
        // LAST-MODIFIED > DTSTAMP > CREATED and is normalized to epoch.
        if (!isset($raw->provenance) || !is_array($raw->provenance)) {
            throw new \RuntimeException('Google adapter missing provenance in event');
        }
        if (!isset($raw->provenance['updatedAtEpoch']) || (int)$raw->provenance['updatedAtEpoch'] <= 0) {
            throw new \RuntimeException('Google adapter could not determine valid updated timestamp');
        }
        $updatedAtEpoch = (int)$raw->provenance['updatedAtEpoch'];

        // --- Hashes: identityHash and stateHash (as previously fed to IntentNormalizer) ---
        // The identityHash and stateHash are computed using the same logic as before:
        // identityHash: hash of (source, type, target, timing, payload, correlation)
        // stateHash:    hash of (timing, payload, ownership, correlation)

        // Use stable JSON encoding for hash inputs
        $identityData = [
            'source'      => 'calendar',
            'type'        => $type,
            'target'      => $target,
            'timing'      => $timing,
            'payload'     => $payload,
            'correlation' => $correlation,
        ];
        $stateData = [
            'timing'      => $timing,
            'payload'     => $payload,
            'ownership'   => $ownership,
            'correlation' => $correlation,
        ];
        $identityHash = hash('sha256', json_encode($identityData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $stateHash    = hash('sha256', json_encode($stateData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return [
            'source'         => 'calendar',
            'type'           => $type,
            'target'         => $target,
            'timing'         => $timing,
            'payload'        => $payload,
            'ownership'      => $ownership,
            'correlation'    => $correlation,
            'updatedAtEpoch' => $updatedAtEpoch,
            'identityHash'   => $identityHash,
            'stateHash'      => $stateHash,
        ];
    }


    // ============================================================
    // Calendar timing adaptation (migrated from IntentNormalizer)
    // ============================================================

    private function draftTimingFromCalendar(
        array $googleEvent,
        NormalizationContext $context,
        array $symbolicTime = []
    ): DraftTiming {
        $raw = (object)$googleEvent;
        $provenance = ['source' => 'calendar'];
        $isAllDay = ($raw->isAllDay ?? false) === true;
        $tz = $context->timezone;

        $startDateRaw = null;
        $endDateRaw   = null;
        $startTimeRaw = null;
        $endTimeRaw   = null;
        $daysRaw      = null;

        $startDt = null;

        if (is_string($raw->dtstart) && trim($raw->dtstart) !== '') {
            $dtstart = trim($raw->dtstart);

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
                if (
                    !$isAllDay
                    && preg_match('/T\d{2}:\d{2}:\d{2}| \d{2}:\d{2}:\d{2}$/', $dtstart)
                ) {
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

                // Date portion for end_date (only if DTEND is date-only in legacy situations)
                // NOTE: for RRULE all-day events we intentionally do NOT use DTEND to set end_date.
                // For non-RRULE single events, we treat end_date as start_date unless a real intent end is given.
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dtend)) {
                    $endDateRaw = $endDt->format('Y-m-d');
                }

                // Only set time if DTEND explicitly includes a time component
                if (
                    !$isAllDay
                    && preg_match('/T\d{2}:\d{2}:\d{2}| \d{2}:\d{2}:\d{2}$/', $dtend)
                ) {
                    $endTimeRaw = $endDt->format('H:i:s');
                }
            }

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
        $startTimeOffset = 0;
        $endTimeOffset   = 0;

        if (isset($symbolicTime['start']) && is_string($symbolicTime['start'])) {
            $startTimeRaw = $symbolicTime['start'];
            if (isset($symbolicTime['start_offset'])) {
                $startTimeOffset = (int)$symbolicTime['start_offset'];
            }
        }

        if (isset($symbolicTime['end']) && is_string($symbolicTime['end'])) {
            $endTimeRaw = $symbolicTime['end'];
            if (isset($symbolicTime['end_offset'])) {
                $endTimeOffset = (int)$symbolicTime['end_offset'];
            }
        }

        // RRULE rule-based timing semantics
        if (is_array($raw->rrule)) {
            $rrule = $raw->rrule;

            if (isset($rrule['FREQ'])) {
                $freq = strtoupper((string)$rrule['FREQ']);
                if ($freq === 'DAILY') {
                    $daysRaw = null;
                } elseif ($freq === 'WEEKLY') {
                    if (!isset($rrule['BYDAY'])) {
                        throw new \RuntimeException('RRULE:FREQ=WEEKLY requires BYDAY');
                    }
                } else {
                    throw new \RuntimeException("Unsupported RRULE FREQ: {$freq}");
                }
            }

            // Extract BYDAY (no expansion)
            if (isset($rrule['BYDAY'])) {
                $byday = $rrule['BYDAY'];
                if (is_string($byday)) {
                    $daysRaw = array_values(
                        array_filter(array_map('trim', explode(',', $byday)))
                    );
                } elseif (is_array($byday)) {
                    $daysRaw = array_values($byday);
                }

                if ($daysRaw === []) {
                    throw new \RuntimeException('Calendar RRULE with BYDAY must specify at least one day');
                }

                $validDays = ['SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA'];
                foreach ($daysRaw as $d) {
                    if (!in_array($d, $validDays, true)) {
                        throw new \RuntimeException("Invalid BYDAY token '{$d}' in calendar RRULE");
                    }
                }
            }

            // BYDAY requires FREQ=WEEKLY
            if ($daysRaw !== null) {
                if (isset($rrule['FREQ']) && strtoupper((string)$rrule['FREQ']) !== 'WEEKLY') {
                    throw new \RuntimeException('Calendar RRULE with BYDAY must use FREQ=WEEKLY');
                }
            }

            // UNTIL -> inclusive intent end_date
            if (isset($rrule['UNTIL'])) {
                $endDateRaw = $this->computeIntentEndDateFromRRuleUntil(
                    $rrule['UNTIL'],
                    $startDt instanceof \DateTimeImmutable ? $startDt : null,
                    $startTimeRaw,
                    $isAllDay,
                    is_array($daysRaw) ? $daysRaw : null,
                    $tz,
                    $raw->provenance['uid'] ?? null,
                    $raw->summary ?? null
                );
                $provenance['end_date_source'] = 'rrule';
                $provenance['end_date_final']  = true;
            }
        }

        // If no RRULE exists and no end date was provided, treat as single-day event
        if ($endDateRaw === null && $raw->rrule === null) {
            $endDateRaw = $startDateRaw;
        }

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
     * Inclusive intent end date from RRULE UNTIL (migrated).
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
        if ($parsed === null) {
            return null;
        }

        [$untilDt, $isDateOnly] = $parsed;
        $untilDt = $untilDt->setTimezone($tz);

        $candidateDate = $untilDt->format('Y-m-d');

        // RFC 5545 semantics:
        // If timed and DTSTART(time-of-day) would occur after UNTIL instant on that date,
        // then there is no occurrence on UNTIL date -> roll back one day.
        if ($isAllDay === false && $dtstart instanceof \DateTimeImmutable) {
            $candidateStart = $dtstart->setDate(
                (int)$untilDt->format('Y'),
                (int)$untilDt->format('m'),
                (int)$untilDt->format('d')
            );
            if ($candidateStart > $untilDt) {
                $candidateDate = $untilDt->modify('-1 day')->format('Y-m-d');
            }
        }

        // IMPORTANT:
        // Preserve calendar UNTIL date intent.
        // Do NOT snap to last matching BYDAY.
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

    /**
     * Normalize DraftTiming into canonical timing array (migrated),
     * including calendar DTEND exclusivity rules.
     *
     * Returns timing array in RawEvent contract shape.
     */
    private function normalizeTiming(
        DraftTiming $draft,
        NormalizationContext $context
    ): array {
        // Guard: prevent 00:00–24:00 from leaking when not all-day
        if (
            $draft->startTimeRaw === '00:00:00'
            && $draft->endTimeRaw === '24:00:00'
            && $draft->startTimeOffset === 0
            && $draft->endTimeOffset === 0
            && $draft->isAllDay === false
        ) {
            throw new \RuntimeException('Invalid timing: 00:00–24:00 must be represented as all-day intent');
        }

        $resolver = $context->holidayResolver;

        $timing = [
            'all_day' => $draft->isAllDay === true,
            'start_date' => $this->normalizeDateField($draft->startDateRaw, $resolver),
            'end_date'   => $this->normalizeDateField($draft->endDateRaw, $resolver),
            'start_time' => $this->normalizeTimeField($draft->startTimeRaw, $draft->startTimeOffset),
            'end_time'   => $this->normalizeTimeField($draft->endTimeRaw, $draft->endTimeOffset),
            'days'       => $this->normalizeDays($draft->daysRaw),
        ];

        // Calendar DTEND end-date policy:
        if (($draft->provenance['end_date_final'] ?? false) !== true) {
            $timing = $this->resolveCalendarEndDate($draft, $timing);
        }

        return $timing;
    }

    /**
     * DTEND-derived end dates may be exclusive at midnight; shift back one day.
     */
    private function resolveCalendarEndDate(DraftTiming $draft, array $timing): array
    {
        if (($draft->provenance['source'] ?? null) !== 'calendar') {
            return $timing;
        }

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

    private function normalizeDateField(?string $raw, HolidayResolver $resolver): array
    {
        if ($raw === null || $raw === '') {
            return ['hard' => null, 'symbolic' => null];
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return ['hard' => $raw, 'symbolic' => null];
        }

        if ($resolver->isSymbolic($raw)) {
            return ['hard' => null, 'symbolic' => $raw];
        }

        return ['hard' => null, 'symbolic' => $raw];
    }

    private function normalizeTimeField(?string $raw, int $offset): array
    {
        if ($raw === null || $raw === '') {
            return ['hard' => null, 'symbolic' => null, 'offset' => $offset];
        }

        if (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $raw)) {
            return ['hard' => $raw, 'symbolic' => null, 'offset' => $offset];
        }

        return ['hard' => null, 'symbolic' => $raw, 'offset' => $offset];
    }

    /**
     * Normalize days into canonical {type:weekly,value:[...]} or null.
     *
     * Accepts:
     * - null
     * - array of tokens ['MO',...]
     * - associative ['type'=>'weekly','value'=>[...]]
     * - leaked ['weekly',[...]] (idempotent unwrap)
     */
    private function normalizeDays(array|int|null $raw): ?array
    {
        if ($raw === null) {
            return null;
        }

        // Google adapter should not receive int bitmasks; that’s FPP adapter territory.
        if (is_int($raw)) {
            throw new \RuntimeException('Google adapter received integer days; expected calendar BYDAY tokens');
        }

        $order = ['SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA'];

        $unwrapWeekly = function(array $v): array {
            while (array_is_list($v) && count($v) === 2 && $v[0] === 'weekly' && is_array($v[1])) {
                $v = $v[1];
            }
            return $v;
        };

        $raw = $unwrapWeekly($raw);

        if (isset($raw['type'], $raw['value']) && $raw['type'] === 'weekly' && is_array($raw['value'])) {
            $raw['value'] = $unwrapWeekly($raw['value']);
        }

        if (array_is_list($raw)) {
            $days = array_values(array_unique($raw));
        } elseif (isset($raw['type'], $raw['value']) && $raw['type'] === 'weekly' && is_array($raw['value'])) {
            $days = array_values(array_unique($raw['value']));
        } else {
            throw new \RuntimeException('Invalid days array shape in Google adapter');
        }

        if ($days === [] || count($days) === 7) {
            return null;
        }

        usort($days, fn($a, $b) =>
            array_search($a, $order, true) <=> array_search($b, $order, true)
        );

        return ['type' => 'weekly', 'value' => $days];
    }

    // ============================================================
    // INI metadata validation (migrated)
    // ============================================================

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
            throw new \RuntimeException('[command] section is only valid for type=command');
        }

        foreach ($commandMeta as $k => $v) {
            if (!is_scalar($v) && !is_array($v)) {
                throw new \RuntimeException("Invalid value for command field '{$k}'");
            }
        }
    }
}

/**
 * DraftTiming (adapter-local)
 * Mirrors previous IntentNormalizer transitional structure.
 */
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
