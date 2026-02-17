<?php
declare(strict_types=1);

/**
 * Calendar Scheduler — OAuth Callback Endpoint (Fallback Flow)
 *
 * Purpose:
 * - Handle browser-based OAuth authorization-code callback.
 * - Exchange code for tokens and persist token.json.
 * - Notify opener window and close callback tab/window.
 *
 * Note:
 * - Device flow is the primary UX path.
 * - This endpoint remains as a compatibility/fallback path.
 */

require_once __DIR__ . '/bootstrap.php';

use CalendarScheduler\Adapter\Calendar\Google\GoogleConfig;

const CS_GOOGLE_CONFIG_DIR = '/home/fpp/media/config/calendar-scheduler/calendar/google';

/**
 * @return array{client_id:string,client_secret:string,redirect_uri:string,token_path:string,scopes:string}
 */
function cs_oauth_callback_config(): array
{
    $config = new GoogleConfig(CS_GOOGLE_CONFIG_DIR);
    $oauth = $config->getOauth();

    $redirectUri = $oauth['redirect_uri'] ?? null;
    if (!is_string($redirectUri) || $redirectUri === '') {
        throw new RuntimeException('oauth.redirect_uri missing in Google config');
    }

    $clientPath = $config->getClientSecretPath();
    $raw = @file_get_contents($clientPath);
    if ($raw === false) {
        throw new RuntimeException("Unable to read Google client file: {$clientPath}");
    }
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        throw new RuntimeException("Invalid JSON in Google client file: {$clientPath}");
    }

    $clientBlock = $json['web'] ?? $json['installed'] ?? null;
    if (!is_array($clientBlock)) {
        throw new RuntimeException("Google client file missing 'web' or 'installed' block");
    }

    $clientId = $clientBlock['client_id'] ?? null;
    $clientSecret = $clientBlock['client_secret'] ?? null;
    if (!is_string($clientId) || $clientId === '' || !is_string($clientSecret) || $clientSecret === '') {
        throw new RuntimeException('Google client_id/client_secret missing');
    }

    $scopes = $oauth['scopes'] ?? [];
    if (!is_array($scopes)) {
        $scopes = [];
    }

    return [
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri' => $redirectUri,
        'token_path' => $config->getTokenPath(),
        'scopes' => implode(' ', $scopes),
    ];
}

/**
 * @param array<string,string> $form
 * @return array<string,mixed>
 */
function cs_oauth_http_post_form_json(string $url, array $form): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('cURL extension is required for OAuth callback');
    }

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Failed to initialize cURL');
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form, '', '&', PHP_QUERY_RFC3986));

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $errno !== 0) {
        throw new RuntimeException("OAuth request failed ({$errno}): {$error}");
    }

    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("OAuth endpoint returned non-JSON (HTTP {$status})");
    }

    return $decoded;
}

/**
 * @param array<string,mixed> $token
 */
function cs_oauth_write_token(array $token, array $cfg): void
{
    $tokenPath = $cfg['token_path'];
    $scopeValue = $cfg['scopes'];

    $now = time();
    $expiresIn = isset($token['expires_in']) ? (int) $token['expires_in'] : 0;
    $normalized = [
        'access_token' => (string) ($token['access_token'] ?? ''),
        'refresh_token' => (string) ($token['refresh_token'] ?? ''),
        'token_type' => (string) ($token['token_type'] ?? 'Bearer'),
        'scope' => (string) ($token['scope'] ?? $scopeValue),
        'expires_in' => $expiresIn,
        'expires_at' => $expiresIn > 0 ? ($now + $expiresIn - 30) : 0,
        'created_at' => $now,
    ];

    if ($normalized['access_token'] === '') {
        throw new RuntimeException('Token exchange returned no access_token');
    }

    if ($normalized['refresh_token'] === '' && is_file($tokenPath)) {
        $existing = json_decode((string) @file_get_contents($tokenPath), true);
        if (is_array($existing) && isset($existing['refresh_token']) && is_string($existing['refresh_token'])) {
            $normalized['refresh_token'] = $existing['refresh_token'];
        }
    }

    $json = json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('Unable to encode token JSON');
    }

    if (@file_put_contents($tokenPath, $json . "\n") === false) {
        throw new RuntimeException("Unable to write token file: {$tokenPath}");
    }
    @chmod($tokenPath, 0600);
}

function cs_oauth_render_page(string $title, string $message, string $status): never
{
    $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $safeStatus = htmlspecialchars($status, ENT_QUOTES, 'UTF-8');
    header('Content-Type: text/html; charset=utf-8');
    echo "<!doctype html><html><head><meta charset=\"utf-8\"><title>{$safeTitle}</title></head><body>";
    echo "<h2>{$safeTitle}</h2><p>{$safeMessage}</p>";
    echo "<p>You may close this window.</p>";
    echo "<script>
      try {
        if (window.opener && !window.opener.closed) {
          window.opener.postMessage({ type: 'cs_oauth_complete', status: '{$safeStatus}' }, '*');
        }
      } catch (e) {}
      setTimeout(function(){ try { window.close(); } catch (e) {} }, 200);
    </script>";
    echo "</body></html>";
    exit;
}

try {
    // Provider-side callback failures are surfaced directly to the user.
    if (isset($_GET['error'])) {
        $error = (string) $_GET['error'];
        cs_oauth_render_page('OAuth Failed', "Authorization failed: {$error}", 'failed');
    }

    // A missing code indicates an incomplete or invalid callback redirect.
    $code = isset($_GET['code']) ? trim((string) $_GET['code']) : '';
    if ($code === '') {
        cs_oauth_render_page('OAuth Error', 'Missing authorization code in callback.', 'failed');
    }

    // Exchange callback code for tokens using the configured OAuth client.
    $cfg = cs_oauth_callback_config();
    $token = cs_oauth_http_post_form_json(
        'https://oauth2.googleapis.com/token',
        [
            'code' => $code,
            'client_id' => $cfg['client_id'],
            'client_secret' => $cfg['client_secret'],
            'redirect_uri' => $cfg['redirect_uri'],
            'grant_type' => 'authorization_code',
        ]
    );

    if (isset($token['error'])) {
        $err = is_string($token['error']) ? $token['error'] : 'unknown_error';
        $desc = is_string($token['error_description'] ?? null) ? $token['error_description'] : '';
        $suffix = $desc !== '' ? " ({$desc})" : '';
        cs_oauth_render_page('OAuth Failed', "Token exchange failed: {$err}{$suffix}", 'failed');
    }

    // Persist normalized token payload for API/CLI calendar operations.
    cs_oauth_write_token($token, $cfg);
    cs_oauth_render_page('OAuth Complete', 'Google authentication succeeded.', 'success');
} catch (Throwable $e) {
    cs_oauth_render_page('OAuth Error', $e->getMessage(), 'failed');
}
