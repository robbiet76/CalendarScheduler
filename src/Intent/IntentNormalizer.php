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

        $start = new \DateTimeImmutable($raw->dtstart, $tz);
        $end   = new \DateTimeImmutable($raw->dtend, $tz);

        // --- Identity (human intent) ---
        $identity = [
            'type'   => \GoogleCalendarScheduler\Platform\FPPSemantics::TYPE_PLAYLIST,
            'target' => $raw->summary,
            'timing' => [
                'start_date' => [
                    'hard' => $start->format('Y-m-d'),
                    'symbolic' => null,
                ],
                'end_date' => [
                    'hard' => $end->format('Y-m-d'),
                    'symbolic' => null,
                ],
                'start_time' => [
                    'hard' => $start->format('H:i:s'),
                    'symbolic' => null,
                    'offset' => 0,
                ],
                'end_time' => [
                    'hard' => $end->format('H:i:s'),
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
        if ($rrule === null) {
            // No recurrence: single occurrence
            $subEvents[] = [
                'timing'   => $identity['timing'],
                'behavior' => $behavior,
                'payload'  => null,
            ];
        } else {
            // Only support FREQ=DAILY for now
            $freq = strtoupper($rrule['FREQ'] ?? '');
            if ($freq !== 'DAILY') {
                throw new \RuntimeException("Unsupported RRULE frequency");
            }
            // Parse UNTIL if present
            $until = null;
            if (isset($rrule['UNTIL'])) {
                // Try to parse as UTC or local
                $untilStr = $rrule['UNTIL'];
                // Acceptable formats: 20240601T120000Z or 20240601T120000
                if (preg_match('/Z$/', $untilStr)) {
                    $until = new \DateTimeImmutable($untilStr);
                } else {
                    $until = new \DateTimeImmutable($untilStr, $tz);
                }
            }
            // Expand daily occurrences
            $curStart = $start;
            $duration = $end->getTimestamp() - $start->getTimestamp();
            while (true) {
                // Stop if UNTIL is set and curStart > UNTIL
                if ($until !== null && $curStart > $until) {
                    break;
                }
                $curEnd = $curStart->modify("+{$duration} seconds");
                $timing = [
                    'start_date' => [
                        'hard' => $curStart->format('Y-m-d'),
                        'symbolic' => null,
                    ],
                    'end_date' => [
                        'hard' => $curEnd->format('Y-m-d'),
                        'symbolic' => null,
                    ],
                    'start_time' => [
                        'hard' => $curStart->format('H:i:s'),
                        'symbolic' => null,
                        'offset' => 0,
                    ],
                    'end_time' => [
                        'hard' => $curEnd->format('H:i:s'),
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
                // Next day
                $curStart = $curStart->modify('+1 day');
                // EXDATE handling to be added later
            }
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