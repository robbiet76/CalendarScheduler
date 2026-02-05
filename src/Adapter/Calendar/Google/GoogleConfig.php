<?php
declare(strict_types=1);

namespace CalendarScheduler\Adapter\Calendar\Google;

final class GoogleConfig
{
    private string $calendarId;
    private string $configPath;
    private array $data;

    public function __construct(string $configPath)
    {
        $this->configPath = $configPath;
        $raw = @file_get_contents($configPath);
        if ($raw === false) {
            throw new \RuntimeException("Google config not found: {$configPath}");
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException("Google config invalid JSON: {$configPath}");
        }
        $this->data = $data;

        // Accept either calendar_id (preferred) or calendarId (legacy).
        $calendarId = $data['calendar_id'] ?? $data['calendarId'] ?? null;
        if (!is_string($calendarId) || $calendarId === '') {
            $calendarId = 'primary';
        }
        $this->calendarId = $calendarId;
    }

    public function getCalendarId(): string
    {
        return $this->calendarId;
    }

    /**
     * Absolute directory containing config.json.
     * This is the authoritative base for token.json and client files.
     */
    public function getConfigDir(): string
    {
        return dirname($this->configPath);
    }

    public function getOauth(): array
    {
        $oauth = $this->data['oauth'] ?? null;
        if (!is_array($oauth)) {
            throw new \RuntimeException("Google config missing oauth block: {$this->configPath}");
        }
        return $oauth;
    }

    public function getOauthRedirectUri(): string
    {
        $oauth = $this->getOauth();

        $uri = $oauth['redirect_uri'] ?? null;
        if (!is_string($uri) || $uri === '') {
            throw new \RuntimeException("Google config missing oauth.redirect_uri: {$this->configPath}");
        }

        return $uri;
    }

    public function getClientSecretPath(): string
    {
        $oauth = $this->getOauth();
        $clientFile = $oauth['client_file'] ?? 'client_secret.json';
        if (!is_string($clientFile) || $clientFile === '') {
            $clientFile = 'client_secret.json';
        }

        return rtrim($this->getConfigDir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . $clientFile;
    }
}
