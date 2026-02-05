<?php
declare(strict_types=1);

namespace CalendarScheduler\Adapter\Calendar\Google;

final class GoogleApiClient
{
    private GoogleConfig $config;

    public function __construct(GoogleConfig $config)
    {
        $this->config = $config;
    }

    public function getConfig(): GoogleConfig
    {
        return $this->config;
    }

    /**
     * Ensures we have a valid access token (refresh if needed).
     *
     * For bootstrap (first-time auth), we intentionally fail hard and require the
     * CLI auth flow to create token.json. (We’ll implement google:auth next.)
     */
    public function ensureAuthenticated(): void
    {
        $token = $this->loadToken();
        if ($token === null) {
            throw new \RuntimeException(
                "Google OAuth token missing. Run the CLI auth bootstrap to generate token.json."
            );
        }

        $expiresAt = $token['expires_at'] ?? null;
        if (is_int($expiresAt) && $expiresAt > time() + 60) {
            return; // still valid
        }

        $refreshToken = $token['refresh_token'] ?? null;
        if (!is_string($refreshToken) || $refreshToken === '') {
            throw new \RuntimeException("Google OAuth refresh_token missing; re-auth is required.");
        }

        $newToken = $this->refreshAccessToken($refreshToken);
        $this->saveToken($newToken);
    }

    /**
     * Create a Google Calendar event.
     * Returns the new Google eventId.
     */
    public function createEvent(string $calendarId, array $payload): string
    {
        $this->ensureAuthenticated();
        $res = $this->requestJson(
            'POST',
            "/calendars/" . rawurlencode($calendarId) . "/events",
            $payload
        );
        $id = $res['id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw new \RuntimeException("Google createEvent: missing id in response.");
        }
        return $id;
    }

    public function updateEvent(string $calendarId, string $eventId, array $payload): void
    {
        $this->ensureAuthenticated();
        $this->requestJson(
            'PATCH',
            "/calendars/" . rawurlencode($calendarId) . "/events/" . rawurlencode($eventId),
            $payload
        );
    }

    public function deleteEvent(string $calendarId, string $eventId): void
    {
        $this->ensureAuthenticated();
        $this->requestJson(
            'DELETE',
            "/calendars/" . rawurlencode($calendarId) . "/events/" . rawurlencode($eventId),
            null
        );
    }

    /**
     * List calendars accessible by the authenticated user.
     * Returns raw Google CalendarList entries.
     */
    public function listCalendars(): array
    {
        $this->ensureAuthenticated();
        $res = $this->requestJson(
            'GET',
            '/users/me/calendarList',
            null
        );
        return $res['items'] ?? [];
    }

    /**
     * List events for a given calendar.
     *
     * @param string $calendarId
     * @param array $params Optional query params (timeMin, timeMax, syncToken, pageToken, etc.)
     * @return array Raw Google Event resources
     */
    public function listEvents(string $calendarId, array $params = []): array
    {
        $this->ensureAuthenticated();

        $query = '';
        if (!empty($params)) {
            $query = '?' . http_build_query($params);
        }

        $res = $this->requestJson(
            'GET',
            '/calendars/' . rawurlencode($calendarId) . '/events' . $query,
            null
        );

        return $res['items'] ?? [];
    }

    // ---------------------------------------------------------------------
    // Token handling
    // ---------------------------------------------------------------------

    private function getTokenFilePath(): string
    {
        $oauth = $this->config->getOauth();

        $tokenFile = $oauth['token_file'] ?? 'token.json';
        if (!is_string($tokenFile) || $tokenFile === '') {
            $tokenFile = 'token.json';
        }

        // token_file is relative to the Google config directory
        return rtrim($this->config->getConfigDir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . $tokenFile;
    }

    private function loadToken(): ?array
    {
        $path = $this->getTokenFilePath();
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    private function saveToken(array $token): void
    {
        $path = $this->getTokenFilePath();
        $json = json_encode($token, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException("Unable to encode token JSON.");
        }
        if (@file_put_contents($path, $json) === false) {
            throw new \RuntimeException("Unable to write token file: {$path}");
        }
    }

    private function refreshAccessToken(string $refreshToken): array
    {
        // Load client_id / client_secret from client_secret.json
        $clientSecretPath = $this->config->getClientSecretPath();

        $raw = @file_get_contents($clientSecretPath);
        if ($raw === false) {
            throw new \RuntimeException(
                "Unable to read Google client_secret.json at {$clientSecretPath}"
            );
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            throw new \RuntimeException("Invalid JSON in client_secret.json");
        }

        // Support both "web" and "installed" OAuth client types
        $clientBlock = $json['web'] ?? $json['installed'] ?? null;
        if (!is_array($clientBlock)) {
            throw new \RuntimeException(
                "client_secret.json missing 'web' or 'installed' OAuth block"
            );
        }

        $clientId = $clientBlock['client_id'] ?? null;
        $clientSecret = $clientBlock['client_secret'] ?? null;
        $oauth = $this->config->getOauth();
        $tokenUri = $oauth['token_uri'] ?? 'https://oauth2.googleapis.com/token';

        if (!is_string($clientId) || $clientId === '' || !is_string($clientSecret) || $clientSecret === '') {
            throw new \RuntimeException(
                "Google OAuth client_id/client_secret missing in client_secret.json"
            );
        }

        $post = http_build_query([
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        $ch = curl_init($tokenUri);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);

        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("Google OAuth refresh failed: {$err}");
        }
        curl_close($ch);

        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new \RuntimeException("Google OAuth refresh: invalid JSON response (HTTP {$code}).");
        }
        if ($code < 200 || $code >= 300) {
            $msg = $data['error_description'] ?? $data['error'] ?? 'unknown';
            throw new \RuntimeException("Google OAuth refresh error (HTTP {$code}): {$msg}");
        }

        $accessToken = $data['access_token'] ?? null;
        $expiresIn = $data['expires_in'] ?? null;
        if (!is_string($accessToken) || $accessToken === '' || !is_int($expiresIn)) {
            throw new \RuntimeException("Google OAuth refresh: missing access_token/expires_in.");
        }

        // Preserve refresh_token from prior token.
        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_at' => time() + $expiresIn,
        ];
    }

    // ---------------------------------------------------------------------
    // Google Calendar REST helpers
    // ---------------------------------------------------------------------

    private function requestJson(string $method, string $path, ?array $payload): array
    {
        $token = $this->loadToken();
        if ($token === null || !is_string($token['access_token'] ?? null)) {
            throw new \RuntimeException("Missing access_token; OAuth bootstrap required.");
        }

        $base = 'https://www.googleapis.com/calendar/v3';
        $url = $base . $path;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

        $headers = [
            'Authorization: Bearer ' . $token['access_token'],
            'Accept: application/json',
        ];

        if ($payload !== null) {
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                throw new \RuntimeException("Unable to encode Google payload JSON.");
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
            $headers[] = 'Content-Type: application/json';
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("Google API request failed: {$err}");
        }
        curl_close($ch);

        // DELETE may return empty body.
        if ($body === '' || $body === null) {
            if ($code >= 200 && $code < 300) {
                return [];
            }
            throw new \RuntimeException("Google API error (HTTP {$code}) with empty body.");
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new \RuntimeException("Google API returned invalid JSON (HTTP {$code}).");
        }

        if ($code < 200 || $code >= 300) {
            $msg = $data['error']['message'] ?? 'unknown';
            throw new \RuntimeException("Google API error (HTTP {$code}): {$msg}");
        }

        return $data;
    }
}
