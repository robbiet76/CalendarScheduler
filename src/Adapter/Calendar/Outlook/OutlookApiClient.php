<?php
declare(strict_types=1);

/**
 * Calendar Scheduler — Source Component
 *
 * File: Adapter/Calendar/Outlook/OutlookApiClient.php
 * Purpose: Outlook API client surface mirroring the Google client contract.
 */

namespace CalendarScheduler\Adapter\Calendar\Outlook;

final class OutlookApiClient
{
    private const GRAPH_BASE = 'https://graph.microsoft.com/v1.0';

    private OutlookConfig $config;
    private bool $debugCalendar;
    private int $deleteSkippedAlreadyAbsent = 0;

    public function __construct(OutlookConfig $config)
    {
        $this->config = $config;
        $this->debugCalendar = getenv('GCS_DEBUG_CALENDAR') === '1';
    }

    public function getConfig(): OutlookConfig
    {
        return $this->config;
    }

    public function ensureAuthenticated(): void
    {
        $token = $this->loadToken();
        if ($token === null) {
            throw new \RuntimeException(
                'Outlook OAuth token missing. Run the Outlook auth bootstrap to generate token.json.'
            );
        }

        $expiresAt = $token['expires_at'] ?? null;
        if (is_int($expiresAt) && $expiresAt > time() + 60) {
            return;
        }

        $refreshToken = $token['refresh_token'] ?? null;
        if (!is_string($refreshToken) || trim($refreshToken) === '') {
            throw new \RuntimeException('Outlook OAuth refresh_token missing; re-auth is required.');
        }

        $newToken = $this->refreshAccessToken($refreshToken);
        $this->saveToken($newToken);
    }

    /** @param array<string,mixed> $payload */
    public function createEvent(string $calendarId, array $payload): string
    {
        $this->ensureAuthenticated();

        $res = $this->requestJson(
            'POST',
            $this->calendarBasePath($calendarId) . '/events',
            $payload
        );

        $id = $res['id'] ?? null;
        if (!is_string($id) || trim($id) === '') {
            throw new \RuntimeException('Outlook createEvent: missing id in response.');
        }

        return $id;
    }

    /** @param array<string,mixed> $payload */
    public function updateEvent(string $calendarId, string $eventId, array $payload): void
    {
        $this->ensureAuthenticated();

        $this->requestJson(
            'PATCH',
            $this->eventPath($calendarId, $eventId),
            $payload
        );
    }

    public function deleteEvent(string $calendarId, string $eventId): void
    {
        $this->ensureAuthenticated();

        try {
            $this->requestJson(
                'DELETE',
                $this->eventPath($calendarId, $eventId),
                null
            );
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
            if (strpos($message, 'HTTP 404') !== false || strpos($message, 'HTTP 410') !== false) {
                $this->deleteSkippedAlreadyAbsent++;
                if ($this->debugCalendar) {
                    error_log(
                        'OutlookApiClient: delete skipped (already absent) calendarId='
                        . $calendarId . ' eventId=' . $eventId
                    );
                }
                return;
            }
            throw $e;
        }
    }

    public function emitDiagnosticsSummary(): void
    {
        if ($this->deleteSkippedAlreadyAbsent > 0) {
            error_log(
                'OutlookApiClient summary: delete_skipped_already_absent=' .
                $this->deleteSkippedAlreadyAbsent
            );
            $this->deleteSkippedAlreadyAbsent = 0;
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function listCalendars(): array
    {
        $this->ensureAuthenticated();

        $res = $this->requestJson('GET', '/me/calendars', null);
        $items = $res['value'] ?? [];

        return is_array($items) ? $items : [];
    }

    /** @return array<string,mixed> */
    public function getMe(): array
    {
        $this->ensureAuthenticated();
        return $this->requestJson('GET', '/me', null);
    }

    /** @return array<string,mixed> */
    public function getCalendar(string $calendarId): array
    {
        $this->ensureAuthenticated();

        return $this->requestJson('GET', $this->calendarBasePath($calendarId), null);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function patchCalendar(string $calendarId, array $payload): array
    {
        $this->ensureAuthenticated();

        return $this->requestJson(
            'PATCH',
            $this->calendarBasePath($calendarId),
            $payload
        );
    }

    /**
     * @param array<string,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    public function listEvents(string $calendarId, array $params = []): array
    {
        $this->ensureAuthenticated();

        $allItems = [];

        $baseParams = array_merge([
            '$top' => 1000,
            '$orderby' => 'lastModifiedDateTime asc',
        ], $params);
        if (!isset($baseParams['$expand']) || !is_string($baseParams['$expand']) || trim((string)$baseParams['$expand']) === '') {
            $baseParams['$expand'] = OutlookEventMetadataSchema::graphExpandQuery();
        }

        $url = $this->calendarBasePath($calendarId) . '/events';
        if ($baseParams !== []) {
            $url .= '?' . http_build_query($baseParams, '', '&', PHP_QUERY_RFC3986);
        }

        while ($url !== null) {
            $res = $this->requestJson('GET', $url, null);
            $items = $res['value'] ?? null;
            if (is_array($items)) {
                $allItems = array_merge($allItems, $items);
            }

            $next = $res['@odata.nextLink'] ?? null;
            $url = is_string($next) && $next !== '' ? $next : null;
        }

        return $allItems;
    }

    private function calendarBasePath(string $calendarId): string
    {
        $normalized = strtolower(trim($calendarId));
        if ($normalized === '' || $normalized === 'primary' || $normalized === 'default') {
            return '/me/calendar';
        }

        return '/me/calendars/' . rawurlencode($calendarId);
    }

    private function eventPath(string $calendarId, string $eventId): string
    {
        $normalized = strtolower(trim($calendarId));
        if ($normalized === '' || $normalized === 'primary' || $normalized === 'default') {
            return '/me/events/' . rawurlencode($eventId);
        }

        return '/me/calendars/' . rawurlencode($calendarId) . '/events/' . rawurlencode($eventId);
    }

    // ---------------------------------------------------------------------
    // Token handling
    // ---------------------------------------------------------------------

    /** @return array<string,mixed>|null */
    private function loadToken(): ?array
    {
        $path = $this->config->getTokenPath();
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    /** @param array<string,mixed> $token */
    private function saveToken(array $token): void
    {
        $path = $this->config->getTokenPath();
        $json = json_encode($token, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Unable to encode Outlook token JSON.');
        }
        if (@file_put_contents($path, $json . PHP_EOL) === false) {
            throw new \RuntimeException("Unable to write Outlook token file: {$path}");
        }
        @chmod($path, 0600);
    }

    /**
     * @return array<string,mixed>
     */
    private function refreshAccessToken(string $refreshToken): array
    {
        $oauth = $this->config->getOauth();

        $clientId = is_string($oauth['client_id'] ?? null) ? trim((string)$oauth['client_id']) : '';
        $clientSecret = is_string($oauth['client_secret'] ?? null) ? trim((string)$oauth['client_secret']) : '';
        $scopes = is_array($oauth['scopes'] ?? null) ? $oauth['scopes'] : [];

        if ($clientId === '' || $clientSecret === '') {
            throw new \RuntimeException('Outlook OAuth client_id/client_secret missing in config.json oauth block.');
        }

        $scope = implode(' ', array_values(array_filter($scopes, 'is_string')));
        if ($scope === '') {
            $scope = 'https://graph.microsoft.com/.default';
        }

        $tokenUrl = 'https://login.microsoftonline.com/'
            . rawurlencode($this->config->getTenantId())
            . '/oauth2/v2.0/token';

        $form = [
            'grant_type' => 'refresh_token',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
            'scope' => $scope,
        ];

        $response = $this->requestFormUrlEncodedJson('POST', $tokenUrl, $form);

        $accessToken = $response['access_token'] ?? null;
        $expiresIn = $response['expires_in'] ?? null;

        if (!is_string($accessToken) || trim($accessToken) === '' || (!is_int($expiresIn) && !is_numeric($expiresIn))) {
            throw new \RuntimeException('Outlook OAuth refresh: missing access_token/expires_in.');
        }

        $now = time();
        $expiresInInt = (int)$expiresIn;
        $newRefreshToken = $response['refresh_token'] ?? $refreshToken;

        return [
            'access_token' => $accessToken,
            'refresh_token' => is_string($newRefreshToken) ? $newRefreshToken : $refreshToken,
            'token_type' => is_string($response['token_type'] ?? null) ? $response['token_type'] : 'Bearer',
            'scope' => is_string($response['scope'] ?? null) ? $response['scope'] : $scope,
            'expires_in' => $expiresInInt,
            'expires_at' => $now + $expiresInInt - 30,
            'created_at' => $now,
        ];
    }

    /**
     * @param array<string,mixed>|null $payload
     * @return array<string,mixed>
     */
    private function requestJson(string $method, string $pathOrUrl, ?array $payload): array
    {
        $token = $this->loadToken();
        if (!is_array($token) || !is_string($token['access_token'] ?? null) || trim((string)$token['access_token']) === '') {
            throw new \RuntimeException('Outlook request missing access token.');
        }

        $url = str_starts_with($pathOrUrl, 'http://') || str_starts_with($pathOrUrl, 'https://')
            ? $pathOrUrl
            : self::GRAPH_BASE . $pathOrUrl;

        if (!function_exists('curl_init')) {
            throw new \RuntimeException('cURL extension is required for Outlook API requests.');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Outlook API request failed: unable to initialize cURL.');
        }

        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $token['access_token'],
        ];

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        if ($payload !== null) {
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                curl_close($ch);
                throw new \RuntimeException('Unable to encode Outlook payload JSON.');
            }
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $errno !== 0) {
            throw new \RuntimeException('Outlook API request failed: ' . $err);
        }

        if ($code >= 200 && $code < 300) {
            $trimmed = trim((string)$raw);
            if ($trimmed === '') {
                return [];
            }
            $data = json_decode($trimmed, true);
            if (!is_array($data)) {
                throw new \RuntimeException("Outlook API returned invalid JSON (HTTP {$code}).");
            }
            return $data;
        }

        $decoded = json_decode((string)$raw, true);
        $msg = null;
        if (is_array($decoded)) {
            $errNode = is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
            $msg = is_string($errNode['message'] ?? null) ? $errNode['message'] : null;
            if ($msg === null) {
                $msg = is_string($decoded['error_description'] ?? null) ? $decoded['error_description'] : null;
            }
        }
        if (!is_string($msg) || trim($msg) === '') {
            $msg = 'Unknown Outlook API error';
        }

        $detail = '';
        if ($this->debugCalendar) {
            $payloadSummary = '';
            if (is_array($payload)) {
                $summary = [
                    'subject' => $payload['subject'] ?? null,
                    'start' => $payload['start'] ?? null,
                    'end' => $payload['end'] ?? null,
                    'isAllDay' => $payload['isAllDay'] ?? null,
                    'recurrence' => $payload['recurrence'] ?? null,
                ];
                $encodedSummary = json_encode($summary, JSON_UNESCAPED_SLASHES);
                $payloadSummary = is_string($encodedSummary) ? $encodedSummary : '';
            }
            $detail = ' [path=' . $pathOrUrl . ($payloadSummary !== '' ? ' payload=' . $payloadSummary : '') . ']';
        }

        throw new \RuntimeException("Outlook API error (HTTP {$code}): {$msg}{$detail}");
    }

    /**
     * @param array<string,string> $form
     * @return array<string,mixed>
     */
    private function requestFormUrlEncodedJson(string $method, string $url, array $form): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('cURL extension is required for Outlook OAuth requests.');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Outlook OAuth request failed: unable to initialize cURL.');
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form, '', '&', PHP_QUERY_RFC3986));

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $errno !== 0) {
            throw new \RuntimeException('Outlook OAuth request failed: ' . $err);
        }

        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("Outlook OAuth response invalid JSON (HTTP {$code}).");
        }

        if ($code < 200 || $code >= 300) {
            $errCode = is_string($decoded['error'] ?? null) ? $decoded['error'] : 'oauth_error';
            $msg = is_string($decoded['error_description'] ?? null)
                ? $decoded['error_description']
                : (is_string($decoded['error'] ?? null) ? $decoded['error'] : 'Unknown OAuth error');
            throw new \RuntimeException("Outlook OAuth refresh error (HTTP {$code}, {$errCode}): {$msg}");
        }

        return $decoded;
    }
}
