<?php
declare(strict_types=1);

namespace GoogleCalendarScheduler\Intent;

use GoogleCalendarScheduler\Intent\CalendarRawEvent;
use GoogleCalendarScheduler\Intent\FppRawEvent;
use GoogleCalendarScheduler\Intent\NormalizationContext;

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
            'type'   => \GoogleCalendarScheduler\Platform\FPPSemantics::TYPE_PLAYLIST,
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

        // --- Recurrence Expansion ---
        $subEvents = [];
        $rrule = $raw->rrule ?? null;

        // Helper: compute per-occurrence end (and end_date hard) based on start + duration.
        $baseDurationSeconds = $end->getTimestamp() - $start->getTimestamp();

        // All-day duration in whole days using exclusive DTEND semantics.
        // Example: start=2025-11-15 00:00, end=2025-11-16 00:00 => durationDays=1.
        $durationDays = 0;
        if ($isAllDay) {
            $durationDays = (int)$start->diff($end)->format('%a');
            if ($durationDays <= 0) {
                $durationDays = 1;
            }
        }

        // Parse UNTIL (RFC5545). This is a boundary on recurrence generation.
        // We compare occurrences by DTSTART (start) and treat UNTIL as inclusive.
        $until = null;
        if (is_array($rrule) && isset($rrule['UNTIL']) && is_string($rrule['UNTIL']) && $rrule['UNTIL'] !== '') {
            $untilStr = $rrule['UNTIL'];

            // RFC common forms:
            // - 20251127T075959Z
            // - 20251127T075959
            // - 20251127 (rare here)
            try {
                if (preg_match('/^\d{8}T\d{6}Z$/', $untilStr)) {
                    $u = \DateTimeImmutable::createFromFormat('Ymd\\THis\\Z', $untilStr, new \DateTimeZone('UTC'));
                    $until = $u ? $u->setTimezone($tz) : null;
                } elseif (preg_match('/^\d{8}T\d{6}$/', $untilStr)) {
                    $u = \DateTimeImmutable::createFromFormat('Ymd\\THis', $untilStr, $tz);
                    $until = $u ?: null;
                } elseif (preg_match('/^\d{8}$/', $untilStr)) {
                    $u = \DateTimeImmutable::createFromFormat('Ymd', $untilStr, $tz);
                    $until = $u ? $u->setTime(23, 59, 59) : null;
                }
            } catch (\Throwable) {
                $until = null;
            }
        }

        $exDateSet = [];
        $exDates = $raw->provenance['exDates'] ?? [];
        if (is_array($exDates)) {
            foreach ($exDates as $ex) {
                try {
                    $exDt = new \DateTimeImmutable($ex, $tz);
                    $key = $isAllDay
                        ? $exDt->format('Y-m-d')
                        : $exDt->format('Y-m-d H:i:s');
                    $exDateSet[$key] = true;
                } catch (\Throwable) {
                    // ignore invalid EXDATE
                }
            }
        }

        if ($rrule === null) {
            // No recurrence: single occurrence
            $subEvents[] = [
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
            ];
        } else {
            // Only support FREQ=DAILY (for now)
            $freq = strtoupper((string)($rrule['FREQ'] ?? ''));
            if ($freq !== 'DAILY') {
                throw new \RuntimeException('Unsupported RRULE frequency');
            }

            // Interval (default 1)
            $interval = 1;
            if (isset($rrule['INTERVAL']) && is_numeric($rrule['INTERVAL'])) {
                $interval = max(1, (int)$rrule['INTERVAL']);
            }

            $countLimit = null;
            if (isset($rrule['COUNT']) && is_numeric($rrule['COUNT'])) {
                $countLimit = max(0, (int)$rrule['COUNT']);
            }
            $generatedCount = 0;

            // We will generate occurrences from DTSTART, stepping by INTERVAL days.
            $curStart = $start;

            // Track final occurrence end_date for identity normalization.
            $finalEndDateHard = $identity['timing']['end_date']['hard'];

            while (true) {
                // UNTIL is inclusive. Stop when DTSTART is strictly after UNTIL.
                if ($until !== null && $curStart > $until) {
                    break;
                }

                $exKey = $isAllDay
                    ? $curStart->format('Y-m-d')
                    : $curStart->format('Y-m-d H:i:s');

                if (isset($exDateSet[$exKey])) {
                    $curStart = $curStart->modify('+' . $interval . ' days');
                    continue;
                }

                if ($countLimit !== null && $generatedCount >= $countLimit) {
                    break;
                }

                // Compute end for this occurrence.
                if ($isAllDay) {
                    $curEndExclusive = $curStart->modify('+' . $durationDays . ' days');
                    $curEndDateHard  = $curEndExclusive->modify('-1 day')->format('Y-m-d');
                } else {
                    $curEnd = $curStart->modify('+' . $baseDurationSeconds . ' seconds');
                    $curEndDateHard = $curEnd->format('Y-m-d');
                }

                $timing = [
                    'start_date' => [
                        'hard' => $curStart->format('Y-m-d'),
                        'symbolic' => null,
                    ],
                    'end_date' => [
                        'hard' => $curEndDateHard,
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
                ];

                $subEvents[] = [
                    'timing'   => $timing,
                    'behavior' => $behavior,
                    'payload'  => null,
                ];

                // Track final end date.
                $finalEndDateHard = $curEndDateHard;

                $generatedCount++;

                // Step forward by INTERVAL days.
                $curStart = $curStart->modify('+' . $interval . ' days');
            }

            // For recurring events, identity should reflect the final expanded window.
            $identity['timing']['end_date']['hard'] = $finalEndDateHard;
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