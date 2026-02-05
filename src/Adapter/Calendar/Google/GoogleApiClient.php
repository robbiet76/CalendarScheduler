<?php

namespace CalendarScheduler\Adapter\Calendar\Google;

use Google\Client as GoogleClient;
use Google\Service\Calendar;

class GoogleApiClient
{
    private Calendar $service;

    public function __construct(Calendar $service)
    {
        $this->service = $service;
    }

    public function createEvent(string $calendarId, array $payload): array
    {
        try {
            $event = $this->service->events->insert($calendarId, new Calendar\Event($payload));
            return (array) $event->toSimpleObject();
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to create event: ' . $e->getMessage(), 0, $e);
        }
    }

    public function updateEvent(
        string $calendarId,
        string $eventId,
        array $payload,
        ?string $etag = null
    ): array {
        try {
            $options = [];
            if ($etag !== null) {
                $options['ifMatch'] = $etag;
            }
            $event = $this->service->events->patch($calendarId, $eventId, new Calendar\Event($payload), $options);
            return (array) $event->toSimpleObject();
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to update event: ' . $e->getMessage(), 0, $e);
        }
    }

    public function deleteEvent(
        string $calendarId,
        string $eventId,
        ?string $etag = null
    ): void {
        try {
            $options = [];
            if ($etag !== null) {
                $options['ifMatch'] = $etag;
            }
            $this->service->events->delete($calendarId, $eventId, $options);
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to delete event: ' . $e->getMessage(), 0, $e);
        }
    }
}