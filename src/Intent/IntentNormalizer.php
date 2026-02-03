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

        // Identity hash
        $identityHashInput = $this->canonicalizeForIdentityHash($identity);

        $this->debugPreHash(
            (string)($event['source'] ?? 'unknown'),
            $identityHashInput
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

            if (is_string($symbolic)) {
                return [
                    'symbolic' => strtolower($symbolic),
                    'hard'     => null,
                ];
            }

            return [
                'symbolic' => null,
                'hard'     => $date['hard'] ?? null,
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
    /**
     * Debug canonical identity inputs immediately before identity hashing.
     * Emits symbolic vs hard resolution state for verification.
     */
    private function debugPreHash(string $source, array $identityInput): void
    {
        if (getenv('GCS_DEBUG_INTENTS') !== '1') {
            return;
        }

        $timing = $identityInput['timing'] ?? [];

        $fmtDate = function ($v): string {
            if (!is_array($v)) {
                return '';
            }
            return sprintf(
                'hard=%s symbolic=%s',
                $v['hard'] ?? '',
                $v['symbolic'] ?? ''
            );
        };

        fwrite(STDERR, sprintf(
            "PRE-HASH [%s]\n".
            "  type=%s target=%s\n".
            "  start_date: %s\n".
            "  end_date:   %s\n\n",
            $source,
            $identityInput['type'] ?? '',
            $identityInput['target'] ?? '',
            $fmtDate($timing['start_date'] ?? null),
            $fmtDate($timing['end_date'] ?? null),
        ));
    }
}
