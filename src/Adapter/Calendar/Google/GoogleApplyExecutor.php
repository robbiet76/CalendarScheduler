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
                $this->client->createEvent(
                    $mapped['payload'],
                    $mapped['calendarId'] ?? null
                );
                break;

            case 'update':
                if (empty($mapped['eventId'])) {
                    throw new RuntimeException('Update operation missing eventId');
                }
                $this->client->updateEvent(
                    $mapped['eventId'],
                    $mapped['payload'],
                    $mapped['etag'] ?? null
                );
                break;

            case 'delete':
                if (empty($mapped['eventId'])) {
                    throw new RuntimeException('Delete operation missing eventId');
                }
                $this->client->deleteEvent(
                    $mapped['eventId'],
                    $mapped['etag'] ?? null
                );
                break;

            default:
                throw new RuntimeException("Unsupported Apply method '{$method}'");
        }
    }
}
