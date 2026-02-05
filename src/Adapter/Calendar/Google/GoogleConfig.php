<?php

namespace CalendarScheduler\Adapter\Calendar\Google;

class GoogleConfig
{
    private array $config;

    public function __construct(string $path)
    {
        if (!file_exists($path)) {
            throw new \RuntimeException("Google calendar config not found: {$path}");
        }

        $json = file_get_contents($path);
        $data = json_decode($json, true);

        if (!is_array($data)) {
            throw new \RuntimeException("Invalid Google calendar config JSON");
        }

        if (empty($data['calendar_id'])) {
            throw new \RuntimeException("Missing required calendar_id in Google config");
        }

        $this->config = $data;
    }

    public function getCalendarId(): string
    {
        return $this->config['calendar_id'];
    }
}