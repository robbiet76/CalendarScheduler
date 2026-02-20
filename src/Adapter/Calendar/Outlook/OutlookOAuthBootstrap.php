<?php
declare(strict_types=1);

/**
 * Calendar Scheduler — Source Component
 *
 * File: Adapter/Calendar/Outlook/OutlookOAuthBootstrap.php
 * Purpose: Bootstrap token acquisition for Outlook/Microsoft Graph.
 */

namespace CalendarScheduler\Adapter\Calendar\Outlook;

final class OutlookOAuthBootstrap
{
    private OutlookConfig $config;

    public function __construct(OutlookConfig $config)
    {
        $this->config = $config;
    }

    public function runCli(bool $printAuthUrl = false): void
    {
        $authUrl = $this->getAuthorizationUrl();
        if ($printAuthUrl) {
            fwrite(STDOUT, $authUrl . PHP_EOL);
            return;
        }

        fwrite(STDOUT, PHP_EOL);
        fwrite(STDOUT, "=== Outlook OAuth Bootstrap (CLI) ===" . PHP_EOL);
        fwrite(STDOUT, 'Config dir: ' . $this->config->getConfigDir() . PHP_EOL);
        fwrite(STDOUT, 'Token output: ' . $this->config->getTokenPath() . PHP_EOL);
        fwrite(STDOUT, PHP_EOL);
        fwrite(STDOUT, "Open the following URL and complete consent:" . PHP_EOL);
        fwrite(STDOUT, $authUrl . PHP_EOL . PHP_EOL);
        fwrite(STDOUT, "Paste the authorization code here and press Enter:" . PHP_EOL);
        fwrite(STDOUT, "> ");

        $code = trim((string)fgets(STDIN));
        if ($code === '') {
            throw new \RuntimeException('No authorization code provided.');
        }

        $token = $this->exchangeCodeForToken($code);
        $this->writeTokenFile($token);

        fwrite(STDOUT, PHP_EOL . 'SUCCESS: token.json written.' . PHP_EOL);
    }

    public function getAuthorizationUrl(): string
    {
        $oauth = $this->config->getOauth();
        $tenantId = $this->config->getTenantId();

        $clientId = is_string($oauth['client_id'] ?? null) ? trim($oauth['client_id']) : '';
        $redirectUri = is_string($oauth['redirect_uri'] ?? null) ? trim($oauth['redirect_uri']) : '';
        $scopes = is_array($oauth['scopes'] ?? null) ? $oauth['scopes'] : [];

        if ($clientId === '' || $redirectUri === '' || $scopes === []) {
            throw new \RuntimeException('Outlook OAuth config missing client_id, redirect_uri, or scopes.');
        }

        $params = [
            'client_id' => $clientId,
            'response_type' => 'code',
            'redirect_uri' => $redirectUri,
            'response_mode' => 'query',
            'scope' => implode(' ', array_values(array_filter($scopes, 'is_string'))),
        ];

        return 'https://login.microsoftonline.com/' . rawurlencode($tenantId) . '/oauth2/v2.0/authorize'
            . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /** @return array<string,mixed> */
    private function exchangeCodeForToken(string $code): array
    {
        $oauth = $this->config->getOauth();

        $clientId = is_string($oauth['client_id'] ?? null) ? trim($oauth['client_id']) : '';
        $clientSecret = is_string($oauth['client_secret'] ?? null) ? trim($oauth['client_secret']) : '';
        $redirectUri = is_string($oauth['redirect_uri'] ?? null) ? trim($oauth['redirect_uri']) : '';
        $scopes = is_array($oauth['scopes'] ?? null) ? $oauth['scopes'] : [];

        if ($clientId === '' || $clientSecret === '' || $redirectUri === '') {
            throw new \RuntimeException('Outlook OAuth config missing client_id/client_secret/redirect_uri.');
        }

        $scope = implode(' ', array_values(array_filter($scopes, 'is_string')));
        if ($scope === '') {
            $scope = 'https://graph.microsoft.com/.default';
        }

        $tokenUrl = 'https://login.microsoftonline.com/'
            . rawurlencode($this->config->getTenantId())
            . '/oauth2/v2.0/token';

        $form = [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'scope' => $scope,
        ];

        return $this->curlFormJson($tokenUrl, $form);
    }

    /** @param array<string,mixed> $token */
    private function writeTokenFile(array $token): void
    {
        $oauth = $this->config->getOauth();
        $scopes = is_array($oauth['scopes'] ?? null) ? $oauth['scopes'] : [];
        $scopeValue = implode(' ', array_values(array_filter($scopes, 'is_string')));

        $accessToken = is_string($token['access_token'] ?? null) ? trim((string)$token['access_token']) : '';
        if ($accessToken === '') {
            throw new \RuntimeException('Token exchange returned no access_token.');
        }

        $now = time();
        $expiresIn = isset($token['expires_in']) ? (int)$token['expires_in'] : 0;
        $refreshToken = is_string($token['refresh_token'] ?? null) ? $token['refresh_token'] : '';

        $tokenPath = $this->config->getTokenPath();
        if ($refreshToken === '' && is_file($tokenPath)) {
            $existing = json_decode((string)@file_get_contents($tokenPath), true);
            if (is_array($existing) && is_string($existing['refresh_token'] ?? null)) {
                $refreshToken = $existing['refresh_token'];
            }
        }

        $normalized = [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type' => is_string($token['token_type'] ?? null) ? $token['token_type'] : 'Bearer',
            'scope' => is_string($token['scope'] ?? null) ? $token['scope'] : $scopeValue,
            'expires_in' => $expiresIn,
            'expires_at' => $expiresIn > 0 ? ($now + $expiresIn - 30) : 0,
            'created_at' => $now,
        ];

        $json = json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new \RuntimeException('Unable to encode Outlook token JSON.');
        }

        if (@file_put_contents($tokenPath, $json . PHP_EOL) === false) {
            throw new \RuntimeException("Unable to write Outlook token file: {$tokenPath}");
        }
        @chmod($tokenPath, 0600);
    }

    /**
     * @param array<string,string> $form
     * @return array<string,mixed>
     */
    private function curlFormJson(string $url, array $form): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('cURL extension is required for Outlook OAuth bootstrap.');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Failed to initialize cURL for Outlook OAuth bootstrap.');
        }

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form, '', '&', PHP_QUERY_RFC3986));

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $errno !== 0) {
            throw new \RuntimeException("Outlook OAuth bootstrap request failed ({$errno}): {$error}");
        }

        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("Outlook OAuth bootstrap returned non-JSON (HTTP {$status}).");
        }

        if ($status < 200 || $status >= 300 || isset($decoded['error'])) {
            $errorCode = is_string($decoded['error'] ?? null) ? $decoded['error'] : 'oauth_error';
            $errorDescription = is_string($decoded['error_description'] ?? null)
                ? $decoded['error_description']
                : 'Unknown OAuth error';
            throw new \RuntimeException(
                "Outlook OAuth bootstrap token exchange failed (HTTP {$status}, {$errorCode}): {$errorDescription}"
            );
        }

        return $decoded;
    }
}
