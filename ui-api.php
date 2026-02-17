<?php
declare(strict_types=1);

use CalendarScheduler\Apply\ApplyOptions;
use CalendarScheduler\Apply\ApplyRunner;
use CalendarScheduler\Apply\ApplyTargets;
use CalendarScheduler\Apply\FppScheduleWriter;
use CalendarScheduler\Apply\ManifestWriter;
use CalendarScheduler\Adapter\Calendar\Google\GoogleApiClient;
use CalendarScheduler\Adapter\Calendar\Google\GoogleApplyExecutor;
use CalendarScheduler\Adapter\Calendar\Google\GoogleConfig;
use CalendarScheduler\Adapter\Calendar\Google\GoogleEventMapper;
use CalendarScheduler\Adapter\Calendar\Google\GoogleOAuthBootstrap;
use CalendarScheduler\Adapter\FppScheduleAdapter;
use CalendarScheduler\Engine\SchedulerEngine;
use CalendarScheduler\Engine\SchedulerRunResult;
use CalendarScheduler\Platform;

header('Content-Type: application/json');

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/src/Platform/FppEnvExporter.php';

const CS_MANIFEST_PATH = '/home/fpp/media/config/calendar-scheduler/manifest.json';
const CS_SCHEDULE_PATH = '/home/fpp/media/config/schedule.json';
const CS_FPP_STAGE_DIR = '/home/fpp/media/config/calendar-scheduler/fpp';
const CS_GOOGLE_CONFIG_DIR = '/home/fpp/media/config/calendar-scheduler/calendar/google';
const CS_FPP_ENV_PATH = '/home/fpp/media/config/calendar-scheduler/runtime/fpp-env.json';

/**
 * @return array<string,mixed>
 */
function cs_read_json_input(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * @param array<string,mixed> $payload
 */
function cs_respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit;
}

function cs_export_fpp_env(): void
{
    $handler = set_error_handler(
        static function (int $severity, string $message, string $file, int $line): bool {
            throw new \RuntimeException("PHP warning: {$message} at {$file}:{$line}");
        }
    );

    try {
        Platform\exportFppEnv(CS_FPP_ENV_PATH);
    } finally {
        restore_error_handler();
    }
}

function cs_run_preview_engine(): SchedulerRunResult
{
    cs_export_fpp_env();

    $engine = new SchedulerEngine();
    return $engine->runFromCli(
        $_SERVER['argv'] ?? [],
        ['refresh-calendar' => true]
    );
}

/**
 * @param array<string,mixed>|null $event
 * @return array<string,mixed>
 */
function cs_normalize_event(?array $event): array
{
    if (!is_array($event)) {
        return [];
    }

    $identity = $event['identity'] ?? [];
    $timing = is_array($identity['timing'] ?? null) ? $identity['timing'] : [];

    $startDate = null;
    if (is_array($timing['start_date'] ?? null)) {
        $startDate = $timing['start_date']['hard'] ?? $timing['start_date']['symbolic'] ?? null;
    }

    $startTime = null;
    if (is_array($timing['start_time'] ?? null)) {
        $startTime = $timing['start_time']['hard'] ?? $timing['start_time']['symbolic'] ?? null;
    }

    return [
        'target' => $identity['target'] ?? null,
        'type' => $identity['type'] ?? null,
        'startDate' => $startDate,
        'startTime' => $startTime,
    ];
}

/**
 * @return array<int,array<string,mixed>>
 */
function cs_actions_for_ui(SchedulerRunResult $result): array
{
    $out = [];
    foreach ($result->actions() as $action) {
        $out[] = [
            'type' => $action->type,
            'target' => $action->target,
            'authority' => $action->authority,
            'identityHash' => $action->identityHash,
            'reason' => $action->reason,
            'event' => cs_normalize_event($action->event),
        ];
    }
    return $out;
}

/**
 * @return array<string,mixed>
 */
function cs_preview_payload(SchedulerRunResult $result): array
{
    return [
        'noop' => $result->isNoop(),
        'generatedAtUtc' => $result->generatedAt()->format(\DateTimeInterface::ATOM),
        'counts' => [
            'fpp' => $result->countsByTarget()['fpp'],
            'calendar' => $result->countsByTarget()['calendar'],
            'total' => $result->totalCounts(),
        ],
        'actions' => cs_actions_for_ui($result),
    ];
}

/**
 * @return array<string,mixed>
 */
function cs_google_status(): array
{
    $base = [
        'connected' => false,
        'selectedCalendarId' => null,
        'authUrl' => null,
        'calendars' => [],
        'account' => 'Not configured',
        'error' => null,
        'setup' => [
            'configPresent' => false,
            'configValid' => false,
            'clientFilePresent' => false,
            'tokenFilePresent' => false,
            'tokenPathWritable' => false,
            'deviceFlowReady' => false,
            'manualFlowReady' => false,
            'hints' => [],
        ],
    ];

    if (!is_dir(CS_GOOGLE_CONFIG_DIR) && !is_file(CS_GOOGLE_CONFIG_DIR)) {
        $base['setup']['hints'][] = 'Google config directory is missing.';
        return $base;
    }
    $base['setup']['configPresent'] = true;

    try {
        $config = new GoogleConfig(CS_GOOGLE_CONFIG_DIR);
        $base['setup']['configValid'] = true;
        $base['selectedCalendarId'] = $config->getCalendarId();

        $clientPath = $config->getClientSecretPath();
        $tokenPath = $config->getTokenPath();
        $base['setup']['clientFilePresent'] = is_file($clientPath);
        $base['setup']['tokenFilePresent'] = is_file($tokenPath);
        $base['setup']['tokenPathWritable'] = is_dir(dirname($tokenPath)) && is_writable(dirname($tokenPath));

        if (!$base['setup']['clientFilePresent']) {
            $base['setup']['hints'][] = "Missing client file: {$clientPath}";
        }
        if (!$base['setup']['tokenPathWritable']) {
            $base['setup']['hints'][] = "Token directory is not writable: " . dirname($tokenPath);
        }

        try {
            $base['authUrl'] = (new GoogleOAuthBootstrap($config))->getAuthorizationUrl();
            $base['setup']['manualFlowReady'] = $base['authUrl'] !== null;
        } catch (\Throwable $e) {
            $base['authUrl'] = null;
            $base['setup']['hints'][] = 'Manual OAuth URL unavailable: ' . $e->getMessage();
        }

        // Device flow is our default path; it only needs valid client config and writable token path.
        $base['setup']['deviceFlowReady'] = $base['setup']['clientFilePresent'] && $base['setup']['tokenPathWritable'];
        if (!$base['setup']['deviceFlowReady']) {
            $base['setup']['hints'][] = 'Device flow not ready: check client file and token directory permissions.';
        }

        $client = new GoogleApiClient($config);
        try {
            $calendarsRaw = $client->listCalendars();
            $calendars = [];
            $account = 'Connected';

            foreach ($calendarsRaw as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $id = isset($item['id']) && is_string($item['id']) ? $item['id'] : null;
                $summary = isset($item['summary']) && is_string($item['summary']) ? $item['summary'] : null;
                if ($id === null || $summary === null) {
                    continue;
                }
                $calendars[] = [
                    'id' => $id,
                    'summary' => $summary,
                    'primary' => (bool) ($item['primary'] ?? false),
                ];
                if (($item['primary'] ?? false) === true) {
                    $account = $summary;
                }
            }

            $base['connected'] = true;
            $base['calendars'] = $calendars;
            $base['account'] = $account;
            $base['error'] = null;
            return $base;
        } catch (\Throwable $e) {
            $base['connected'] = false;
            $base['calendars'] = [];
            $base['account'] = 'Not connected yet';
            $base['error'] = $e->getMessage();
            return $base;
        }
    } catch (\Throwable $e) {
        $base['setup']['hints'][] = 'Invalid Google config: ' . $e->getMessage();
        $base['error'] = $e->getMessage();
        return $base;
    }
}

function cs_set_calendar_id(string $calendarId): void
{
    $path = rtrim(CS_GOOGLE_CONFIG_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'config.json';
    $raw = @file_get_contents($path);
    if ($raw === false) {
        throw new \RuntimeException("Google config not found: {$path}");
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new \RuntimeException("Google config invalid JSON: {$path}");
    }
    $data['calendar_id'] = $calendarId;
    unset($data['calendarId']);

    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new \RuntimeException('Failed to encode Google config JSON');
    }
    if (@file_put_contents($path, $json . "\n") === false) {
        throw new \RuntimeException("Failed to write Google config: {$path}");
    }
}

/**
 * @return array{client_id:string,client_secret:string,scopes:string}
 */
function cs_google_device_auth_config(): array
{
    $config = new GoogleConfig(CS_GOOGLE_CONFIG_DIR);
    $oauth = $config->getOauth();
    $clientPath = $config->getClientSecretPath();

    $raw = @file_get_contents($clientPath);
    if ($raw === false) {
        throw new \RuntimeException("Unable to read Google client file: {$clientPath}");
    }
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        throw new \RuntimeException("Invalid JSON in Google client file: {$clientPath}");
    }

    $clientBlock = $json['web'] ?? $json['installed'] ?? null;
    if (!is_array($clientBlock)) {
        throw new \RuntimeException("Google client file missing 'web' or 'installed' block");
    }

    $clientId = $clientBlock['client_id'] ?? null;
    $clientSecret = $clientBlock['client_secret'] ?? null;
    if (!is_string($clientId) || $clientId === '' || !is_string($clientSecret) || $clientSecret === '') {
        throw new \RuntimeException('Google client_id/client_secret missing');
    }

    $scopes = $oauth['scopes'] ?? [];
    if (!is_array($scopes) || count($scopes) === 0) {
        throw new \RuntimeException('Google oauth.scopes missing');
    }

    return [
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'scopes' => implode(' ', $scopes),
    ];
}

/**
 * @param array<string,string> $form
 * @return array<string,mixed>
 */
function cs_http_post_form_json(string $url, array $form): array
{
    if (!function_exists('curl_init')) {
        throw new \RuntimeException('cURL extension is required for OAuth device flow');
    }

    $ch = curl_init($url);
    if ($ch === false) {
        throw new \RuntimeException('Failed to initialize cURL');
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
        throw new \RuntimeException("OAuth request failed ({$errno}): {$error}");
    }

    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        throw new \RuntimeException("OAuth endpoint returned non-JSON (HTTP {$status})");
    }

    return $decoded;
}

/**
 * @param array<string,mixed> $token
 */
function cs_write_google_token(array $token): void
{
    $config = new GoogleConfig(CS_GOOGLE_CONFIG_DIR);
    $tokenPath = $config->getTokenPath();
    $oauth = $config->getOauth();
    $scopes = $oauth['scopes'] ?? [];
    $scopeValue = is_array($scopes) ? implode(' ', $scopes) : '';

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
        throw new \RuntimeException('Token exchange returned no access_token');
    }

    if ($normalized['refresh_token'] === '' && is_file($tokenPath)) {
        $existing = json_decode((string) @file_get_contents($tokenPath), true);
        if (is_array($existing) && isset($existing['refresh_token']) && is_string($existing['refresh_token'])) {
            $normalized['refresh_token'] = $existing['refresh_token'];
        }
    }

    $json = json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new \RuntimeException('Unable to encode token JSON');
    }

    if (@file_put_contents($tokenPath, $json . "\n") === false) {
        throw new \RuntimeException("Unable to write token file: {$tokenPath}");
    }
    @chmod($tokenPath, 0600);
}

/**
 * @return array<string,mixed>
 */
function cs_google_device_start(): array
{
    $auth = cs_google_device_auth_config();

    $resp = cs_http_post_form_json(
        'https://oauth2.googleapis.com/device/code',
        [
            'client_id' => $auth['client_id'],
            'scope' => $auth['scopes'],
        ]
    );

    if (!isset($resp['device_code'], $resp['user_code'])) {
        $error = is_string($resp['error'] ?? null) ? $resp['error'] : 'unknown_error';
        throw new \RuntimeException("Device auth start failed: {$error}");
    }

    return $resp;
}

/**
 * @return array<string,mixed>
 */
function cs_google_device_poll(string $deviceCode): array
{
    $auth = cs_google_device_auth_config();

    $resp = cs_http_post_form_json(
        'https://oauth2.googleapis.com/token',
        [
            'client_id' => $auth['client_id'],
            'client_secret' => $auth['client_secret'],
            'device_code' => $deviceCode,
            'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
        ]
    );

    if (isset($resp['error'])) {
        $error = is_string($resp['error']) ? $resp['error'] : 'unknown_error';
        if (in_array($error, ['authorization_pending', 'slow_down'], true)) {
            return [
                'status' => 'pending',
                'error' => $error,
            ];
        }
        if (in_array($error, ['access_denied', 'expired_token'], true)) {
            return [
                'status' => 'failed',
                'error' => $error,
            ];
        }
        throw new \RuntimeException("Device auth poll failed: {$error}");
    }

    cs_write_google_token($resp);
    return ['status' => 'connected'];
}

function cs_google_exchange_authorization_code(string $code): void
{
    $config = new GoogleConfig(CS_GOOGLE_CONFIG_DIR);
    $oauth = $config->getOauth();
    $redirectUri = $oauth['redirect_uri'] ?? null;
    if (!is_string($redirectUri) || $redirectUri === '') {
        throw new \RuntimeException('Google oauth.redirect_uri missing');
    }

    $auth = cs_google_device_auth_config();
    $resp = cs_http_post_form_json(
        'https://oauth2.googleapis.com/token',
        [
            'code' => $code,
            'client_id' => $auth['client_id'],
            'client_secret' => $auth['client_secret'],
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ]
    );

    if (isset($resp['error'])) {
        $error = is_string($resp['error']) ? $resp['error'] : 'unknown_error';
        $desc = is_string($resp['error_description'] ?? null) ? $resp['error_description'] : '';
        $suffix = $desc !== '' ? " ({$desc})" : '';
        throw new \RuntimeException("Authorization code exchange failed: {$error}{$suffix}");
    }

    cs_write_google_token($resp);
}

function cs_apply(SchedulerRunResult $result): array
{
    $targets = ApplyTargets::all();
    $options = ApplyOptions::apply($targets, true);

    $googleExecutor = null;
    if (is_dir(CS_GOOGLE_CONFIG_DIR) || is_file(CS_GOOGLE_CONFIG_DIR)) {
        $googleConfig = new GoogleConfig(CS_GOOGLE_CONFIG_DIR);
        $googleClient = new GoogleApiClient($googleConfig);
        $googleMapper = new GoogleEventMapper();
        $googleExecutor = new GoogleApplyExecutor($googleClient, $googleMapper);
    }

    $applier = new ApplyRunner(
        new ManifestWriter(CS_MANIFEST_PATH),
        new FppScheduleAdapter(CS_SCHEDULE_PATH),
        new FppScheduleWriter(CS_SCHEDULE_PATH, CS_FPP_STAGE_DIR),
        $googleExecutor
    );

    $applier->apply($result->reconciliationResult(), $options);

    return $result->totalCounts();
}

try {
    $input = cs_read_json_input();
    $action = $input['action'] ?? $_GET['action'] ?? 'status';
    if (!is_string($action) || $action === '') {
        $action = 'status';
    }

    if ($action === 'status') {
        cs_respond([
            'ok' => true,
            'provider' => 'google',
            'google' => cs_google_status(),
        ]);
    }

    if ($action === 'set_calendar') {
        $calendarId = $input['calendar_id'] ?? '';
        if (!is_string($calendarId) || trim($calendarId) === '') {
            cs_respond(['ok' => false, 'error' => 'calendar_id is required'], 422);
        }
        cs_set_calendar_id(trim($calendarId));
        cs_respond(['ok' => true]);
    }

    if ($action === 'auth_device_start') {
        $resp = cs_google_device_start();
        cs_respond([
            'ok' => true,
            'device' => [
                'device_code' => $resp['device_code'] ?? '',
                'user_code' => $resp['user_code'] ?? '',
                'verification_url' => $resp['verification_url'] ?? ($resp['verification_uri'] ?? ''),
                'verification_url_complete' => $resp['verification_url_complete'] ?? ($resp['verification_uri_complete'] ?? ''),
                'expires_in' => (int) ($resp['expires_in'] ?? 0),
                'interval' => (int) ($resp['interval'] ?? 5),
            ],
        ]);
    }

    if ($action === 'auth_device_poll') {
        $deviceCode = $input['device_code'] ?? '';
        if (!is_string($deviceCode) || trim($deviceCode) === '') {
            cs_respond(['ok' => false, 'error' => 'device_code is required'], 422);
        }
        $poll = cs_google_device_poll(trim($deviceCode));
        cs_respond([
            'ok' => true,
            'poll' => $poll,
        ]);
    }

    if ($action === 'auth_exchange_code') {
        $code = $input['code'] ?? '';
        if (!is_string($code) || trim($code) === '') {
            cs_respond(['ok' => false, 'error' => 'code is required'], 422);
        }
        cs_google_exchange_authorization_code(trim($code));
        cs_respond(['ok' => true]);
    }

    if ($action === 'preview') {
        $runResult = cs_run_preview_engine();
        cs_respond([
            'ok' => true,
            'preview' => cs_preview_payload($runResult),
        ]);
    }

    if ($action === 'apply') {
        $runResult = cs_run_preview_engine();
        $applied = cs_apply($runResult);
        $post = cs_run_preview_engine();
        cs_respond([
            'ok' => true,
            'applied' => $applied,
            'preview' => cs_preview_payload($post),
        ]);
    }

    cs_respond(['ok' => false, 'error' => "Unknown action: {$action}"], 404);
} catch (\Throwable $e) {
    cs_respond([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 500);
}
