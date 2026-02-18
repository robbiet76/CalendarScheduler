<?php

declare(strict_types=1);

/**
 * Calendar Scheduler — Source Component
 *
 * File: Apply/ApplyRunner.php
 * Purpose: Defines the ApplyRunner component used by the Calendar Scheduler Apply layer.
 */

namespace CalendarScheduler\Apply;

use CalendarScheduler\Diff\ReconciliationAction;
use CalendarScheduler\Diff\ReconciliationResult;
use CalendarScheduler\Apply\ApplyOptions;
use CalendarScheduler\Adapter\FppScheduleAdapter;
use CalendarScheduler\Apply\FppScheduleWriter;

/**
 * ApplyRunner
 *
 * The ONLY layer allowed to mutate external state.
 * Operates exclusively on executable actions from ReconciliationResult.
 */
final class ApplyRunner
{
    public function __construct(
        private readonly ManifestWriter $manifestWriter,
        private readonly ?FppScheduleAdapter $fppAdapter = null,
        private readonly ?FppScheduleWriter $fppWriter = null,
        private readonly ?\CalendarScheduler\Adapter\Calendar\Google\GoogleApplyExecutor $googleExecutor = null
    ) {}

    public function apply(
        ReconciliationResult $result,
        ApplyOptions $options
    ): void
    {
        $targetManifest = $result->targetManifest();
        $executable = $result->executableActions();
        $blocked = $result->blockedActions();

        $actionsByTarget = [
            ReconciliationAction::TARGET_FPP => [],
            ReconciliationAction::TARGET_CALENDAR => [],
        ];
        $disallowed = [];

        foreach ($executable as $action) {
            if (!isset($actionsByTarget[$action->target])) {
                continue;
            }
            if (!$options->canWrite($action->target)) {
                $disallowed[] = $action;
                continue;
            }
            $actionsByTarget[$action->target][] = $action;
        }

        if (($blocked !== [] || $disallowed !== []) && $options->failOnBlockedActions) {
            $messages = [];
            foreach ($blocked as $action) {
                $messages[] = "Blocked: {$action->identityHash} ({$action->reason})";
            }
            foreach ($disallowed as $action) {
                $messages[] = "Blocked by target policy: {$action->identityHash} ({$action->target} not writable)";
            }
            throw new \RuntimeException('Apply blocked: ' . implode('; ', $messages));
        }

        $fppActions = $actionsByTarget[ReconciliationAction::TARGET_FPP];
        $calendarActions = $actionsByTarget[ReconciliationAction::TARGET_CALENDAR];
        $fppApplied = false;
        $calendarApplied = false;

        try {
            if ($fppActions !== []) {
                if ($this->fppAdapter === null || $this->fppWriter === null) {
                    throw new \RuntimeException(
                        'FPP actions present but FppScheduleAdapter and/or FppScheduleWriter not configured'
                    );
                }

                // Build full target schedule from manifest (deletes expressed by absence)
                $target = $targetManifest;
                $targetEvents = $target['events'] ?? [];
                if (!is_array($targetEvents)) {
                    $targetEvents = [];
                }

                $singleEvents = [];
                foreach ($targetEvents as $event) {
                    if (!is_array($event)) {
                        continue;
                    }

                    // v2 manifest event: 1 manifest event => N FPP schedule entries (subEvents)
                    // FppScheduleAdapter expects the v2 manifest shape: identity + subEvents.
                    $identity = $event['identity'] ?? null;
                    $subEvents = $event['subEvents'] ?? null;

                    if (!is_array($identity) || !is_array($subEvents)) {
                        // No legacy support in this project; fail fast with a helpful message.
                        $id = is_string($event['identityHash'] ?? null) ? $event['identityHash'] : '(missing identityHash)';
                        throw new \InvalidArgumentException(
                            "ApplyRunner: target manifest event is missing required v2 keys (identity/subEvents). identityHash={$id}"
                        );
                    }

                    foreach ($this->sortSubEventsForFppWrite($subEvents) as $sub) {
                        if (!is_array($sub)) {
                            continue;
                        }

                        // Ensure behavior fields are present in payload for adapters that expect them.
                        // We keep the canonical v2 shape, but duplicate enabled/repeat/stopType into payload.
                        $payload = is_array($sub['payload'] ?? null) ? $sub['payload'] : [];
                        $behavior = is_array($sub['behavior'] ?? null) ? $sub['behavior'] : [];
                        $payload = array_merge($payload, [
                            'enabled'  => $behavior['enabled']  ?? ($payload['enabled']  ?? true),
                            'repeat'   => $behavior['repeat']   ?? ($payload['repeat']   ?? 'none'),
                            'stopType' => $behavior['stopType'] ?? ($payload['stopType'] ?? 'graceful'),
                        ]);

                        $single = [
                            'id'           => $event['id'] ?? ($event['identityHash'] ?? null),
                            'identityHash' => $event['identityHash'] ?? null,
                            'stateHash'    => $event['stateHash'] ?? null,
                            'identity'     => $identity,
                            'ownership'    => is_array($event['ownership'] ?? null) ? $event['ownership'] : [],
                            'correlation'  => is_array($event['correlation'] ?? null) ? $event['correlation'] : [],
                            'provenance'   => $event['provenance'] ?? null,
                            'subEvents'    => [
                                array_merge($sub, [
                                    'payload' => $payload,
                                ]),
                            ],
                            'source'       => 'manifest',
                        ];

                        $singleEvents[] = $single;
                    }
                }

                $scheduleEntries = [];
                foreach ($this->sortSingleEventsForFppWrite($singleEvents) as $single) {
                    $scheduleEntries[] = $this->fppAdapter->toScheduleEntry($single);
                }

                // ALWAYS write staged schedule (even in plan/dry-run)
                $this->fppWriter->writeStaged($scheduleEntries);

                // Only commit to live schedule.json during real apply
                if (!$options->isPlan() && !$options->isDryRun()) {
                    $this->fppWriter->commitStaged();
                    $fppApplied = true;
                }
            }

            if ($calendarActions !== []) {
                if ($this->googleExecutor === null) {
                    throw new \RuntimeException(
                        'Calendar actions present but no GoogleApplyExecutor configured'
                    );
                }

                if (!$options->isPlan() && !$options->isDryRun()) {
                    $googleResults = $this->googleExecutor->applyActions($calendarActions);
                    $targetManifest = $this->applyGoogleMutationResultsToManifest($targetManifest, $googleResults);
                    $calendarApplied = true;
                }
            }

            // Persist canonical manifest ONLY during real apply
            if (!$options->isPlan() && !$options->isDryRun()) {
                $this->manifestWriter->applyTargetManifest($targetManifest);
            }
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    /**
     * Persist Google provider linkage into manifest subEvent payloads so subsequent
     * update/delete operations can resolve concrete googleEventId values.
     *
     * @param array<string,mixed> $manifest
     * @param array<int,\CalendarScheduler\Adapter\Calendar\Google\GoogleMutationResult> $results
     * @return array<string,mixed>
     */
    private function applyGoogleMutationResultsToManifest(array $manifest, array $results): array
    {
        $events = $manifest['events'] ?? [];
        if (!is_array($events) || $results === []) {
            return $manifest;
        }

        foreach ($results as $result) {
            if (!($result instanceof \CalendarScheduler\Adapter\Calendar\Google\GoogleMutationResult)) {
                continue;
            }

            if (
                $result->op !== \CalendarScheduler\Adapter\Calendar\Google\GoogleMutation::OP_CREATE
                && $result->op !== \CalendarScheduler\Adapter\Calendar\Google\GoogleMutation::OP_UPDATE
            ) {
                continue;
            }

            if (!is_string($result->googleEventId) || $result->googleEventId === '') {
                continue;
            }

            $id = $result->manifestEventId;
            if (!is_array($events[$id] ?? null)) {
                continue;
            }

            $event = $events[$id];
            $subEvents = $event['subEvents'] ?? null;
            if (!is_array($subEvents)) {
                continue;
            }

            foreach ($subEvents as $i => $sub) {
                if (!is_array($sub)) {
                    continue;
                }

                $stateHash = $sub['stateHash'] ?? null;
                if (!is_string($stateHash) || $stateHash === '') {
                    continue;
                }

                if ($stateHash !== $result->subEventHash) {
                    continue;
                }

                $payload = is_array($sub['payload'] ?? null) ? $sub['payload'] : [];
                $payload['googleEventId'] = $result->googleEventId;
                $sub['payload'] = $payload;
                $subEvents[$i] = $sub;
                break;
            }

            $correlation = is_array($event['correlation'] ?? null) ? $event['correlation'] : [];
            $googleEventIds = is_array($correlation['googleEventIds'] ?? null) ? $correlation['googleEventIds'] : [];
            $googleEventIds[$result->subEventHash] = $result->googleEventId;
            $correlation['googleEventIds'] = $googleEventIds;
            $event['correlation'] = $correlation;
            $event['subEvents'] = array_values($subEvents);
            $events[$id] = $event;
        }

        $manifest['events'] = $events;
        return $manifest;
    }

    /**
     * FPP evaluates schedule rows top-down and executes the first matching row.
     * Write narrower/more specific subevents first, then wider base coverage last.
     *
     * @param array<int,mixed> $subEvents
     * @return array<int,mixed>
     */
    private function sortSubEventsForFppWrite(array $subEvents): array
    {
        usort($subEvents, function (mixed $a, mixed $b): int {
            $aOrder = $this->subEventExecutionOrder($a);
            $bOrder = $this->subEventExecutionOrder($b);
            if ($aOrder !== null && $bOrder !== null && $aOrder !== $bOrder) {
                return $aOrder <=> $bOrder;
            }

            $aTiming = is_array($a) ? (is_array($a['timing'] ?? null) ? $a['timing'] : []) : [];
            $bTiming = is_array($b) ? (is_array($b['timing'] ?? null) ? $b['timing'] : []) : [];

            $aSpan = $this->timingSpanDays($aTiming);
            $bSpan = $this->timingSpanDays($bTiming);
            if ($aSpan !== $bSpan) {
                return $aSpan <=> $bSpan;
            }

            $aStart = $this->timingStartKey($aTiming);
            $bStart = $this->timingStartKey($bTiming);
            if ($aStart !== $bStart) {
                return strcmp($bStart, $aStart);
            }

            $aHash = is_array($a) && is_string($a['stateHash'] ?? null) ? $a['stateHash'] : '';
            $bHash = is_array($b) && is_string($b['stateHash'] ?? null) ? $b['stateHash'] : '';
            return strcmp($aHash, $bHash);
        });

        return array_values($subEvents);
    }

    /**
     * Sort flattened single-subevent manifest rows by global execution order.
     *
     * @param array<int,array<string,mixed>> $singleEvents
     * @return array<int,array<string,mixed>>
     */
    private function sortSingleEventsForFppWrite(array $singleEvents): array
    {
        usort($singleEvents, function (array $a, array $b): int {
            $aSub = (is_array($a['subEvents'] ?? null) && isset($a['subEvents'][0]) && is_array($a['subEvents'][0]))
                ? $a['subEvents'][0]
                : [];
            $bSub = (is_array($b['subEvents'] ?? null) && isset($b['subEvents'][0]) && is_array($b['subEvents'][0]))
                ? $b['subEvents'][0]
                : [];

            $aOrder = $this->subEventExecutionOrder($aSub);
            $bOrder = $this->subEventExecutionOrder($bSub);
            if ($aOrder !== null && $bOrder !== null && $aOrder !== $bOrder) {
                return $aOrder <=> $bOrder;
            }
            if ($aOrder !== null && $bOrder === null) {
                return -1;
            }
            if ($aOrder === null && $bOrder !== null) {
                return 1;
            }

            $aTiming = is_array($aSub['timing'] ?? null) ? $aSub['timing'] : [];
            $bTiming = is_array($bSub['timing'] ?? null) ? $bSub['timing'] : [];

            $aStart = $this->timingStartKey($aTiming);
            $bStart = $this->timingStartKey($bTiming);
            if ($aStart !== $bStart) {
                return strcmp($aStart, $bStart);
            }

            $aSpan = $this->timingSpanDays($aTiming);
            $bSpan = $this->timingSpanDays($bTiming);
            if ($aSpan !== $bSpan) {
                return $aSpan <=> $bSpan;
            }

            $aId = is_string($a['identityHash'] ?? null) ? $a['identityHash'] : '';
            $bId = is_string($b['identityHash'] ?? null) ? $b['identityHash'] : '';
            return strcmp($aId, $bId);
        });

        return array_values($singleEvents);
    }

    private function subEventExecutionOrder(mixed $subEvent): ?int
    {
        if (!is_array($subEvent)) {
            return null;
        }
        $value = $subEvent['executionOrder'] ?? null;
        if (is_int($value)) {
            return $value >= 0 ? $value : 0;
        }
        if (is_string($value) && is_numeric($value)) {
            $n = (int)$value;
            return $n >= 0 ? $n : 0;
        }
        return null;
    }

    /**
     * @param array<string,mixed> $timing
     */
    private function timingSpanDays(array $timing): int
    {
        $start = $this->timingDateYmd($timing, 'start_date');
        $end = $this->timingDateYmd($timing, 'end_date');
        if ($start === null || $end === null) {
            return PHP_INT_MAX;
        }

        $startTs = strtotime($start . ' 00:00:00 UTC');
        $endTs = strtotime($end . ' 00:00:00 UTC');
        if (!is_int($startTs) || !is_int($endTs) || $endTs < $startTs) {
            return PHP_INT_MAX;
        }

        return (int) floor(($endTs - $startTs) / 86400) + 1;
    }

    /**
     * @param array<string,mixed> $timing
     */
    private function timingStartKey(array $timing): string
    {
        $date = $this->timingDateYmd($timing, 'start_date') ?? '0000-00-00';
        $time = $this->timingTimeHms($timing, 'start_time') ?? '00:00:00';
        return $date . ' ' . $time;
    }

    /**
     * @param array<string,mixed> $timing
     */
    private function timingDateYmd(array $timing, string $key): ?string
    {
        $date = is_array($timing[$key] ?? null) ? $timing[$key] : [];
        $hard = is_string($date['hard'] ?? null) ? trim($date['hard']) : '';
        if ($hard !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $hard) === 1) {
            return $hard;
        }
        return null;
    }

    /**
     * @param array<string,mixed> $timing
     */
    private function timingTimeHms(array $timing, string $key): ?string
    {
        $time = is_array($timing[$key] ?? null) ? $timing[$key] : [];
        $hard = is_string($time['hard'] ?? null) ? trim($time['hard']) : '';
        if ($hard !== '' && preg_match('/^\d{2}:\d{2}:\d{2}$/', $hard) === 1) {
            return $hard;
        }
        return null;
    }
}
