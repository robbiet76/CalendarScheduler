<?php
declare(strict_types=1);

/**
 * Calendar Scheduler — UI API Endpoint
 *
 * File: ui-api.php
 * Purpose: Expose JSON APIs used by the Calendar Scheduler UI for connection,
 * status, preview, and apply operations.
 */

use CalendarScheduler\Apply\ApplyOptions;
use CalendarScheduler\Apply\ApplyRunner;
use CalendarScheduler\Apply\ApplyTargets;
use CalendarScheduler\Apply\FppScheduleWriter;
use CalendarScheduler\Apply\ManifestWriter;
use CalendarScheduler\Adapter\Calendar\Google\GoogleApiClient;
use CalendarScheduler\Adapter\Calendar\Google\GoogleApplyExecutor;
use CalendarScheduler\Adapter\Calendar\Google\GoogleConfig;
use CalendarScheduler\Adapter\Calendar\Google\GoogleEventMapper;
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
const CS_GOOGLE_DEVICE_CLIENT_FILENAME = 'client_secret_device.json';
const CS_GOOGLE_DEFAULT_REDIRECT_URI = 'http://127.0.0.1:8765/oauth2callback';
const CS_GOOGLE_DEFAULT_SCOPE = 'https://www.googleapis.com/auth/calendar';
const CS_SYNC_MODE_BOTH = 'both';
const CS_SYNC_MODE_CALENDAR = 'calendar';
const CS_SYNC_MODE_FPP = 'fpp';
const CS_SYNC_MODE_META_KEY = 'x-cs-sync-mode';

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
    // Promote warnings to exceptions so export failures are explicit to callers.
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

function cs_run_preview_engine(?string $syncMode = null): SchedulerRunResult
{
    // Always refresh FPP environment before computing a reconciliation preview.
    cs_export_fpp_env();

    $syncMode = cs_normalize_sync_mode($syncMode ?? CS_SYNC_MODE_BOTH);
    $engine = new SchedulerEngine();
    return $engine->runFromCli(
        $_SERVER['argv'] ?? [],
        [
            'refresh-calendar' => true,
            'sync-mode' => $syncMode,
        ]
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

function cs_normalize_sync_mode(mixed $mode): string
{
    if (!is_string($mode)) {
        return CS_SYNC_MODE_BOTH;
    }
    $mode = strtolower(trim($mode));
    if (in_array($mode, [CS_SYNC_MODE_BOTH, CS_SYNC_MODE_CALENDAR, CS_SYNC_MODE_FPP], true)) {
        return $mode;
    }
    return CS_SYNC_MODE_BOTH;
}

/**
 * @param array<int,array<string,mixed>> $actions
 * @return array<int,array<string,mixed>>
 */
function cs_filter_actions_for_sync_mode(array $actions, string $syncMode): array
{
    $syncMode = cs_normalize_sync_mode($syncMode);
    if ($syncMode === CS_SYNC_MODE_BOTH) {
        return $actions;
    }

    // Calendar -> FPP means only FPP-targeted operations are actionable.
    if ($syncMode === CS_SYNC_MODE_CALENDAR) {
        return array_values(array_filter($actions, static function (array $a): bool {
            return ($a['target'] ?? '') === 'fpp';
        }));
    }

    // FPP -> Calendar means only calendar-targeted operations are actionable.
    return array_values(array_filter($actions, static function (array $a): bool {
        return ($a['target'] ?? '') === 'calendar';
    }));
}

function cs_extract_sync_mode_from_description(mixed $description): ?string
{
    if (!is_string($description) || trim($description) === '') {
        return null;
    }
    if (!preg_match('/^\s*' . preg_quote(CS_SYNC_MODE_META_KEY, '/') . '\s*=\s*([a-z_]+)\s*$/mi', $description, $m)) {
        return null;
    }
    $mode = cs_normalize_sync_mode($m[1] ?? '');
    return $mode;
}

function cs_set_sync_mode_in_description(string $description, string $mode): string
{
    $mode = cs_normalize_sync_mode($mode);
    $line = CS_SYNC_MODE_META_KEY . '=' . $mode;
    $pattern = '/^\s*' . preg_quote(CS_SYNC_MODE_META_KEY, '/') . '\s*=\s*[a-z_]+\s*$/mi';

    if (preg_match($pattern, $description) === 1) {
        return (string) preg_replace($pattern, $line, $description, 1);
    }

    $description = rtrim($description);
    if ($description !== '') {
        $description .= "\n\n";
    }
    return $description . $line . "\n";
}

/**
 * @return array<string,mixed>
 */
function cs_preview_payload(SchedulerRunResult $result, string $syncMode): array
{
    $actions = cs_filter_actions_for_sync_mode(cs_actions_for_ui($result), $syncMode);

    $counts = [
        'fpp' => ['created' => 0, 'updated' => 0, 'deleted' => 0],
        'calendar' => ['created' => 0, 'updated' => 0, 'deleted' => 0],
        'total' => ['created' => 0, 'updated' => 0, 'deleted' => 0],
    ];
    foreach ($actions as $action) {
        $type = is_string($action['type'] ?? null) ? $action['type'] : '';
        $target = is_string($action['target'] ?? null) ? $action['target'] : '';
        if (!in_array($type, ['create', 'update', 'delete'], true)) {
            continue;
        }
        if (!in_array($target, ['fpp', 'calendar'], true)) {
            continue;
        }
        $counts[$target][$type . 'd'] = ($counts[$target][$type . 'd'] ?? 0) + 1;
        $counts['total'][$type . 'd'] = ($counts['total'][$type . 'd'] ?? 0) + 1;
    }

    $hasPending = false;
    foreach ($actions as $action) {
        if (($action['type'] ?? '') !== 'noop') {
            $hasPending = true;
            break;
        }
    }

    return [
        'noop' => !$hasPending,
        'generatedAtUtc' => $result->generatedAt()->format(\DateTimeInterface::ATOM),
        'counts' => $counts,
        'actions' => $actions,
        'syncMode' => $syncMode,
    ];
}

/**
 * @return array<string,mixed>
 */
function cs_google_status(): array
{
    // Base payload shape is stable for UI rendering, even when setup is incomplete.
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

    $autoConfigCreated = false;
    try {
        $autoConfigCreated = cs_bootstrap_google_config_if_missing();
    } catch (\Throwable $e) {
        $base['setup']['hints'][] = 'Unable to auto-create Google config: ' . $e->getMessage();
    }
    if ($autoConfigCreated) {
        $base['setup']['hints'][] = 'Created default Google config.json automatically.';
    }

    if (!is_dir(CS_GOOGLE_CONFIG_DIR) && !is_file(CS_GOOGLE_CONFIG_DIR)) {
        $base['setup']['hints'][] = 'Google config directory is missing.';
        return $base;
    }
    $base['setup']['configPresent'] = true;

    try {
        $config = new GoogleConfig(CS_GOOGLE_CONFIG_DIR);
        $base['setup']['configValid'] = true;
        $base['selectedCalendarId'] = $config->getCalendarId();

        $deviceClientPath = $config->getClientSecretPath();
        $tokenPath = $config->getTokenPath();
        $base['setup']['clientFilePresent'] = is_file($deviceClientPath);
        $base['setup']['tokenFilePresent'] = is_file($tokenPath);
        $base['setup']['tokenPathWritable'] = is_dir(dirname($tokenPath)) && is_writable(dirname($tokenPath));

        if (!$base['setup']['clientFilePresent']) {
            $base['setup']['hints'][] = "Missing device client file: {$deviceClientPath}";
        }
        if (!$base['setup']['tokenPathWritable']) {
            $base['setup']['hints'][] = "Token directory is not writable: " . dirname($tokenPath);
        }

        // Manual OAuth callback flow is optional fallback; device flow is primary.
        $base['authUrl'] = null;
        $base['setup']['manualFlowReady'] = false;

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
                $accessRole = isset($item['accessRole']) && is_string($item['accessRole'])
                    ? $item['accessRole']
                    : '';
                // Limit selectable calendars to owner-level calendars only.
                if ($accessRole !== 'owner') {
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

function cs_get_sync_mode(): string
{
    $config = cs_read_google_config_json();
    $calendarId = $config['calendar_id'] ?? $config['calendarId'] ?? 'primary';
    $calendarId = is_string($calendarId) && trim($calendarId) !== '' ? $calendarId : 'primary';
    try {
        $googleConfig = new GoogleConfig(CS_GOOGLE_CONFIG_DIR);
        $googleClient = new GoogleApiClient($googleConfig);
        $calendar = $googleClient->getCalendar($calendarId);
        $description = $calendar['description'] ?? null;
        $mode = cs_extract_sync_mode_from_description($description);
        if ($mode !== null) {
            return $mode;
        }
    } catch (\Throwable $e) {
        // Fallback to local default if calendar metadata cannot be fetched.
    }

    return cs_normalize_sync_mode($config['sync_mode'] ?? null);
}

function cs_set_sync_mode(string $mode): void
{
    $mode = cs_normalize_sync_mode($mode);
    $config = cs_read_google_config_json();

    $calendarId = $config['calendar_id'] ?? $config['calendarId'] ?? 'primary';
    $calendarId = is_string($calendarId) && trim($calendarId) !== '' ? $calendarId : 'primary';
    $googleConfig = new GoogleConfig(CS_GOOGLE_CONFIG_DIR);
    $googleClient = new GoogleApiClient($googleConfig);
    $calendar = $googleClient->getCalendar($calendarId);
    $description = is_string($calendar['description'] ?? null) ? $calendar['description'] : '';
    $updatedDescription = cs_set_sync_mode_in_description($description, $mode);
    $googleClient->patchCalendar($calendarId, [
        'description' => $updatedDescription,
    ]);

    // Keep top-level sync_mode for backward compatibility and default fallback.
    $config['sync_mode'] = $mode;
    cs_write_google_config_json($config);
}

function cs_bootstrap_google_config_if_missing(): bool
{
    // Auto-bootstrap first-run config so UI setup can be completed without SSH.
    if (!is_dir(CS_GOOGLE_CONFIG_DIR)) {
        if (!@mkdir(CS_GOOGLE_CONFIG_DIR, 0775, true) && !is_dir(CS_GOOGLE_CONFIG_DIR)) {
            throw new \RuntimeException('Failed to create Google config directory: ' . CS_GOOGLE_CONFIG_DIR);
        }
    }

    $path = rtrim(CS_GOOGLE_CONFIG_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'config.json';
    if (is_file($path)) {
        // Migrate legacy oauth.client_file key away from runtime config.
        $raw = @file_get_contents($path);
        $existing = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($existing)) {
            $oauth = is_array($existing['oauth'] ?? null) ? $existing['oauth'] : [];
            $changed = false;
            $syncMode = cs_normalize_sync_mode($existing['sync_mode'] ?? null);
            if (($existing['sync_mode'] ?? null) !== $syncMode) {
                $existing['sync_mode'] = $syncMode;
                $changed = true;
            }
            if (array_key_exists('sync_mode_by_calendar', $existing)) {
                unset($existing['sync_mode_by_calendar']);
                $changed = true;
            }

            if (array_key_exists('client_file', $oauth)) {
                unset($oauth['client_file']);
                $changed = true;
            }
            $configuredDeviceFile = $oauth['device_client_file'] ?? null;
            if (!is_string($configuredDeviceFile) || trim($configuredDeviceFile) === '') {
                $oauth['device_client_file'] = CS_GOOGLE_DEVICE_CLIENT_FILENAME;
                $changed = true;
            } else {
                // Normalize to canonical filename to avoid accumulating uploaded secrets.
                $configuredDeviceFile = basename(trim($configuredDeviceFile));
                if ($configuredDeviceFile !== CS_GOOGLE_DEVICE_CLIENT_FILENAME) {
                    $oldPath = rtrim(CS_GOOGLE_CONFIG_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $configuredDeviceFile;
                    $newPath = rtrim(CS_GOOGLE_CONFIG_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . CS_GOOGLE_DEVICE_CLIENT_FILENAME;
                    if (is_file($oldPath)) {
                        $rawClient = @file_get_contents($oldPath);
                        if (is_string($rawClient) && @file_put_contents($newPath, $rawClient) !== false) {
                            @chmod($newPath, 0600);
                            @unlink($oldPath);
                        }
                    }
                    $oauth['device_client_file'] = CS_GOOGLE_DEVICE_CLIENT_FILENAME;
                    $changed = true;
                }
            }

            if ($changed) {
                $existing['oauth'] = $oauth;
                $json = json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                if (is_string($json)) {
                    @file_put_contents($path, $json . "\n");
                }
            }
        }
        return false;
    }

    $config = [
        'provider' => 'google',
        'calendar_id' => 'primary',
        'sync_mode' => CS_SYNC_MODE_BOTH,
        'oauth' => [
            'device_client_file' => CS_GOOGLE_DEVICE_CLIENT_FILENAME,
            'token_file' => 'token.json',
            'redirect_uri' => CS_GOOGLE_DEFAULT_REDIRECT_URI,
            'scopes' => [CS_GOOGLE_DEFAULT_SCOPE],
        ],
    ];

    $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new \RuntimeException('Failed to encode default Google config JSON.');
    }
    if (@file_put_contents($path, $json . "\n") === false) {
        throw new \RuntimeException('Failed to write default Google config: ' . $path);
    }
    return true;
}

function cs_set_calendar_id(string $calendarId): void
{
    cs_bootstrap_google_config_if_missing();
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
 * @return array<string,mixed>
 */
function cs_read_google_config_json(): array
{
    cs_bootstrap_google_config_if_missing();
    $path = rtrim(CS_GOOGLE_CONFIG_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'config.json';
    $raw = @file_get_contents($path);
    if ($raw === false) {
        throw new \RuntimeException("Google config not found: {$path}");
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new \RuntimeException("Google config invalid JSON: {$path}");
    }
    return $data;
}

/**
 * @param array<string,mixed> $data
 */
function cs_write_google_config_json(array $data): void
{
    $path = rtrim(CS_GOOGLE_CONFIG_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'config.json';
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new \RuntimeException('Failed to encode Google config JSON');
    }
    if (@file_put_contents($path, $json . "\n") === false) {
        throw new \RuntimeException("Failed to write Google config: {$path}");
    }
}

function cs_google_upload_device_client(string $filename, string $jsonText): string
{
    // Accept uploaded OAuth client JSON and always overwrite canonical device client file.
    $filename = CS_GOOGLE_DEVICE_CLIENT_FILENAME;

    $payload = json_decode($jsonText, true);
    if (!is_array($payload)) {
        throw new \RuntimeException('Uploaded file is not valid JSON.');
    }

    $clientBlock = null;
    if (isset($payload['installed']) && is_array($payload['installed'])) {
        $clientBlock = $payload['installed'];
    } elseif (isset($payload['web']) && is_array($payload['web'])) {
        $clientBlock = $payload['web'];
    }
    if (!is_array($clientBlock)) {
        throw new \RuntimeException("OAuth JSON must contain an 'installed' or 'web' client block.");
    }

    $clientId = $clientBlock['client_id'] ?? null;
    $clientSecret = $clientBlock['client_secret'] ?? null;
    if (!is_string($clientId) || $clientId === '' || !is_string($clientSecret) || $clientSecret === '') {
        throw new \RuntimeException('OAuth JSON is missing client_id/client_secret.');
    }

    $targetPath = rtrim(CS_GOOGLE_CONFIG_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;
    $normalized = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($normalized) || @file_put_contents($targetPath, $normalized . "\n") === false) {
        throw new \RuntimeException("Failed to write uploaded OAuth JSON: {$targetPath}");
    }
    @chmod($targetPath, 0600);

    $config = cs_read_google_config_json();
    $oauth = is_array($config['oauth'] ?? null) ? $config['oauth'] : [];
    $oauth['device_client_file'] = $filename;
    $config['oauth'] = $oauth;
    cs_write_google_config_json($config);

    // Prune older uploaded client secret files to avoid secret accumulation.
    $pattern = rtrim(CS_GOOGLE_CONFIG_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'client_secret*.json';
    $candidates = glob($pattern) ?: [];
    foreach ($candidates as $candidate) {
        if (!is_string($candidate)) {
            continue;
        }
        if (basename($candidate) === CS_GOOGLE_DEVICE_CLIENT_FILENAME) {
            continue;
        }
        @unlink($candidate);
    }

    return $filename;
}

/**
 * @return array{client_id:string,client_secret:string,scopes:string,client_path:string,client_type:string}
 */
function cs_google_device_auth_config(): array
{
    cs_bootstrap_google_config_if_missing();
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

    $clientType = 'unknown';
    if (isset($json['installed']) && is_array($json['installed'])) {
        $clientType = 'installed';
        $clientBlock = $json['installed'];
    } elseif (isset($json['web']) && is_array($json['web'])) {
        $clientType = 'web';
        $clientBlock = $json['web'];
    } else {
        $clientBlock = null;
    }
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
        'client_path' => $clientPath,
        'client_type' => $clientType,
    ];
}

/**
 * @return array{client_id:string,client_secret:string,redirect_uri:string}
 */
function cs_google_manual_auth_config(): array
{
    cs_bootstrap_google_config_if_missing();
    $config = new GoogleConfig(CS_GOOGLE_CONFIG_DIR);
    $oauth = $config->getOauth();
    $redirectUri = $oauth['redirect_uri'] ?? null;
    if (!is_string($redirectUri) || $redirectUri === '') {
        throw new \RuntimeException('Google oauth.redirect_uri missing');
    }

    $clientPath = $config->getClientSecretPath();
    $raw = @file_get_contents($clientPath);
    if ($raw === false) {
        throw new \RuntimeException("Unable to read Google client file: {$clientPath}");
    }
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        throw new \RuntimeException("Invalid JSON in Google client file: {$clientPath}");
    }

    $clientBlock = $json['web'] ?? null;
    if (!is_array($clientBlock)) {
        throw new \RuntimeException(
            "Manual OAuth requires a 'web' OAuth client in {$clientPath}"
        );
    }

    $clientId = $clientBlock['client_id'] ?? null;
    $clientSecret = $clientBlock['client_secret'] ?? null;
    if (!is_string($clientId) || $clientId === '' || !is_string($clientSecret) || $clientSecret === '') {
        throw new \RuntimeException('Google OAuth client_id/client_secret missing');
    }

    return [
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri' => $redirectUri,
    ];
}

/**
 * @param array<string,string> $form
 * @return array<string,mixed>
 */
function cs_http_post_form_json(string $url, array $form): array
{
    // Shared HTTP helper for OAuth device code/token exchanges.
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
    // Normalize token payload and preserve refresh_token across partial responses.
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
    $auth = cs_google_manual_auth_config();
    $resp = cs_http_post_form_json(
        'https://oauth2.googleapis.com/token',
        [
            'code' => $code,
            'client_id' => $auth['client_id'],
            'client_secret' => $auth['client_secret'],
            'redirect_uri' => $auth['redirect_uri'],
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

function cs_google_disconnect(): void
{
    cs_bootstrap_google_config_if_missing();
    $config = new GoogleConfig(CS_GOOGLE_CONFIG_DIR);
    $tokenPath = $config->getTokenPath();
    if (is_file($tokenPath)) {
        if (!@unlink($tokenPath)) {
            throw new \RuntimeException("Unable to remove token file: {$tokenPath}");
        }
    }
}

function cs_apply(SchedulerRunResult $result, ?string $syncMode = null): array
{
    $syncMode = cs_normalize_sync_mode($syncMode ?? cs_get_sync_mode());
    if ($syncMode === CS_SYNC_MODE_CALENDAR) {
        $targets = [ApplyTargets::TARGET_FPP];
    } elseif ($syncMode === CS_SYNC_MODE_FPP) {
        $targets = [ApplyTargets::TARGET_CALENDAR];
    } else {
        $targets = ApplyTargets::all();
    }
    $options = ApplyOptions::apply($targets, false);

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
    // Action dispatch for all UI-facing operations.
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
            'syncMode' => cs_get_sync_mode(),
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

    if ($action === 'set_sync_mode') {
        $syncMode = $input['sync_mode'] ?? '';
        if (!is_string($syncMode) || trim($syncMode) === '') {
            cs_respond(['ok' => false, 'error' => 'sync_mode is required'], 422);
        }
        cs_set_sync_mode($syncMode);
        cs_respond([
            'ok' => true,
            'syncMode' => cs_get_sync_mode(),
        ]);
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

    if ($action === 'auth_disconnect') {
        cs_google_disconnect();
        cs_respond(['ok' => true]);
    }

    if ($action === 'auth_upload_device_client') {
        $filename = $input['filename'] ?? CS_GOOGLE_DEVICE_CLIENT_FILENAME;
        $json = $input['json'] ?? '';
        if (!is_string($filename)) {
            $filename = CS_GOOGLE_DEVICE_CLIENT_FILENAME;
        }
        if (!is_string($json) || trim($json) === '') {
            cs_respond(['ok' => false, 'error' => 'json is required'], 422);
        }
        $stored = cs_google_upload_device_client($filename, $json);
        cs_respond([
            'ok' => true,
            'stored' => $stored,
        ]);
    }

    if ($action === 'preview') {
        $syncMode = cs_get_sync_mode();
        $runResult = cs_run_preview_engine($syncMode);
        cs_respond([
            'ok' => true,
            'preview' => cs_preview_payload($runResult, $syncMode),
        ]);
    }

    if ($action === 'apply') {
        $syncMode = cs_get_sync_mode();
        $runResult = cs_run_preview_engine($syncMode);
        $applied = cs_apply($runResult, $syncMode);
        $post = cs_run_preview_engine($syncMode);
        cs_respond([
            'ok' => true,
            'applied' => $applied,
            'preview' => cs_preview_payload($post, $syncMode),
        ]);
    }

    cs_respond(['ok' => false, 'error' => "Unknown action: {$action}"], 404);
} catch (\Throwable $e) {
    cs_respond([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 500);
}
