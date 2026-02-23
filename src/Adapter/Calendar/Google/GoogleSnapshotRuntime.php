<?php
declare(strict_types=1);

namespace CalendarScheduler\Adapter\Calendar\Google;

use CalendarScheduler\Adapter\Calendar\ProviderSnapshotRuntime;

final class GoogleSnapshotRuntime implements ProviderSnapshotRuntime
{
    private const CONFIG_DIR = '/home/fpp/media/config/calendar-scheduler/calendar/google';

    private GoogleConfig $config;
    private GoogleApiClient $client;
    private GoogleCalendarTranslator $translator;

    public function __construct()
    {
        $this->config = new GoogleConfig(self::CONFIG_DIR);
        $this->client = new GoogleApiClient($this->config);
        $this->translator = new GoogleCalendarTranslator();
    }

    public function providerName(): string
    {
        return 'google';
    }

    public function calendarId(): string
    {
        return $this->config->getCalendarId();
    }

    public function translatedEvents(): array
    {
        $rawEvents = $this->client->listEvents($this->config->getCalendarId());
        return $this->translator->ingest($rawEvents, $this->config->getCalendarId());
    }
}

