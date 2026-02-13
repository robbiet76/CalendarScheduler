<?php

declare(strict_types=1);

namespace CalendarScheduler\Adapter\Calendar\Google;

/**
 * One-time interactive OAuth bootstrap for Google Calendar.
 *
 * CLI-only command: `php bin/calendar-scheduler google:auth`
 *
 * Responsibilities:
 *  - Read client_secret.json
 *  - Print consent URL
 *  - Receive auth code via user paste-back (local web redirect flow)
 *  - Exchange code for tokens
 *  - Persist token.json
 *
 * Notes:
 *  - This does NOT read/write calendar events.
 *  - This does NOT touch Manifest/Diff/Apply logic.
 *  - Uses a local web redirect–based OAuth flow.
 */
final class GoogleOAuthBootstrap
{
    // Fallback only; normally we use auth_uri from client_secret.json
    private const FALLBACK_AUTH_URL = 'https://accounts.google.com/o/oauth2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private GoogleConfig $config;

    public function __construct(GoogleConfig $config)
    {
        $this->config = $config;
    }

    public function runCli(bool $printAuthUrl = false): void
    {
        $configDir = $this->config->getConfigDir();
        $clientSecretPath = $this->config->getClientSecretPath();
        $tokenPath = $this->config->getTokenPath();

        fwrite(STDOUT, PHP_EOL);
        fwrite(STDOUT, "=== Google OAuth Bootstrap (CLI) ===" . PHP_EOL);
        fwrite(STDOUT, "Config dir: {$configDir}" . PHP_EOL);
        fwrite(STDOUT, "Client secret: {$clientSecretPath}" . PHP_EOL);
        fwrite(STDOUT, "Token output: {$tokenPath}" . PHP_EOL);
        fwrite(STDOUT, PHP_EOL);

        // Always print manually constructed OAuth URL for copy/paste into a local browser
        $authUrl = $this->buildAuthUrl();
        fwrite(STDOUT, "Open this URL in a local browser and authorize access:" . PHP_EOL . PHP_EOL);
        fwrite(STDOUT, $authUrl . PHP_EOL . PHP_EOL);

        fwrite(
            STDOUT,
            "Paste the authorization `code` here (you can generate it on any machine/browser), then press Enter:" . PHP_EOL
        );

        fwrite(STDOUT, "> ");
        $code = trim((string) fgets(STDIN));
        if ($code === '') {
            throw new \RuntimeException("No authorization code provided.");
        }

        $token = $this->exchangeCodeForToken($code);
        $this->writeTokenFile($token);

        fwrite(STDOUT, PHP_EOL . "SUCCESS: token.json written." . PHP_EOL);
    }

    private function buildAuthUrl(): string
    {
        $oauth = $this->config->getOauth();
        $client = $this->loadClientSecrets();

        $clientId = $client['client_id'] ?? null;
        if (!is_string($clientId) || $clientId === '') {
            throw new \RuntimeException("client_id missing in client_secret.json");
        }

        $redirectUri = $oauth['redirect_uri'] ?? null;
        if (!is_string($redirectUri) || $redirectUri === '') {
            throw new \RuntimeException("oauth.redirect_uri missing in config.json");
        }

        $scopes = $oauth['scopes'] ?? [];
        if (!is_array($scopes) || count($scopes) === 0) {
            throw new \RuntimeException("oauth.scopes missing in config.json");
        }

        $params = [
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', $scopes),
            'access_type' => 'offline',
            'prompt' => 'consent',
        ];

        // Prefer auth_uri from client_secret.json (matches working manual URL)
        $authUrlBase = $client['auth_uri'] ?? self::FALLBACK_AUTH_URL;

        return $authUrlBase . '?' . http_build_query($params);
    }

    /**
     * Google Calendar API writes/refresh in this project rely on a **Web application** OAuth client.
     * Desktop/"installed" clients do not support the redirect URI model we use on FPP and will
     * cause `invalid_client` or HTTP 400/401 failures.
     *
     * @return array<string,mixed>
     */
    private function loadClientSecrets(): array
    {
        $path = $this->config->getClientSecretPath();
        $clientSecret = $this->loadJsonFile($path);

        if (isset($clientSecret['web']) && is_array($clientSecret['web'])) {
            return $clientSecret['web'];
        }

        if (isset($clientSecret['installed']) && is_array($clientSecret['installed'])) {
            throw new \RuntimeException(
                'client_secret.json contains an "installed" (Desktop) OAuth client. ' .
                'This project requires a **Web application** OAuth client with an Authorized redirect URI ' .
                'matching oauth.redirect_uri (e.g. http://127.0.0.1:8765/oauth2callback). ' .
                'Create a Web OAuth client in Google Cloud Console and download its client_secret.json.'
            );
        }

        throw new \RuntimeException(
            'client_secret.json is missing a "web" OAuth client block. ' .
            'Create a Web application OAuth client in Google Cloud Console and download its client_secret.json.'
        );
    }

    private function exchangeCodeForToken(string $code): array
    {
        $oauth = $this->config->getOauth();
        $client = $this->loadClientSecrets();

        $clientId = $client['client_id'] ?? null;
        $clientSecretValue = $client['client_secret'] ?? null;
        if (!is_string($clientId) || $clientId === '' || !is_string($clientSecretValue) || $clientSecretValue === '') {
            throw new \RuntimeException("client_id/client_secret missing in client_secret.json");
        }

        $redirectUri = $oauth['redirect_uri'] ?? null;
        if (!is_string($redirectUri) || $redirectUri === '') {
            throw new \RuntimeException("oauth.redirect_uri missing in config.json");
        }

        $post = [
            'code' => $code,
            'client_id' => $clientId,
            'client_secret' => $clientSecretValue,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ];

        $resp = $this->curlJson(self::TOKEN_URL, $post);
        if (!is_array($resp)) {
            throw new \RuntimeException("token endpoint did not return JSON.");
        }

        if (isset($resp['error'])) {
            $msg = is_string($resp['error']) ? $resp['error'] : 'unknown_error';
            $desc = isset($resp['error_description']) && is_string($resp['error_description']) ? $resp['error_description'] : '';
            $suffix = $desc !== '' ? " ({$desc})" : '';
            throw new \RuntimeException("token exchange failed: {$msg}{$suffix}");
        }

        return $resp;
    }

    private function writeTokenFile(array $token): void
    {
        $tokenPath = $this->config->getTokenPath();
        $oauth = $this->config->getOauth();
        $scopes = $oauth['scopes'] ?? [];
        $scopeValue = is_array($scopes) ? implode(' ', $scopes) : '';

        // Normalize token payload for runtime usage
        $now = time();
        $expiresIn = isset($token['expires_in']) ? (int) $token['expires_in'] : 0;
        $normalized = [
            'access_token' => (string) ($token['access_token'] ?? ''),
            'refresh_token' => (string) ($token['refresh_token'] ?? ''),
            'token_type' => (string) ($token['token_type'] ?? 'Bearer'),
            'scope' => (string) ($token['scope'] ?? $scopeValue),
            'expires_in' => $expiresIn,
            'expires_at' => $expiresIn > 0 ? ($now + $expiresIn - 30) : 0, // subtract small safety buffer
            'created_at' => $now,
        ];

        if ($normalized['access_token'] === '') {
            throw new \RuntimeException("Token exchange returned no access_token.");
        }

        // refresh_token is often only returned the *first* time with prompt=consent.
        // If missing and token.json already exists, preserve existing refresh_token.
        if ($normalized['refresh_token'] === '' && is_file($tokenPath)) {
            $existing = $this->loadJsonFile($tokenPath);
            if (isset($existing['refresh_token']) && is_string($existing['refresh_token']) && $existing['refresh_token'] !== '') {
                $normalized['refresh_token'] = $existing['refresh_token'];
            }
        }

        $this->writeJsonFileAtomic($tokenPath, $normalized);
        @chmod($tokenPath, 0600);
    }

    /**
     * @return mixed
     */
    private function curlJson(string $url, array $post)
    {
        $ch = curl_init($url);
        if ($ch === false) {
            fwrite(STDERR, "ERROR: curl_init failed.\n");
            exit(1);
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $errno !== 0) {
            fwrite(STDERR, "ERROR: cURL request failed ({$errno}): {$err}\n");
            exit(1);
        }

        $decoded = json_decode((string) $raw, true);
        if ($decoded === null) {
            fwrite(STDERR, "ERROR: Non-JSON response from {$url} (HTTP {$status}).\n");
            fwrite(STDERR, (string) $raw . "\n");
            exit(1);
        }

        return $decoded;
    }

    private function loadJsonFile(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            fwrite(STDERR, "ERROR: Failed reading {$path}\n");
            exit(1);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            fwrite(STDERR, "ERROR: Invalid JSON in {$path}\n");
            exit(1);
        }
        return $decoded;
    }

    private function writeJsonFileAtomic(string $path, array $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
                fwrite(STDERR, "ERROR: Could not create dir: {$dir}\n");
                exit(1);
            }
        }

        $tmp = $path . '.tmp';
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            fwrite(STDERR, "ERROR: json_encode failed\n");
            exit(1);
        }

        if (file_put_contents($tmp, $json . "\n") === false) {
            fwrite(STDERR, "ERROR: Could not write tmp file: {$tmp}\n");
            exit(1);
        }

        if (!rename($tmp, $path)) {
            @unlink($tmp);
            fwrite(STDERR, "ERROR: Could not move {$tmp} to {$path}\n");
            exit(1);
        }
    }
}
