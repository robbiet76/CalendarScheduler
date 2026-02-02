<?php
declare(strict_types=1);

namespace CalendarScheduler\Adapter;

use CalendarScheduler\Intent\RawEvent;
use CalendarScheduler\Platform\FPPSemantics;
use CalendarScheduler\Intent\NormalizationContext;

final class FppScheduleRawEventAdapter
{
    /**
     * @param array<int,array<string,mixed>> $scheduleEntries
     * @return RawEvent[]
     */
    public function fromSchedule(
        array $scheduleEntries,
        NormalizationContext $context,
        int $scheduleUpdatedAt
    ): array {
        $out = [];

        foreach ($scheduleEntries as $entry) {
            // --- Type ---
            if (!empty($entry['command'])) {
                $type = 'command';
                $target = (string)$entry['command'];
            } else {
                $type = ($entry['sequence'] ?? 0) ? 'sequence' : 'playlist';
                $target = preg_replace('/\.fseq$/i', '', (string)$entry['playlist']);
            }
            $type = FPPSemantics::normalizeType($type);

            // --- All-day detection ---
            $isAllDay =
                ($entry['startTime'] ?? null) === '00:00:00'
                && ($entry['endTime'] ?? null) === '24:00:00'
                && (int)($entry['startTimeOffset'] ?? 0) === 0
                && (int)($entry['endTimeOffset'] ?? 0) === 0;

            // --- Guard date stripping ---
            $endDate = $entry['endDate'] ?? null;
            if (
                is_string($endDate)
                && FPPSemantics::isSchedulerGuardDate(
                    $endDate,
                    new \DateTimeImmutable('now', $context->timezone)
                )
            ) {
                $endDate = null;
            }

            // --- Timing (canonical) ---
            $timing = [
                'all_day' => $isAllDay,
                'start_date' => ['hard' => $entry['startDate'] ?? null, 'symbolic' => null],
                'end_date'   => ['hard' => $endDate, 'symbolic' => null],
                'start_time' => $isAllDay ? null : [
                    'hard'     => $entry['startTime'] ?? null,
                    'symbolic' => null,
                    'offset'   => (int)($entry['startTimeOffset'] ?? 0),
                ],
                'end_time'   => $isAllDay ? null : [
                    'hard'     => $entry['endTime'] ?? null,
                    'symbolic' => null,
                    'offset'   => (int)($entry['endTimeOffset'] ?? 0),
                ],
                'days' => FPPSemantics::normalizeDays($entry['day'] ?? null),
            ];

            // --- Payload ---
            $repeatNumeric = FPPSemantics::normalizeRepeat($entry['repeat'] ?? null);
            // --- stopType normalization (inline, previously FPPSemantics::normalizeStopType) ---
            $stopTypeValue = $entry['stopType'] ?? null;
            if (is_int($stopTypeValue)) {
                if ($stopTypeValue === FPPSemantics::STOP_TYPE_HARD) {
                    $stopType = 'hard';
                } elseif ($stopTypeValue === FPPSemantics::STOP_TYPE_GRACEFUL_LOOP) {
                    $stopType = 'graceful_loop';
                } else {
                    $stopType = 'graceful';
                }
            } elseif (is_string($stopTypeValue)) {
                $stopType = strtolower(trim($stopTypeValue));
            } else {
                $stopType = 'graceful';
            }

            $payload = [
                'enabled'  => FPPSemantics::normalizeEnabled($entry['enabled'] ?? true),
                'repeat'   => FPPSemantics::repeatToSemantic($repeatNumeric),
                'stopType' => $stopType,
            ];

            if ($type === 'command') {
                // Inline FPPSemantics::extractCommandPayload
                $command = [];
                foreach ($entry as $k => $v) {
                    if (
                        $k === 'enabled' || $k === 'sequence' || $k === 'day' ||
                        $k === 'startTime' || $k === 'startTimeOffset' ||
                        $k === 'endTime' || $k === 'endTimeOffset' ||
                        $k === 'repeat' || $k === 'startDate' || $k === 'endDate' ||
                        $k === 'stopType' || $k === 'playlist' || $k === 'command'
                    ) {
                        continue;
                    }
                    $command[$k] = $v;
                }
                $command['name'] = (string)$entry['command'];
                $payload['command'] = $command;
            }

            $out[] = new RawEvent(
                source: 'fpp',
                type: $type,
                target: $target,
                timing: $timing,
                payload: $payload,
                ownership: [
                    'managed'    => true,
                    'controller' => 'fpp',
                    'locked'     => false,
                ],
                correlation: [
                    'source' => 'fpp',
                    'raw'    => $entry,
                ],
                sourceUpdatedAt: $scheduleUpdatedAt
            );
        }

        return $out;
    }
}