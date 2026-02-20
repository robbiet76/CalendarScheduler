<?php
declare(strict_types=1);

/**
 * Calendar Scheduler — Source Component
 *
 * File: Adapter/Calendar/Outlook/OutlookConfig.php
 * Purpose: Load and validate Outlook provider configuration values.
 */

namespace CalendarScheduler\Adapter\Calendar\Outlook;

final class OutlookConfig
{
    private string $calendarId;
    private string $configPath;
    /** @var array<string,mixed> */
    private array $data;

    public function __construct(string $configPath)
    {
        if (is_dir($configPath)) {
            $configPath = rtrim($configPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'config.json';
        }
        $this->configPath = $configPath;

        $raw = @file_get_contents($configPath);
        if ($raw === false) {
            throw new \RuntimeException("Outlook config not found: {$configPath}");
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException("Outlook config invalid JSON: {$configPath}");
        }
        $this->data = $data;

        $calendarId = $data['calendar_id'] ?? $data['calendarId'] ?? 'primary';
        if (!is_string($calendarId) || trim($calendarId) === '') {
            $calendarId = 'primary';
        }
        $this->calendarId = trim($calendarId);
    }

    public function getCalendarId(): string
    {
        return $this->calendarId;
    }

    public function getConfigDir(): string
    {
        return dirname($this->configPath);
    }

    /** @return array<string,mixed> */
    public function getOauth(): array
    {
        $oauth = $this->data['oauth'] ?? null;
        if (!is_array($oauth)) {
            throw new \RuntimeException("Outlook config missing oauth block: {$this->configPath}");
        }
        return $oauth;
    }

    public function getTenantId(): string
    {
        $oauth = $this->getOauth();
        $tenantId = $oauth['tenant_id'] ?? 'common';
        if (!is_string($tenantId) || trim($tenantId) === '') {
            return 'common';
        }
        return trim($tenantId);
    }

    public function getTokenPath(): string
    {
        $oauth = $this->getOauth();
        $tokenFile = $oauth['token_file'] ?? 'token.json';
        if (!is_string($tokenFile) || trim($tokenFile) === '') {
            $tokenFile = 'token.json';
        }

        return rtrim($this->getConfigDir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . $tokenFile;
    }
}
