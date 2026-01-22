<?php
declare(strict_types=1);

namespace GoogleCalendarScheduler\Platform;

use GoogleCalendarScheduler\Platform\IcsFetcher;
use GoogleCalendarScheduler\Platform\IcsParser;
use GoogleCalendarScheduler\Platform\SunTimeDisplayEstimator;
use GoogleCalendarScheduler\Platform\HolidayResolver;
use GoogleCalendarScheduler\Platform\FppSemantics;
use DateTimeImmutable;

/**
 * CalendarTranslator
 *
 * Translates manifest events into calendar export intents.
 *
 * This class is calendar-provider agnostic. It does NOT:
 * - assume Google Calendar
 * - write ICS directly
 * - mutate the manifest
 */
final class CalendarTranslator
{
    private HolidayResolver $holidayResolver;

    /**
     * @param HolidayResolver|null $holidayResolver
     */
    public function __construct(?HolidayResolver $holidayResolver = null)
    {
        $this->holidayResolver = $holidayResolver ?? new HolidayResolver([]);
    }

    /**
     * Translate an ICS source (file path or URL) into manifest event structures.
     *
     * @param string $icsSource Path or URL to ICS
     * @return array<int,array<string,mixed>> Manifest-shaped events
     */
    public function translateIcsSourceToManifestEvents(string $icsSource): array
    {
        $fetcher = new IcsFetcher();
        $parser  = new IcsParser();

        $raw = $fetcher->fetch($icsSource);
        if ($raw === '') {
            return [];
        }

        $now        = new \DateTimeImmutable('now');
        $horizonEnd = $now->modify('+2 years');

        $records = $parser->parse($raw, $now, $horizonEnd);

        $events = [];

        foreach ($records as $rec) {
            $events[] = [
                'type'   => 'playlist',
                'target' => $rec['summary'] ?? '',
                'correlation' => [
                    'source'     => 'calendar',
                    'externalId' => $rec['uid'] ?? null,
                ],
                'ownership' => [
                    'managed'    => true,
                    'controller' => 'calendar',
                    'locked'     => false,
                ],
                'provenance' => [
                    'source'      => 'calendar',
                    'provider'    => null,
                    'imported_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                ],
                'subEvents' => [
                    [
                        'timing' => [
                            'start_date' => $this->resolveCalendarDate(substr($rec['start'], 0, 10)),
                            'end_date'   => $this->resolveCalendarDate(substr($rec['end'],   0, 10)),
                            'start_time' => ['hard' => substr($rec['start'], 11, 8), 'symbolic' => null, 'offset' => 0],
                            'end_time'   => ['hard' => substr($rec['end'],   11, 8), 'symbolic' => null, 'offset' => 0],
                            'days'       => null,
                        ],
                        'behavior' => FppSemantics::defaultBehavior('playlist'),
                    ],
                ],
            ];
        }

        return $events;
    }

    /**
     * Translate manifest into calendar export events.
     *
     * @param array<string,mixed> $manifest
     * @param float $latitude   (for display-only sun time estimation)
     * @param float $longitude
     *
     * @return array<int,array<string,mixed>> Calendar export intents
     */
    public function export(
        array $manifest,
        float $latitude,
        float $longitude
    ): array {
        $out = [];

        foreach ($manifest['events'] ?? [] as $event) {
            $eventType   = $event['type'] ?? null;
            $eventTarget = $event['target'] ?? '';

            foreach ($event['subEvents'] ?? [] as $subEvent) {
                $intent = $this->buildCalendarIntent(
                    $eventType,
                    $eventTarget,
                    $subEvent,
                    $latitude,
                    $longitude
                );

                if ($intent !== null) {
                    $out[] = $intent;
                }
            }
        }

        return $out;
    }

    /* ============================================================
     * Core translation
     * ============================================================ */

    private function buildCalendarIntent(
        ?string $eventType,
        string $target,
        array $subEvent,
        float $lat,
        float $lon
    ): ?array {
        $timing   = $subEvent['timing']   ?? null;
        $behavior = $subEvent['behavior'] ?? [];
        $payload  = $subEvent['payload']  ?? null;

        if (!is_array($timing)) {
            return null;
        }

        // --------------------------------------------------------
        // Resolve dates (HARD ONLY for calendar)
        // --------------------------------------------------------
        $startDate = $this->resolveHardDate($timing['start_date'] ?? null);
        $endDate   = $this->resolveHardDate($timing['end_date']   ?? null);

        if (!$startDate || !$endDate) {
            return null;
        }

        // --------------------------------------------------------
        // Resolve times (display-only for sun-based)
        // --------------------------------------------------------
        $startTime = $this->resolveDisplayTime(
            $timing['start_time'] ?? null,
            $startDate,
            $lat,
            $lon
        );

        $endTime = $this->resolveDisplayTime(
            $timing['end_time'] ?? null,
            $endDate,
            $lat,
            $lon
        );

        if (!$startTime || !$endTime) {
            return null;
        }

        $dtStart = new DateTimeImmutable("$startDate $startTime");
        $dtEnd   = new DateTimeImmutable("$endDate $endTime");

        // --------------------------------------------------------
        // Build YAML metadata (symbolic TIME only)
        // --------------------------------------------------------
        $yaml = [
            'behavior' => $behavior,
        ];

        if ($eventType === 'command' && is_array($payload)) {
            $yaml['payload'] = $payload;
        }

        $this->appendSymbolicTimeYaml($yaml, 'start_time', $timing['start_time'] ?? null);
        $this->appendSymbolicTimeYaml($yaml, 'end_time',   $timing['end_time']   ?? null);

        return [
            'summary' => $target,
            'dtstart' => $dtStart,
            'dtend'   => $dtEnd,
            'yaml'    => $yaml,
        ];
    }

    /* ============================================================
     * Date + Time helpers
     * ============================================================ */

    private function resolveHardDate(?array $date): ?string
    {
        if (!is_array($date)) {
            return null;
        }

        if (!empty($date['hard'])) {
            return $date['hard'];
        }

        if (!empty($date['symbolic'])) {
            $year = (int)date('Y');
            $resolved = $this->holidayResolver->resolveSymbolic(
                $date['symbolic'],
                $year
            );

            return $resolved?->format('Y-m-d');
        }

        return null;
    }

    private function resolveDisplayTime(
        ?array $time,
        string $ymd,
        float $lat,
        float $lon
    ): ?string {
        if (!is_array($time)) {
            return null;
        }

        if (!empty($time['symbolic'])) {
            return SunTimeDisplayEstimator::estimate(
                $ymd,
                $time['symbolic'],
                $lat,
                $lon,
                (int)($time['offset'] ?? 0)
            );
        }

        return $time['hard'] ?? null;
    }

    private function appendSymbolicTimeYaml(
        array &$yaml,
        string $key,
        ?array $time
    ): void {
        if (!is_array($time)) {
            return;
        }

        if (!empty($time['symbolic'])) {
            $yaml[$key] = [
                'symbolic' => $time['symbolic'],
                'offset'   => (int)($time['offset'] ?? 0),
            ];
        }
    }
    /**
     * Returns both hard and symbolic (holiday) representation for a calendar date.
     */
    private function resolveCalendarDate(string $ymd): array
    {
        $year = (int)substr($ymd, 0, 4);

        $symbolic = $this->holidayResolver->reverseResolveExact(
            new DateTimeImmutable($ymd),
            $year
        );

        return [
            'hard'     => $ymd,
            'symbolic' => $symbolic,
        ];
    }
}