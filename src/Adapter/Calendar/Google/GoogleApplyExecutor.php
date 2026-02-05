<?php

declare(strict_types=1);

namespace CalendarScheduler\Adapter\Calendar\Google;

use RuntimeException;

/**
 * GoogleApplyExecutor
 *
 * Executes ApplyOps against Google Calendar via a provided transport.
 * This class enforces Apply semantics but contains no OAuth or HTTP logic itself.
 * This executor never inspects or iterates subevents; all collapsing/projection logic is delegated to GoogleEventMapper.
 */
final class GoogleApplyExecutor
{
    private GoogleApiClient $client;
    private GoogleEventMapper $mapper;
    private string $timezone;

    public function __construct(
        GoogleApiClient $client,
        GoogleEventMapper $mapper,
        string $timezone
    ) {
        $this->client = $client;
        $this->mapper = $mapper;
        $this->timezone = $timezone;
    }

    /**
     * Apply a list of ApplyOps.
     *
     * Each ApplyOp is expected to contain a full Manifest Event envelope (not subevents).
     *
     * @param array<int,array> $applyOps
     */
    public function apply(array $applyOps): void
    {
        foreach ($applyOps as $op) {
            $this->applyOne($op);
        }
    }

    /**
     * Apply a single ApplyOp.
     */
    private function applyOne(array $applyOp): void
    {
        $mapped = $this->mapper->mapApplyOp($applyOp, $this->timezone);

        $method = $mapped['method'];

        switch ($method) {
            case 'create':
                $calendarId = (string) ($mapped['calendarId'] ?? 'primary');
                $this->client->request(
                    'POST',
                    '/calendars/' . rawurlencode($calendarId) . '/events',
                    [],
                    $mapped['payload']
                );
                break;

            case 'update':
                if (empty($mapped['eventId'])) {
                    throw new RuntimeException('Update operation missing eventId');
                }
                $calendarId = (string) ($mapped['calendarId'] ?? 'primary');
                $eventId = (string) $mapped['eventId'];
                $this->client->request(
                    'PUT',
                    '/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($eventId),
                    [],
                    $mapped['payload']
                );
                break;

            case 'delete':
                if (empty($mapped['eventId'])) {
                    throw new RuntimeException('Delete operation missing eventId');
                }
                $calendarId = (string) ($mapped['calendarId'] ?? 'primary');
                $eventId = (string) $mapped['eventId'];
                $this->client->request(
                    'DELETE',
                    '/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($eventId)
                );
                break;

            default:
                throw new RuntimeException("Unsupported Apply method '{$method}'");
        }
    }
}
