<?php
declare(strict_types=1);

/**
 * Calendar Scheduler — Source Component
 *
 * File: Adapter/Calendar/Google/GoogleConfig.php
 * Purpose: Load and validate Google provider configuration values (calendar,
 * OAuth, and credential file paths) from the plugin config directory.
 */

namespace CalendarScheduler\Adapter\Calendar\Google;

final class GoogleConfig
{
    // Normalized, validated configuration state.
    private string $calendarId;
    private string $configPath;
    private array $data;

    public function __construct(string $configPath)
    {
        // Accept either direct file path or config directory input.
        if (is_dir($configPath)) {
            $configPath = rtrim($configPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'config.json';
        }
        $this->configPath = $configPath;

        // Load and parse provider configuration JSON.
        $raw = @file_get_contents($configPath);
        if ($raw === false) {
            throw new \RuntimeException("Google config not found: {$configPath}");
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException("Google config invalid JSON: {$configPath}");
        }
        $this->data = $data;

        // Resolve calendar ID using preferred and legacy keys.
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
        // OAuth settings are required for API calls and device auth flow.
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
        // Client file can be overridden per config; default to client_secret.json.
        $oauth = $this->getOauth();
        $clientFile = $oauth['client_file'] ?? 'client_secret.json';
        if (!is_string($clientFile) || $clientFile === '') {
            $clientFile = 'client_secret.json';
        }

        return rtrim($this->getConfigDir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . $clientFile;
    }

    public function getTokenPath(): string
    {
        // Token file can be overridden per config; default to token.json.
        $oauth = $this->getOauth();
        $tokenFile = $oauth['token_file'] ?? 'token.json';
        if (!is_string($tokenFile) || $tokenFile === '') {
            $tokenFile = 'token.json';
        }

        return rtrim($this->getConfigDir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . $tokenFile;
    }
}
