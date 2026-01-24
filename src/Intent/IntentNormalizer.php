<?php
declare(strict_types=1);

namespace GoogleCalendarScheduler\Intent;

use GoogleCalendarScheduler\Intent\CalendarRawEvent;
use GoogleCalendarScheduler\Intent\FppRawEvent;
use GoogleCalendarScheduler\Intent\NormalizationContext;
use GoogleCalendarScheduler\Platform\YamlMetadata;

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
        // --- Parse base timestamps ---
        $tz = $context->timezone;

        $start = new \DateTimeImmutable($raw->dtstart);
        $start = $start->setTimezone($tz);

        $end = new \DateTimeImmutable($raw->dtend);
        $end = $end->setTimezone($tz);

        // --- YAML-driven type resolution ---
        $yaml = YamlMetadata::fromDescription($raw->description);
        $type = $yaml['type'] ?? null;
        $type = \GoogleCalendarScheduler\Platform\FPPSemantics::normalizeType($type);

        // --- Identity (human intent) ---
        // All-day normalization is intentional and occurs ONLY at the Intent layer.
        // Raw calendar data must never coerce or invent times.
        // Handle all-day events explicitly:
        // For all-day intents, times are null because the event spans entire days without specific start/end times.
        // This preserves the intent without coercing times to 23:59:59 or 24:00:00.
        // Date normalization happens here (IntentNormalizer) to ensure consistent interpretation,
        // rather than in earlier layers like CalendarTranslator.
        $isAllDay = ($raw->isAllDay ?? false) === true;

        $startTimeHard = $start->format('H:i:s');
        $endTimeHard   = $end->format('H:i:s');

        // Calendar all-day events typically use an exclusive DTEND boundary.
        // Intent represents whole-day span with times omitted.
        $endDateHard = $end->format('Y-m-d');
        if ($isAllDay) {
            $startTimeHard = null;
            $endTimeHard   = null;
            $endDateHard   = $end->modify('-1 day')->format('Y-m-d');
        }

        $identity = [
            'type'   => $type,
            'target' => $raw->summary,
            'timing' => [
                'start_date' => [
                    'hard' => $start->format('Y-m-d'),
                    'symbolic' => null,
                ],
                'end_date' => [
                    'hard' => $endDateHard,
                    'symbolic' => null,
                ],
                'start_time' => [
                    'hard' => $startTimeHard,
                    'symbolic' => null,
                    'offset' => 0,
                ],
                'end_time' => [
                    'hard' => $endTimeHard,
                    'symbolic' => null,
                    'offset' => 0,
                ],
                'days' => null,
            ],
        ];

        // --- Behavior defaults ---
        $behavior = $context->fpp::defaultBehavior();

        // --- Recurrence handling (NO expansion) ---
        // FPP scheduler operates on date ranges. We NEVER generate one subEvent per day.
        // RRULE is used ONLY to compute the intent window (end_date).
        $rrule = $raw->rrule ?? null;

        // Default identity end_date comes from DTEND (already adjusted for all-day exclusivity above).
        $endDateHardForIntent = $identity['timing']['end_date']['hard'];

        // EXDATE support: if DTSTART is excluded, we cannot represent “skip first occurrence”
        // without expanding into multiple windows, which is forbidden. Fail fast.
        $exDates = $raw->provenance['exDates'] ?? [];
        if (is_array($exDates) && $rrule !== null && count($exDates) > 0) {
            $firstKey = $isAllDay
                ? $start->format('Y-m-d')
                : $start->format('Y-m-d H:i:s');

            foreach ($exDates as $ex) {
                try {
                    $exDt = new \DateTimeImmutable((string)$ex, $tz);
                    $exKey = $isAllDay
                        ? $exDt->format('Y-m-d')
                        : $exDt->format('Y-m-d H:i:s');
                    if ($exKey === $firstKey) {
                        throw new \RuntimeException('EXDATE excludes DTSTART; cannot normalize without expanding into multiple windows');
                    }
                } catch (\Throwable) {
                    // ignore invalid EXDATE
                }
            }
        }

        if ($rrule !== null) {
            $freq = strtoupper((string)($rrule['FREQ'] ?? ''));
            if ($freq === '') {
                throw new \RuntimeException('RRULE present but missing FREQ');
            }
            if ($freq !== 'DAILY') {
                throw new \RuntimeException('Unsupported RRULE frequency');
            }

            // Determine the inclusive last date of the recurrence window.
            // Supported: UNTIL or COUNT.
            // NOTE: We do not expand occurrences; we only compute the final end_date.

            // 1) UNTIL
            $until = null;
            if (isset($rrule['UNTIL']) && is_string($rrule['UNTIL']) && $rrule['UNTIL'] !== '') {
                $untilStr = $rrule['UNTIL'];
                try {
                    if (preg_match('/^\d{8}T\d{6}Z$/', $untilStr)) {
                        $u = \DateTimeImmutable::createFromFormat('Ymd\\THis\\Z', $untilStr, new \DateTimeZone('UTC'));
                        $until = $u ? $u->setTimezone($tz) : null;
                    } elseif (preg_match('/^\d{8}T\d{6}$/', $untilStr)) {
                        $u = \DateTimeImmutable::createFromFormat('Ymd\\THis', $untilStr, $tz);
                        $until = $u ?: null;
                    } elseif (preg_match('/^\d{8}$/', $untilStr)) {
                        $u = \DateTimeImmutable::createFromFormat('Ymd', $untilStr, $tz);
                        // date-only UNTIL is inclusive for that whole day
                        $until = $u ? $u->setTime(23, 59, 59) : null;
                    }
                } catch (\Throwable) {
                    $until = null;
                }
            }

            if ($until !== null) {
                // Inclusive last occurrence date is the UNTIL date in local tz.
                $lastStartDate = $until->format('Y-m-d');

                // Identity window end_date should be the last day on which an occurrence runs.
                // If the event crosses midnight, the effective end_date is +1 day from the last start.
                if ($isAllDay) {
                    // For all-day, duration is whole-day span; end_date should be lastStartDate + (durationDays-1).
                    $durationDays = (int)$start->diff($end)->format('%a');
                    if ($durationDays <= 0) {
                        $durationDays = 1;
                    }
                    $endDateHardForIntent = (new \DateTimeImmutable($lastStartDate, $tz))
                        ->modify('+' . ($durationDays - 1) . ' days')
                        ->modify('-1 day')
                        ->format('Y-m-d');
                } else {
                    $crossesMidnight = $end->format('Y-m-d') !== $start->format('Y-m-d');
                    $endDateHardForIntent = $crossesMidnight
                        ? (new \DateTimeImmutable($lastStartDate, $tz))->modify('+1 day')->modify('-1 day')->format('Y-m-d')
                        : (new \DateTimeImmutable($lastStartDate, $tz))->modify('-1 day')->format('Y-m-d');
                }
            } else {
                // 2) COUNT (only for DAILY)
                $count = null;
                if (isset($rrule['COUNT']) && is_numeric($rrule['COUNT'])) {
                    $count = (int)$rrule['COUNT'];
                }
                if ($count === null || $count <= 0) {
                    throw new \RuntimeException('RRULE must provide UNTIL or COUNT');
                }

                // COUNT includes the DTSTART occurrence.
                $interval = 1;
                if (isset($rrule['INTERVAL']) && is_numeric($rrule['INTERVAL'])) {
                    $interval = max(1, (int)$rrule['INTERVAL']);
                }

                $daysToAdd = ($count - 1) * $interval;
                $lastStart = $start->modify('+' . $daysToAdd . ' days');
                $lastStartDate = $lastStart->format('Y-m-d');

                if ($isAllDay) {
                    $durationDays = (int)$start->diff($end)->format('%a');
                    if ($durationDays <= 0) {
                        $durationDays = 1;
                    }
                    $endDateHardForIntent = (new \DateTimeImmutable($lastStartDate, $tz))
                        ->modify('+' . ($durationDays - 1) . ' days')
                        ->format('Y-m-d');
                } else {
                    $crossesMidnight = $end->format('Y-m-d') !== $start->format('Y-m-d');
                    $endDateHardForIntent = $crossesMidnight
                        ? (new \DateTimeImmutable($lastStartDate, $tz))->modify('+1 day')->format('Y-m-d')
                        : $lastStartDate;
                }
            }

            // Apply the computed end_date to the identity window.
            $identity['timing']['end_date']['hard'] = $endDateHardForIntent;
        }

        // Always produce exactly ONE subEvent: a single date-range window.
        $subEvents = [[
            'timing'   => [
                'start_date' => $identity['timing']['start_date'],
                'end_date'   => $identity['timing']['end_date'],
                'start_time' => [
                    'hard' => $startTimeHard,
                    'symbolic' => null,
                    'offset' => 0,
                ],
                'end_time' => [
                    'hard' => $endTimeHard,
                    'symbolic' => null,
                    'offset' => 0,
                ],
                'days' => null,
            ],
            'behavior' => $behavior,
            'payload'  => null,
        ]];

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

        // --- Identity hash ---
        $identityHash = hash(
            'sha256',
            json_encode(
                ['identity' => $identity, 'subEvents' => $subEvents],
                JSON_THROW_ON_ERROR
            )
        );

        return new Intent(
            $identityHash,
            $identity,
            $ownership,
            $correlation,
            $subEvents
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

        // --- Required fields validation (fail fast) ---
        foreach (['playlist', 'startDate', 'endDate', 'startTime', 'endTime'] as $k) {
            if (!isset($d[$k])) {
                throw new \RuntimeException("FPP raw event missing required field: {$k}");
            }
        }

        // --- Type normalization ---
        $type = ($d['sequence'] ?? 0) ? 'sequence' : 'playlist';
        $type = \GoogleCalendarScheduler\Platform\FPPSemantics::normalizeType($type);

        // --- Target normalization ---
        // Strip known file extensions (.fseq)
        $target = (string)$d['playlist'];
        $target = preg_replace('/\.fseq$/i', '', $target);

        // --- Identity timing ---
        $identityTiming = [
            'start_date' => $d['startDate'],
            'end_date'   => $d['endDate'],
            'start_time' => $d['startTime'],
            'end_time'   => $d['endTime'],
            'days'       => isset($d['day']) ? (int)$d['day'] : null,
        ];

        $identity = [
            'type'   => $type,
            'target' => $target,
            'timing' => $identityTiming,
        ];

        // --- Behavior (fully explicit) ---
        $behavior = [
            'enabled'  => \GoogleCalendarScheduler\Platform\FPPSemantics::normalizeEnabled($d['enabled'] ?? true),
            'repeat'   => isset($d['repeat'])
                ? \GoogleCalendarScheduler\Platform\FPPSemantics::normalizeRepeat($d['repeat'])
                : \GoogleCalendarScheduler\Platform\FPPSemantics::defaultRepeatForType($type),
            'stopType' => \GoogleCalendarScheduler\Platform\FPPSemantics::stopTypeToEnum($d['stopType'] ?? null),
        ];

        // --- SubEvents ---
        $subEvents = [[
            'timing'   => [
                'start_date' => $d['startDate'],
                'end_date'   => $d['endDate'],
                'start_time' => $d['startTime'],
                'end_time'   => $d['endTime'],
                'days'       => isset($d['day']) ? (int)$d['day'] : null,
            ],
            'behavior' => $behavior,
            'payload'  => null,
        ]];

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

        // --- Identity hash ---
        $identityHash = hash('sha256', json_encode([
            'identity'  => $identity,
            'subEvents' => $subEvents,
        ], JSON_THROW_ON_ERROR));

        return new Intent(
            $identityHash,
            $identity,
            $ownership,
            $correlation,
            $subEvents
        );
    }
}