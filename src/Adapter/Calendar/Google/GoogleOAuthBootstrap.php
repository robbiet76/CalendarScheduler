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
 *  - Receive auth code via user paste-back (OOB flow)
 *  - Exchange code for tokens
 *  - Persist token.json
 *
 * Notes:
 *  - This does NOT read/write calendar events.
 *  - This does NOT touch Manifest/Diff/Apply logic.
 *  - Uses an out-of-band (OOB) paste-back flow for OAuth consent.
 */
final class GoogleOAuthBootstrap
{
    private const DEFAULT_CONFIG_DIR = '/home/fpp/media/config/calendar-scheduler/calendar/google';
    private const CLIENT_SECRET_FILE = 'client_secret.json';
    private const TOKEN_FILE = 'token.json';

    // OAuth endpoints (Google)
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    // Out-of-band redirect URI for OAuth consent
    private const REDIRECT_URI = 'urn:ietf:wg:oauth:2.0:oob';

    // Calendar scope (read/write)
    private const SCOPE = 'https://www.googleapis.com/auth/calendar';

    /**
     * Keep signature stable even if we don't need config yet.
     * (We may later use it for calendar_id selection / config.json coordination.)
     */
    private GoogleConfig $config;

    public function __construct(GoogleConfig $config)
    {
        $this->config = $config;
    }

    public function run(): void
    {
        $configDir = getenv('CS_GOOGLE_CONFIG_DIR') ?: self::DEFAULT_CONFIG_DIR;
        $clientSecretPath = rtrim($configDir, '/') . '/' . self::CLIENT_SECRET_FILE;
        $tokenPath = rtrim($configDir, '/') . '/' . self::TOKEN_FILE;

        if (!is_file($clientSecretPath)) {
            fwrite(STDERR, "ERROR: Missing client secret: {$clientSecretPath}\n");
            fwrite(STDERR, "Copy your Google OAuth client json to that path.\n");
            exit(1);
        }

        $clientSecret = $this->loadJsonFile($clientSecretPath);
        [$clientId, $clientSecretValue] = $this->extractClientCredentials($clientSecret);

        $authUrl = $this->buildAuthUrl($clientId);

        fwrite(STDERR, "\n=== Google OAuth Bootstrap (CLI) ===\n");
        fwrite(STDERR, "Config dir: {$configDir}\n");
        fwrite(STDERR, "Client secret: {$clientSecretPath}\n");
        fwrite(STDERR, "Token output: {$tokenPath}\n\n");
        fwrite(STDERR, "1) Open this URL in a browser and complete consent:\n\n");
        fwrite(STDERR, $authUrl . "\n\n");
        fwrite(STDERR, "2) Paste the authorization code here, then press Enter:\n> ");
        $code = trim(fgets(STDIN) ?: '');

        if ($code === '') {
            fwrite(STDERR, "ERROR: No authorization code received.\n");
            exit(1);
        }

        $token = $this->exchangeCodeForToken($clientId, $clientSecretValue, $code);

        // Normalize token payload for runtime usage
        $now = time();
        $expiresIn = isset($token['expires_in']) ? (int) $token['expires_in'] : 0;
        $normalized = [
            'access_token' => (string) ($token['access_token'] ?? ''),
            'refresh_token' => (string) ($token['refresh_token'] ?? ''),
            'token_type' => (string) ($token['token_type'] ?? 'Bearer'),
            'scope' => (string) ($token['scope'] ?? self::SCOPE),
            'expires_in' => $expiresIn,
            'expires_at' => $expiresIn > 0 ? ($now + $expiresIn - 30) : 0, // subtract small safety buffer
            'created_at' => $now,
        ];

        if ($normalized['access_token'] === '') {
            fwrite(STDERR, "ERROR: Token exchange returned no access_token.\n");
            fwrite(STDERR, json_encode($token, JSON_PRETTY_PRINT) . "\n");
            exit(1);
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

        fwrite(STDERR, "\nSUCCESS: token.json written.\n");
        fwrite(STDERR, "Next: run `php bin/calendar-scheduler --refresh-calendar`\n\n");
        exit(0);
    }

    private function buildAuthUrl(string $clientId): string
    {
        $params = [
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT_URI,
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
        ];
        return self::AUTH_URL . '?' . http_build_query($params);
    }

    /**
     * Supports both "installed" and "web" client_secret.json shapes.
     *
     * @return array{0:string,1:string} [client_id, client_secret]
     */
    private function extractClientCredentials(array $clientSecret): array
    {
        $root = null;
        if (isset($clientSecret['installed']) && is_array($clientSecret['installed'])) {
            $root = $clientSecret['installed'];
        } elseif (isset($clientSecret['web']) && is_array($clientSecret['web'])) {
            $root = $clientSecret['web'];
        } else {
            $root = $clientSecret;
        }

        $clientId = (string) ($root['client_id'] ?? '');
        $clientSecretValue = (string) ($root['client_secret'] ?? '');

        if ($clientId === '' || $clientSecretValue === '') {
            fwrite(STDERR, "ERROR: client_secret.json missing client_id/client_secret.\n");
            exit(1);
        }

        return [$clientId, $clientSecretValue];
    }

    private function exchangeCodeForToken(string $clientId, string $clientSecretValue, string $code): array
    {
        $post = [
            'code' => $code,
            'client_id' => $clientId,
            'client_secret' => $clientSecretValue,
            'redirect_uri' => self::REDIRECT_URI,
            'grant_type' => 'authorization_code',
        ];

        $resp = $this->curlJson(self::TOKEN_URL, $post);
        if (!is_array($resp)) {
            fwrite(STDERR, "ERROR: token endpoint did not return JSON.\n");
            exit(1);
        }

        if (isset($resp['error'])) {
            $msg = is_string($resp['error']) ? $resp['error'] : 'unknown_error';
            $desc = isset($resp['error_description']) && is_string($resp['error_description']) ? $resp['error_description'] : '';
            fwrite(STDERR, "ERROR: token exchange failed: {$msg}\n");
            if ($desc !== '') {
                fwrite(STDERR, $desc . "\n");
            }
            exit(1);
        }

        return $resp;
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
