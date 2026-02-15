<?php
declare(strict_types=1);

namespace CalendarScheduler\Intent;

use CalendarScheduler\Intent\NormalizationContext;
use CalendarScheduler\Platform\HolidayResolver;

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
 * - holiday/date symbolics are applied (time symbolics are preserved),
 * - calendar/FPP semantic differences are reconciled.
 *
 * Resolution MUST NEVER see raw events.
 *
 * IntentNormalizer output MUST be stable and comparable.
 */
final class IntentNormalizer
{
    /**
     * Canonical weekday order used by FPP semantics.
     * Identity/state hashing must use this order to avoid source-order drift.
     *
     * @var array<int,string>
     */
    private const WEEKDAY_ORDER = ['SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA'];

    public function __construct()
    {
        // Intentionally empty.
        // Dependencies will be explicit when added.
    }


    /**
     * Normalize a canonical manifest-event array into an Intent.
     *
     * @param array<string,mixed> $event
     */
    public function fromManifestEvent(
        array $event,
        NormalizationContext $context
    ): Intent {
        $timingArr = $this->applyHolidaySymbolics(
            $event['timing'],
            $context->holidayResolver
        );

        // Days normalization source-of-truth:
        // - Calendar-side events may not populate timing.days directly.
        // - If Resolution produced weeklyDays metadata, use it to populate timing.days.
        // - This keeps identity/state hashing stable across calendar and FPP.
        if (
            (!isset($timingArr['days']) || $timingArr['days'] === null)
            && isset($event['weeklyDays'])
            && is_array($event['weeklyDays'])
            && $event['weeklyDays'] !== []
        ) {
            $timingArr['days'] = [
                'type'  => 'weekly',
                'value' => array_values($event['weeklyDays']),
            ];
        }
        // Debug raw days before any normalization
        if (getenv('GCS_DEBUG_INTENTS') === '1') {
            $rawDays = $timingArr['days'] ?? null;
            fwrite(STDERR, "RAW DAYS [" . ($event['source'] ?? 'unknown') . "]: " . json_encode($rawDays) . "\n");
        }
        // Symbolic times (dusk/dawn/etc.) must remain symbolic end-to-end.
        // Any hard time present alongside a symbolic value is display-only and must be ignored.
        $timingArr = $this->normalizeSymbolicTimes($timingArr);

        // Calendar invariants:
        // - Calendar-side events must always carry concrete hard dates.
        // - Symbolic-only dates are only valid on the FPP side.
        $this->assertCalendarHardDates(
            (string)($event['source'] ?? ''),
            (string)($event['type'] ?? ''),
            (string)($event['target'] ?? ''),
            $timingArr
        );

        // All-day normalization:
        // When all_day = true, time fields are semantically irrelevant
        // and MUST be nulled for identity stability across providers.
        if (!empty($timingArr['all_day'])) {
            $timingArr['start_time'] = null;
            $timingArr['end_time']   = null;
        }

        /**
         * Command timing normalization:
         * - Commands are point-in-time unless repeating
         */
        if (
            ($event['type'] ?? null) === 'command'
            && (($event['payload']['repeat'] ?? 'none') === 'none')
        ) {
            $timingArr['end_time'] = $timingArr['start_time'] ?? null;
        }

        $identity = [
            'type'   => $event['type'],
            'target' => $event['target'],
            'timing' => $timingArr,
        ];

        $firstSubEvent = (
            isset($event['subEvents'][0]) && is_array($event['subEvents'][0])
        ) ? $event['subEvents'][0] : [];

        // State hash should prefer subevent-level execution state when available.
        // Calendar pipeline enriches subevent payload with behavior-derived defaults.
        $statePayload = is_array($firstSubEvent['payload'] ?? null)
            ? $firstSubEvent['payload']
            : (is_array($event['payload'] ?? null) ? $event['payload'] : []);
        $stateBehavior = is_array($firstSubEvent['behavior'] ?? null)
            ? $firstSubEvent['behavior']
            : (is_array($event['behavior'] ?? null) ? $event['behavior'] : []);

        $subEvent = [
            'type'    => $event['type'],
            'target'  => $event['target'],
            'timing'  => $timingArr,
            'payload' => $statePayload,
            'behavior' => $stateBehavior,
        ];

        // Debug raw timing state before canonicalization
        $this->debugPreHash(
            (string)($event['source'] ?? 'unknown'),
            [
                'stage'  => 'pre-canonical',
                'type'   => $identity['type'],
                'target' => $identity['target'],
                'timing' => $identity['timing'],
            ]
        );

        // Identity hash
        $identityHashInput = $this->canonicalizeForIdentityHash($identity);

        // Debug canonicalized timing state
        $this->debugPreHash(
            (string)($event['source'] ?? 'unknown'),
            [
                'stage'  => 'post-canonical',
                'type'   => $identityHashInput['type'],
                'target' => $identityHashInput['target'],
                'timing' => $identityHashInput['timing'],
            ]
        );

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
            $identityHashInput,
            $event['ownership'],
            $event['correlation'],
            [$subEvent],
            $eventStateHash
        );
    }

    // ===============================
    // Shared normalization helpers
    // ===============================


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
                $symbolic = trim($symbolic);
                if ($symbolic === '') {
                    $symbolic = null;
                }
            }

            return [
                'hard'     => $time['hard'] ?? null,
                'symbolic' => $symbolic,
                'offset'   => (int)($time['offset'] ?? 0),
            ];
        };

        $pickDate = function (?array $date): array {
            if ($date === null) {
                return ['symbolic' => null, 'hard' => null];
            }

            $symbolic = $date['symbolic'] ?? null;
            if (is_string($symbolic)) {
                $symbolic = trim($symbolic);
                if ($symbolic === '') {
                    $symbolic = null;
                }
            } else {
                $symbolic = null;
            }

            $hard = $date['hard'] ?? null;
            if (!is_string($hard) || trim($hard) === '') {
                $hard = null;
            }

            /*
             * Identity rule:
             * - If a symbolic holiday exists, it MUST be used for identity.
             * - Hard date becomes fallback only when no symbolic exists.
             * - When symbolic exists, hard date MUST be nulled to prevent
             *   identity drift between calendar and FPP representations.
             */
            if ($symbolic !== null) {
                return [
                    'symbolic' => $symbolic,
                    'hard'     => null,
                ];
            }

            return [
                'symbolic' => null,
                'hard'     => $hard,
            ];
        };

        $canonDays = function ($v): ?array {
            if ($v === null || !is_array($v)) {
                return null;
            }
            if (($v['type'] ?? null) !== 'weekly' || !is_array($v['value'] ?? null)) {
                return null;
            }
            return [
                'type'  => 'weekly',
                'value' => $this->canonicalizeWeeklyDays($v['value']),
            ];
        };

        return [
            'type'   => $identity['type'],
            'target' => $identity['target'],
            'timing' => [
                'all_day'    => (bool)$timing['all_day'],
                'start_date' => $pickDate($timing['start_date'] ?? null),
                'end_date'   => $pickDate($timing['end_date'] ?? null),
                'start_time' => $pickTime($timing['start_time'] ?? null),
                'days'       => $canonDays($timing['days'] ?? null),
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
        $behavior = is_array($subEvent['behavior'] ?? null) ? $subEvent['behavior'] : [];

        $lowerOrNull = function ($v): ?string {
            if (!is_string($v)) {
                return null;
            }
            $v = trim($v);
            return $v === '' ? null : strtolower($v);
        };
        $toBool = static function ($v): bool {
            return !($v === false || $v === 0 || $v === '0');
        };

        $canonDate = function ($v): array {
            if (!is_array($v)) {
                return ['hard' => null, 'symbolic' => null];
            }

            $hard = isset($v['hard']) && is_string($v['hard']) && trim($v['hard']) !== ''
                ? $v['hard']
                : null;

            $symbolic = isset($v['symbolic']) && is_string($v['symbolic']) && trim($v['symbolic']) !== ''
                ? $v['symbolic']
                : null;

            // Date semantics: symbolic dates are authoritative and hard date is display-only.
            // Keep parity with identity canonicalization to avoid false state drift.
            if ($symbolic !== null) {
                $hard = null;
            }

            return [
                'hard'     => $hard,
                'symbolic' => $symbolic,
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

            $symbolic = $v['symbolic'] ?? null;
            if (is_string($symbolic)) {
                $symbolic = trim($symbolic);
                if ($symbolic === '') {
                    $symbolic = null;
                }
            }

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
                'value' => $this->canonicalizeWeeklyDays($v['value']),
            ];
        };

        // Execution behavior fields must be stable across sources.
        // Prefer explicit behavior block, then payload fallback.
        $enabled = \CalendarScheduler\Platform\FPPSemantics::normalizeEnabled(
            $behavior['enabled'] ?? ($payload['enabled'] ?? true)
        );
        $repeat  = isset($behavior['repeat']) && is_string($behavior['repeat'])
            ? strtolower(trim($behavior['repeat']))
            : (
                isset($payload['repeat']) && is_string($payload['repeat'])
                    ? strtolower(trim($payload['repeat']))
                    : (\CalendarScheduler\Platform\FPPSemantics::repeatToSemantic(
                        \CalendarScheduler\Platform\FPPSemantics::defaultRepeatForType((string)($subEvent['type'] ?? 'playlist'))
                    ))
            );

        $stopType = isset($behavior['stopType']) && is_string($behavior['stopType'])
            ? strtolower(trim($behavior['stopType']))
            : (
                isset($payload['stopType']) && is_string($payload['stopType'])
                    ? strtolower(trim($payload['stopType']))
                    : 'graceful'
            );

        // Preserve command metadata (if present) as part of state with stable defaults.
        $command = null;
        $isCommand = ((string)($subEvent['type'] ?? '')) === 'command';
        if ($isCommand || isset($payload['command'])) {
            $rawCommand = is_array($payload['command'] ?? null) ? $payload['command'] : [];
            $command = [];

            // Normalize common command fields that may be omitted on one side.
            $command['name'] = isset($rawCommand['name']) && trim((string)$rawCommand['name']) !== ''
                ? (string)$rawCommand['name']
                : (string)($subEvent['target'] ?? '');
            if (is_array($rawCommand['args'] ?? null)) {
                $command['args'] = array_values($rawCommand['args']);
            } elseif (array_key_exists('args', $rawCommand)) {
                $command['args'] = [$rawCommand['args']];
            } else {
                $command['args'] = [];
            }
            $command['multisyncCommand'] = $toBool($rawCommand['multisyncCommand'] ?? false);
            $command['multisyncHosts'] = isset($rawCommand['multisyncHosts'])
                ? (string)$rawCommand['multisyncHosts']
                : '';

            // Preserve any additional command options deterministically.
            $extra = [];
            foreach ($rawCommand as $key => $value) {
                if (!is_string($key)) {
                    continue;
                }
                if (isset($command[$key])) {
                    continue;
                }
                $extra[$key] = $value;
            }
            ksort($extra);
            foreach ($extra as $key => $value) {
                $command[$key] = $value;
            }
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
     * Normalize symbolic time fields.
     *
     * Rules:
     * - If a time has a non-empty `symbolic` value, its `hard` value is display-only and MUST be set to null.
     * - Both start_time and end_time may be hard or symbolic independently.
     * - Offsets are always normalized to int.
     * - Empty symbolic strings are treated as null.
     */
    private function normalizeSymbolicTimes(array $timing): array
    {
        foreach (['start_time', 'end_time'] as $k) {
            if (!isset($timing[$k]) || $timing[$k] === null) {
                continue;
            }
            if (!is_array($timing[$k])) {
                // Unexpected shape; leave as-is and let later canonicalizers handle defaults.
                continue;
            }

            $symbolic = $timing[$k]['symbolic'] ?? null;
            if (is_string($symbolic)) {
                $symbolic = trim($symbolic);
                if ($symbolic === '') {
                    $symbolic = null;
                } else {
                    $symbolic = \CalendarScheduler\Platform\FPPSemantics::normalizeSymbolicTimeToken($symbolic);
                }
            } else {
                $symbolic = null;
            }

            // Normalize offset early so downstream hashing sees stable ints.
            $timing[$k]['offset'] = (int)($timing[$k]['offset'] ?? 0);

            if ($symbolic !== null) {
                $timing[$k]['symbolic'] = $symbolic;
                // Hard time is display-only when symbolic is present.
                $timing[$k]['hard'] = null;
            } else {
                // No symbolic time; keep hard time if it is a non-empty string, else null.
                $hard = $timing[$k]['hard'] ?? null;
                if (!is_string($hard) || trim($hard) === '') {
                    $hard = null;
                }
                $timing[$k]['hard'] = $hard;
                $timing[$k]['symbolic'] = null;
            }
        }

        return $timing;
    }

    /**
     * Normalize weekly day lists to canonical FPP tokens and ordering.
     *
     * @param array<int,mixed> $days
     * @return array<int,string>
     */
    private function canonicalizeWeeklyDays(array $days): array
    {
        $normalized = [];

        foreach ($days as $day) {
            if (!is_string($day)) {
                continue;
            }

            $token = strtoupper(trim($day));
            if ($token === '') {
                continue;
            }

            // Accept a few common textual forms from calendar tooling.
            $token = match ($token) {
                'SUN' => 'SU',
                'MON' => 'MO',
                'TUE', 'TUES' => 'TU',
                'WED' => 'WE',
                'THU', 'THUR', 'THURS' => 'TH',
                'FRI' => 'FR',
                'SAT' => 'SA',
                default => $token,
            };

            if (in_array($token, self::WEEKDAY_ORDER, true) && !isset($normalized[$token])) {
                $normalized[$token] = true;
            }
        }

        $ordered = [];
        foreach (self::WEEKDAY_ORDER as $day) {
            if (isset($normalized[$day])) {
                $ordered[] = $day;
            }
        }

        return $ordered;
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

    /**
     * Calendar source invariant:
     * - start_date.hard and end_date.hard must always be present.
     * - symbolic-only dates are invalid for calendar events.
     *
     * @param array<string,mixed> $timing
     */
    private function assertCalendarHardDates(
        string $source,
        string $type,
        string $target,
        array $timing
    ): void {
        if ($source !== 'calendar') {
            return;
        }

        foreach (['start_date', 'end_date'] as $key) {
            $date = $timing[$key] ?? null;
            $hard = is_array($date) ? ($date['hard'] ?? null) : null;

            if (!is_string($hard) || trim($hard) === '') {
                throw new \RuntimeException(
                    "Calendar event missing required {$key}.hard (type={$type}, target={$target})"
                );
            }
        }
    }
    /**
     * Debug canonical identity inputs immediately before identity hashing.
     * Emits symbolic vs hard resolution state for verification.
     */
    private function debugPreHash(string $source, array $data): void
    {
        if (getenv('GCS_DEBUG_INTENTS') !== '1') {
            return;
        }

        $timing = $data['timing'] ?? [];
        $stage  = $data['stage'] ?? 'unknown';


        $fmtDate = function ($v): string {
            if (!is_array($v)) {
                return 'hard= symbolic=';
            }
            return sprintf(
                'hard=%s symbolic=%s',
                $v['hard'] ?? '',
                $v['symbolic'] ?? ''
            );
        };

        $fmtTime = function ($v): string {
            if (!is_array($v)) {
                return 'null';
            }
            return sprintf(
                'hard=%s symbolic=%s offset=%d',
                $v['hard'] ?? '',
                $v['symbolic'] ?? '',
                (int)($v['offset'] ?? 0)
            );
        };

        $fmtDays = function ($v): string {
            if (!is_array($v)) {
                return 'null';
            }
            return json_encode($v);
        };

        fwrite(STDERR, sprintf(
            "PRE-HASH [%s] (%s)\n".
            "  type=%s target=%s\n".
            "  all_day:    %s\n".
            "  start_date: %s\n".
            "  end_date:   %s\n".
            "  start_time: %s\n".
            "  end_time:   %s\n".
            "  days:       %s\n\n",
            $source,
            $stage,
            $data['type'] ?? '',
            $data['target'] ?? '',
            isset($timing['all_day']) ? ($timing['all_day'] ? 'true' : 'false') : 'false',
            $fmtDate($timing['start_date'] ?? null),
            $fmtDate($timing['end_date'] ?? null),
            $fmtTime($timing['start_time'] ?? null),
            $fmtTime($timing['end_time'] ?? null),
            $fmtDays($timing['days'] ?? null),
        ));
    }
}
