<?php
declare(strict_types=1);

namespace CalendarScheduler\Adapter\Calendar\Google;

final class GoogleApiClient
{
    private GoogleConfig $config;

    /** @var string[] */
    private array $scopes;

    private const DEVICE_CODE_ENDPOINT = 'https://oauth2.googleapis.com/device/code';
    private const TOKEN_ENDPOINT       = 'https://oauth2.googleapis.com/token';

    private const CALENDAR_BASE = 'https://www.googleapis.com/calendar/v3';

    /**
     * @param string[] $scopes
     */
    public function __construct(GoogleConfig $config, array $scopes)
    {
        $this->config = $config;
        $this->scopes = $scopes;
    }

    // ---------------------------------------------------------------------
    // Public: auth
    // ---------------------------------------------------------------------

    /**
     * One-time interactive auth for headless FPP (device code flow).
     * Prints instructions + polls until authorized, then persists refresh token.
     */
    public function authorizeDeviceFlowInteractive(): void
    {
        $clientId = $this->config->getClientId();
        $clientSecret = $this->config->getClientSecret();

        if ($clientId === '' || $clientSecret === '') {
            throw new \RuntimeException('GoogleApiClient: client_id/client_secret missing in config.json');
        }

        $device = $this->httpForm(self::DEVICE_CODE_ENDPOINT, [
            'client_id' => $clientId,
            'scope' => implode(' ', $this->scopes),
        ]);

        $userCode = (string) ($device['user_code'] ?? '');
        $verificationUrl = (string) ($device['verification_url'] ?? '');
        $deviceCode = (string) ($device['device_code'] ?? '');
        $interval = (int) ($device['interval'] ?? 5);

        if ($userCode === '' || $verificationUrl === '' || $deviceCode === '') {
            throw new \RuntimeException('GoogleApiClient: device code response missing fields');
        }

        // CLI output only — ok for setup command
        fwrite(STDERR, "\nGoogle OAuth authorization required.\n");
        fwrite(STDERR, "1) On another device, open: {$verificationUrl}\n");
        fwrite(STDERR, "2) Enter code: {$userCode}\n\n");
        fwrite(STDERR, "Waiting for authorization...\n");

        $grantType = 'urn:ietf:params:oauth:grant-type:device_code';
        $started = time();

        while (true) {
            // Poll token endpoint
            try {
                $token = $this->httpForm(self::TOKEN_ENDPOINT, [
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'device_code' => $deviceCode,
                    'grant_type' => $grantType,
                ], [200, 400, 401, 403, 428]);
            } catch (\Throwable $e) {
                // transient network error
                sleep(max(1, $interval));
                continue;
            }

            if (isset($token['access_token'])) {
                $this->persistTokensFromTokenResponse($token);
                fwrite(STDERR, "Authorized. Tokens saved to: " . $this->config->getPath() . "\n\n");
                return;
            }

            $err = (string) ($token['error'] ?? '');
            if ($err === 'authorization_pending') {
                sleep(max(1, $interval));
                continue;
            }
            if ($err === 'slow_down') {
                $interval += 5;
                sleep($interval);
                continue;
            }
            if ($err === 'access_denied') {
                throw new \RuntimeException('GoogleApiClient: access denied by user');
            }
            if ($started + 1800 < time()) {
                throw new \RuntimeException('GoogleApiClient: device authorization timed out');
            }

            // Unknown error
            throw new \RuntimeException('GoogleApiClient: token polling error: ' . json_encode($token));
        }
    }

    /**
     * Ensures a usable access token exists (refresh if needed).
     */
    public function ensureAccessToken(): string
    {
        $tokens = $this->config->getTokens();
        $access = (string) ($tokens['access_token'] ?? '');
        $refresh = (string) ($tokens['refresh_token'] ?? '');
        $expiresAt = (int) ($tokens['access_token_expires_at'] ?? 0);

        // 60s skew
        if ($access !== '' && $expiresAt > (time() + 60)) {
            return $access;
        }

        if ($refresh === '') {
            throw new \RuntimeException('GoogleApiClient: no refresh token; run device auth');
        }

        $clientId = $this->config->getClientId();
        $clientSecret = $this->config->getClientSecret();

        $token = $this->httpForm(self::TOKEN_ENDPOINT, [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refresh,
            'grant_type' => 'refresh_token',
        ]);

        if (!isset($token['access_token'])) {
            throw new \RuntimeException('GoogleApiClient: refresh failed: ' . json_encode($token));
        }

        // refresh response might not include refresh_token; preserve existing
        $token['refresh_token'] = $refresh;
        $this->persistTokensFromTokenResponse($token);

        return (string) $token['access_token'];
    }

    // ---------------------------------------------------------------------
    // Public: Calendar API helpers (low-level)
    // ---------------------------------------------------------------------

    /**
     * @param array<string,string> $query
     * @param array<string,mixed>|null $json
     * @return array<string,mixed>
     */
    public function request(string $method, string $path, array $query = [], ?array $json = null): array
    {
        $token = $this->ensureAccessToken();
        $url = rtrim(self::CALENDAR_BASE, '/') . '/' . ltrim($path, '/');
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $headers = [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
        ];
        $body = null;
        if ($json !== null) {
            $headers[] = 'Content-Type: application/json';
            $body = json_encode($json);
            if ($body === false) {
                throw new \RuntimeException('GoogleApiClient: failed to encode json body');
            }
        }

        return $this->httpRaw($method, $url, $headers, $body);
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /**
     * @param array<string,mixed> $token
     */
    private function persistTokensFromTokenResponse(array $token): void
    {
        $access = (string) ($token['access_token'] ?? '');
        $refresh = (string) ($token['refresh_token'] ?? '');
        $expiresIn = (int) ($token['expires_in'] ?? 0);

        $tokens = $this->config->getTokens();
        $tokens['access_token'] = $access;
        if ($refresh !== '') {
            $tokens['refresh_token'] = $refresh;
        }
        if ($expiresIn > 0) {
            $tokens['access_token_expires_at'] = time() + $expiresIn;
        }
        $this->config->setTokens($tokens);
        $this->config->save();
    }

    /**
     * @param array<string,string> $params
     * @param int[] $allowedStatus
     * @return array<string,mixed>
     */
    private function httpForm(string $url, array $params, array $allowedStatus = [200]): array
    {
        $body = http_build_query($params);
        $headers = [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ];
        return $this->httpRaw('POST', $url, $headers, $body, $allowedStatus);
    }

    /**
     * @param string[] $headers
     * @param int[] $allowedStatus
     * @return array<string,mixed>
     */
    private function httpRaw(
        string $method,
        string $url,
        array $headers,
        ?string $body = null,
        array $allowedStatus = [200]
    ): array {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('GoogleApiClient: curl init failed');
        }

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $resp = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            throw new \RuntimeException('GoogleApiClient: curl error: ' . $err);
        }

        $decoded = json_decode($resp, true);
        if (!is_array($decoded)) {
            $decoded = ['raw' => $resp];
        }

        if (!in_array($status, $allowedStatus, true)) {
            throw new \RuntimeException("GoogleApiClient: HTTP {$status} {$url} -> " . json_encode($decoded));
        }

        return $decoded;
    }
}
