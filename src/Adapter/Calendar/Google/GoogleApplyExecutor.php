<?php
declare(strict_types=1);

/**
 * Calendar Scheduler — Source Component
 *
 * File: Adapter/Calendar/Google/GoogleApplyExecutor.php
 * Purpose: Execute mapped Google mutation operations against the Google API
 * client and return mutation results for apply summaries and diagnostics.
 */

namespace CalendarScheduler\Adapter\Calendar\Google;

use CalendarScheduler\Adapter\Calendar\Google\GoogleMutation;
use CalendarScheduler\Adapter\Calendar\Google\GoogleMutationResult;
use CalendarScheduler\Diff\ReconciliationAction;

final class GoogleApplyExecutor
{
    // Provider API boundary and action-to-mutation mapper.
    private GoogleApiClient $client;
    private GoogleEventMapper $mapper;

    public function __construct(GoogleApiClient $client, GoogleEventMapper $mapper)
    {
        $this->client = $client;
        $this->mapper = $mapper;
    }

    /**
     * Convenience entrypoint: map ReconciliationAction[] to GoogleMutation[] and apply.
     *
     * This keeps the "apply() consumes GoogleMutation[] only" contract intact,
     * while allowing ApplyRunner to pass actions at the orchestration boundary.
     *
     * @param ReconciliationAction[] $actions
     * @return GoogleMutationResult[]
     */
    public function applyActions(array $actions): array
    {
        // Convert reconciliation actions to concrete provider mutations.
        $mutations = [];
        $bundleCandidates = [];

        foreach ($actions as $action) {
            $startIndex = count($mutations);
            $mapped = $this->mapper->mapAction($action, $this->client->getConfig());
            foreach ($mapped as $mutation) {
                $mutations[] = $mutation;
            }

            // FPP -> Calendar override bundles may arrive as separate identity actions.
            // Capture single-subevent create actions so we can inject EXDATE on the base.
            $candidate = $this->extractBundleCandidate($action, $mapped, $startIndex);
            if ($candidate !== null) {
                $bundleCandidates[] = $candidate;
            }
        }

        // Preserve per-action manifest linkage while repairing calendar-side override rendering.
        $mutations = $this->injectBundleExDates($mutations, $bundleCandidates);

        // Emit mapper/client diagnostics around batch execution.
        $this->mapper->emitDiagnosticsSummary();
        $results = $this->apply($mutations);
        $this->client->emitDiagnosticsSummary();
        return $results;
    }

    /**
     * Apply a batch of Google mutations to Google Calendar.
     *
     * @param GoogleMutation[] $mutations
     * @return GoogleMutationResult[]
     */
    public function apply(array $mutations): array
    {
        // Execute mutations sequentially to preserve deterministic ordering.
        $results = [];
        foreach ($mutations as $mutation) {
            $results[] = $this->applyOne($mutation);
        }
        return $results;
    }

    private function applyOne(GoogleMutation $mutation): GoogleMutationResult
    {
        // Route each mutation opcode to the matching Google API operation.
        switch ($mutation->op) {
            case GoogleMutation::OP_CREATE:
                $eventId = $this->client->createEvent(
                    $mutation->calendarId,
                    $mutation->payload
                );
                return new GoogleMutationResult(
                    $mutation->op,
                    $mutation->calendarId,
                    $eventId,
                    $mutation->manifestEventId,
                    $mutation->subEventHash
                );

            case GoogleMutation::OP_UPDATE:
                $this->client->updateEvent(
                    $mutation->calendarId,
                    $mutation->googleEventId,
                    $mutation->payload
                );
                return new GoogleMutationResult(
                    $mutation->op,
                    $mutation->calendarId,
                    $mutation->googleEventId,
                    $mutation->manifestEventId,
                    $mutation->subEventHash
                );

            case GoogleMutation::OP_DELETE:
                $this->client->deleteEvent(
                    $mutation->calendarId,
                    $mutation->googleEventId
                );
                return new GoogleMutationResult(
                    $mutation->op,
                    $mutation->calendarId,
                    $mutation->googleEventId,
                    $mutation->manifestEventId,
                    $mutation->subEventHash
                );

            default:
                throw new \RuntimeException(
                    'GoogleApplyExecutor: unsupported mutation op ' . $mutation->op
                );
        }
    }

    /**
     * Capture create actions that can participate in base+override EXDATE stitching.
     *
     * @param array<int,GoogleMutation> $mapped
     * @return array<string,mixed>|null
     */
    private function extractBundleCandidate(ReconciliationAction $action, array $mapped, int $startIndex): ?array
    {
        if ($action->type !== ReconciliationAction::TYPE_CREATE) {
            return null;
        }
        if ($action->target !== ReconciliationAction::TARGET_CALENDAR) {
            return null;
        }

        $event = is_array($action->event ?? null) ? $action->event : [];
        if (strtolower((string)($event['source'] ?? '')) !== 'fpp') {
            return null;
        }

        $subEvents = is_array($event['subEvents'] ?? null) ? $event['subEvents'] : [];
        if (count($subEvents) !== 1 || !is_array($subEvents[0])) {
            return null;
        }

        if (count($mapped) !== 1 || !($mapped[0] instanceof GoogleMutation)) {
            return null;
        }
        if ($mapped[0]->op !== GoogleMutation::OP_CREATE) {
            return null;
        }

        $timing = is_array($subEvents[0]['timing'] ?? null) ? $subEvents[0]['timing'] : [];
        $start = $this->timingDateYmd($timing, 'start_date');
        $end = $this->timingDateYmd($timing, 'end_date');
        if ($start === null || $end === null) {
            return null;
        }
        if ($start > $end) {
            return null;
        }

        $allDay = (bool)($timing['all_day'] ?? false);
        $startTime = $allDay ? '00:00:00' : ($this->timingTimeHms($timing, 'start_time') ?? '');
        if (!$allDay && $startTime === '') {
            return null;
        }

        $timezone = is_string($timing['timezone'] ?? null) && trim((string)$timing['timezone']) !== ''
            ? trim((string)$timing['timezone'])
            : 'UTC';
        $daysKey = json_encode($timing['days'] ?? null);
        if (!is_string($daysKey)) {
            $daysKey = 'null';
        }

        return [
            'groupKey' => implode('|', [
                $mapped[0]->calendarId,
                $allDay ? '1' : '0',
                $timezone,
                $daysKey,
            ]),
            'mutationIndex' => $startIndex,
            'start' => $start,
            'end' => $end,
            'allDay' => $allDay,
            'timezone' => $timezone,
            'startTime' => $startTime,
        ];
    }

    /**
     * For candidate bundles, inject EXDATE into the widest base recurrence.
     *
     * @param array<int,GoogleMutation> $mutations
     * @param array<int,array<string,mixed>> $candidates
     * @return array<int,GoogleMutation>
     */
    private function injectBundleExDates(array $mutations, array $candidates): array
    {
        if (count($candidates) < 2) {
            return $mutations;
        }

        $groups = [];
        foreach ($candidates as $candidate) {
            $key = (string)($candidate['groupKey'] ?? '');
            if ($key === '') {
                continue;
            }
            if (!isset($groups[$key])) {
                $groups[$key] = [];
            }
            $groups[$key][] = $candidate;
        }

        foreach ($groups as $group) {
            if (!is_array($group) || count($group) < 2) {
                continue;
            }

            $base = $this->pickBundleBase($group);
            if ($base === null) {
                continue;
            }

            $baseStart = (string)($base['start'] ?? '');
            $baseEnd = (string)($base['end'] ?? '');
            if ($baseStart === '' || $baseEnd === '') {
                continue;
            }

            $dates = [];
            $hasOverride = false;
            $validGroup = true;
            foreach ($group as $candidate) {
                if (($candidate['mutationIndex'] ?? null) === ($base['mutationIndex'] ?? null)) {
                    continue;
                }

                $start = (string)($candidate['start'] ?? '');
                $end = (string)($candidate['end'] ?? '');
                if ($start === '' || $end === '') {
                    continue;
                }

                if (!$this->rangesOverlap($baseStart, $baseEnd, $start, $end)) {
                    continue;
                }

                if (!$this->rangeContainedBy($baseStart, $baseEnd, $start, $end)) {
                    $validGroup = false;
                    break;
                }

                $hasOverride = true;
                foreach ($this->expandDateRange($start, $end) as $day) {
                    if ($day < $baseStart || $day > $baseEnd) {
                        continue;
                    }
                    $dates[$day] = true;
                }
            }

            if (!$validGroup || !$hasOverride || $dates === []) {
                continue;
            }

            ksort($dates, SORT_STRING);
            $exDate = $this->buildExDateLine(
                array_keys($dates),
                (bool)($base['allDay'] ?? false),
                (string)($base['timezone'] ?? 'UTC'),
                (string)($base['startTime'] ?? '')
            );
            if ($exDate === null) {
                continue;
            }

            $idx = (int)($base['mutationIndex'] ?? -1);
            if (!isset($mutations[$idx])) {
                continue;
            }
            $baseMutation = $mutations[$idx];
            $payload = $baseMutation->payload;
            $recurrence = is_array($payload['recurrence'] ?? null) ? $payload['recurrence'] : [];
            $recurrence[] = $exDate;
            $payload['recurrence'] = array_values(array_unique($recurrence));

            $mutations[$idx] = new GoogleMutation(
                op: $baseMutation->op,
                calendarId: $baseMutation->calendarId,
                googleEventId: $baseMutation->googleEventId,
                payload: $payload,
                manifestEventId: $baseMutation->manifestEventId,
                subEventHash: $baseMutation->subEventHash
            );
        }

        return $mutations;
    }

    /**
     * @param array<int,array<string,mixed>> $group
     * @return array<string,mixed>|null
     */
    private function pickBundleBase(array $group): ?array
    {
        $best = null;
        $bestSpan = -1;
        $bestStart = '';
        foreach ($group as $candidate) {
            $start = (string)($candidate['start'] ?? '');
            $end = (string)($candidate['end'] ?? '');
            if ($start === '' || $end === '') {
                continue;
            }
            $span = $this->inclusiveDaySpan($start, $end);
            if ($span < 0) {
                continue;
            }
            if ($best === null || $span > $bestSpan || ($span === $bestSpan && strcmp($start, $bestStart) < 0)) {
                $best = $candidate;
                $bestSpan = $span;
                $bestStart = $start;
            }
        }

        return $best;
    }

    private function inclusiveDaySpan(string $startYmd, string $endYmd): int
    {
        try {
            $start = new \DateTimeImmutable($startYmd, new \DateTimeZone('UTC'));
            $end = new \DateTimeImmutable($endYmd, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return -1;
        }

        $delta = $end->getTimestamp() - $start->getTimestamp();
        if ($delta < 0) {
            return -1;
        }

        return (int) floor($delta / 86400) + 1;
    }

    private function rangesOverlap(string $aStart, string $aEnd, string $bStart, string $bEnd): bool
    {
        return $aStart <= $bEnd && $bStart <= $aEnd;
    }

    private function rangeContainedBy(string $outerStart, string $outerEnd, string $innerStart, string $innerEnd): bool
    {
        return $innerStart >= $outerStart && $innerEnd <= $outerEnd;
    }

    /**
     * @return array<int,string>
     */
    private function expandDateRange(string $startYmd, string $endYmd): array
    {
        $out = [];
        try {
            $cursor = new \DateTimeImmutable($startYmd, new \DateTimeZone('UTC'));
            $end = new \DateTimeImmutable($endYmd, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return $out;
        }

        while ($cursor <= $end) {
            $out[] = $cursor->format('Y-m-d');
            $cursor = $cursor->modify('+1 day');
        }

        return $out;
    }

    /**
     * @param array<int,string> $days
     */
    private function buildExDateLine(array $days, bool $allDay, string $timezone, string $baseStartTime): ?string
    {
        if ($days === []) {
            return null;
        }

        if ($allDay) {
            $tokens = array_map(
                static fn(string $day): string => str_replace('-', '', $day),
                $days
            );
            return 'EXDATE;VALUE=DATE:' . implode(',', $tokens);
        }

        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $baseStartTime) !== 1) {
            return null;
        }

        $tokens = [];
        foreach ($days as $day) {
            $tokens[] = str_replace('-', '', $day) . 'T' . str_replace(':', '', $baseStartTime);
        }

        $tz = trim($timezone) !== '' ? trim($timezone) : 'UTC';
        return 'EXDATE;TZID=' . $tz . ':' . implode(',', $tokens);
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
