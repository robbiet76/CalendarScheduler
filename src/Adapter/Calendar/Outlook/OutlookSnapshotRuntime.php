<?php
declare(strict_types=1);

namespace CalendarScheduler\Adapter\Calendar\Outlook;

use CalendarScheduler\Adapter\Calendar\ProviderSnapshotRuntime;

final class OutlookSnapshotRuntime implements ProviderSnapshotRuntime
{
    private const CONFIG_DIR = '/home/fpp/media/config/calendar-scheduler/calendar/outlook';

    private OutlookConfig $config;
    private OutlookApiClient $client;
    private OutlookCalendarTranslator $translator;

    public function __construct()
    {
        $this->config = new OutlookConfig(self::CONFIG_DIR);
        $this->client = new OutlookApiClient($this->config);
        $this->translator = new OutlookCalendarTranslator();
    }

    public function providerName(): string
    {
        return 'outlook';
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

