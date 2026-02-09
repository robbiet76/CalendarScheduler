<?php
declare(strict_types=1);

namespace CalendarScheduler\Intent;

use CalendarScheduler\Intent\NormalizationContext;
use CalendarScheduler\Platform\HolidayResolver;
use CalendarScheduler\Planner\Dto\PlannerIntent;

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
        // Symbolic times (dusk/dawn/etc.) must remain symbolic end-to-end.
        // Any hard time present alongside a symbolic value is display-only and must be ignored.
        $timingArr = $this->normalizeSymbolicTimes($timingArr);

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

        $subEvent = [
            'type'    => $event['type'],
            'target'  => $event['target'],
            'timing'  => $timingArr,
            'payload' => $event['payload'],
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
            $identity,
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
     * - Identity represents a single calendar event
     * - Identity is stable across time
     * - Identity excludes date-range activation (start/end dates)
     * - Identity excludes execution state (repeat, stopType, enabled)
     * - Identity includes all-day flag, start_time, end_time, and weekly day selection
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
            }

            $hard = $date['hard'] ?? null;
            if (!is_string($hard) || $hard === '') {
                $hard = null;
            }

            return [
                'symbolic' => $symbolic,
                'hard'     => $hard,
            ];
        };

        return [
            'type'   => $identity['type'],
            'target' => $identity['target'],
            'timing' => [
                'all_day'    => (bool)$timing['all_day'],
                'start_time' => $pickTime($timing['start_time'] ?? null),
                'end_time'   => $pickTime($timing['end_time'] ?? null),
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

            $hard = isset($v['hard']) && is_string($v['hard']) && trim($v['hard']) !== ''
                ? $v['hard']
                : null;

            $symbolic = isset($v['symbolic']) && is_string($v['symbolic']) && trim($v['symbolic']) !== ''
                ? $v['symbolic']
                : null;

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

        fwrite(STDERR, sprintf(
            "PRE-HASH [%s] (%s)\n".
            "  type=%s target=%s\n".
            "  start_date: %s\n".
            "  end_date:   %s\n\n",
            $source,
            $stage,
            $data['type'] ?? '',
            $data['target'] ?? '',
            $fmtDate($timing['start_date'] ?? null),
            $fmtDate($timing['end_date'] ?? null),
        ));
    }
    /**
     * Normalize a set of PlannerIntent objects into canonical Intents.
     *
     * Groups PlannerIntents by computed identityHash, producing one Intent per group.
     *
     * @param PlannerIntent[] $plannerIntents
     * @return Intent[]
     */
    public function normalizePlannerIntents(array $plannerIntents): array
    {
        // Group PlannerIntents by identityHash
        $grouped = [];
        $identities = [];
        foreach ($plannerIntents as $pi) {
            // Compute identity array matching calendar identity semantics:
            // - type (from payload['type'] if present, else infer from payload['playlist']/['command'])
            // - target (playlist / sequence / command name)
            // - timing: all_day, start_time, end_time, days (weekly mask if present)

            // Determine type
            $payload = is_array($pi->payload ?? null) ? $pi->payload : [];
            $type = $payload['type'] ?? null;
            if ($type === null) {
                if (isset($payload['playlist'])) {
                    $type = 'playlist';
                } elseif (isset($payload['command'])) {
                    $type = 'command';
                } elseif (isset($payload['sequence'])) {
                    $type = 'sequence';
                } else {
                    $type = 'unknown';
                }
            }

            // Determine target
            if (isset($payload['playlist'])) {
                $target = $payload['playlist'];
            } elseif (isset($payload['sequence'])) {
                $target = $payload['sequence'];
            } elseif (isset($payload['command']) && is_array($payload['command']) && isset($payload['command']['name'])) {
                $target = $payload['command']['name'];
            } elseif (isset($payload['command']) && is_string($payload['command'])) {
                $target = $payload['command'];
            } else {
                $target = null;
            }

            // Compose timing (REPLACED per instruction)
            $timing = [
                'all_day' => (bool)$pi->allDay,
                'start_time' => [
                    'hard' => $pi->start->format('H:i:s'),
                    'symbolic' => null,
                    'offset' => 0,
                ],
                'end_time' => [
                    'hard' => $pi->end->format('H:i:s'),
                    'symbolic' => null,
                    'offset' => 0,
                ],
                'days' => $payload['days'] ?? null,
            ];

            $identity = [
                'type'   => $type,
                'target' => $target,
                'timing' => $timing,
            ];

            // Canonicalize and hash
            $identityHashInput = $this->canonicalizeForIdentityHash($identity);
            $identityHashJson = json_encode($identityHashInput, JSON_THROW_ON_ERROR);
            $identityHash = hash('sha256', $identityHashJson);

            // Save for later (for Intent identity)
            $identities[$identityHash] = $identity;

            // Group by identityHash
            if (!isset($grouped[$identityHash])) {
                $grouped[$identityHash] = [];
            }
            $grouped[$identityHash][] = $pi;
        }

        // Build Intents
        $intents = [];
        foreach ($grouped as $identityHash => $group) {
            $identity = $identities[$identityHash];
            $subEvents = [];
            foreach ($group as $pi) {
                $payload = is_array($pi->payload ?? null) ? $pi->payload : [];
                // Compose timing (REPLACED per instruction)
                $timing = [
                    'all_day' => (bool)$pi->allDay,
                    'start_time' => [
                        'hard' => $pi->start->format('H:i:s'),
                        'symbolic' => null,
                        'offset' => 0,
                    ],
                    'end_time' => [
                        'hard' => $pi->end->format('H:i:s'),
                        'symbolic' => null,
                        'offset' => 0,
                    ],
                    'days' => $payload['days'] ?? null,
                ];
                $subEvent = [
                    // bundleUid may remain in subEvent metadata only
                    'bundleUid' => $pi->bundleUid,
                    'timing'    => $timing,
                    'role'      => $pi->role,
                    'scope'     => $pi->scope,
                    'payload'   => $payload,
                ];
                // Compute stateHash for subEvent (use canonicalizeForStateHash)
                $shInput = $this->canonicalizeForStateHash([
                    'type'    => $identity['type'],
                    'target'  => $identity['target'],
                    'timing'  => $timing,
                    'payload' => $payload,
                ]);
                $subEvent['stateHash'] = hash('sha256', json_encode($shInput, JSON_THROW_ON_ERROR));
                $subEvents[] = $subEvent;
            }
            $ownership = [];
            $correlation = [];
            $intents[$identityHash] = new Intent(
                $identityHash,
                $identity,
                $ownership,
                $correlation,
                $subEvents
            );
        }
        return $intents;
    }
}