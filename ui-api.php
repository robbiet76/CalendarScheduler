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
use CalendarScheduler\Adapter\Calendar\Outlook\OutlookApiClient;
use CalendarScheduler\Adapter\Calendar\Outlook\OutlookApplyExecutor;
use CalendarScheduler\Adapter\Calendar\Outlook\OutlookConfig;
use CalendarScheduler\Adapter\Calendar\Outlook\OutlookEventMapper;
use CalendarScheduler\Adapter\Calendar\Outlook\OutlookOAuthBootstrap;
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
const CS_OUTLOOK_CONFIG_DIR = '/home/fpp/media/config/calendar-scheduler/calendar/outlook';
const CS_FPP_ENV_PATH = '/home/fpp/media/config/calendar-scheduler/runtime/fpp-env.json';
const CS_GOOGLE_DEVICE_CLIENT_FILENAME = 'client_secret_device.json';
const CS_GOOGLE_DEFAULT_REDIRECT_URI = 'http://127.0.0.1:8765/oauth2callback';
const CS_GOOGLE_DEFAULT_SCOPE = 'https://www.googleapis.com/auth/calendar';
const CS_OUTLOOK_DEFAULT_REDIRECT_URI = 'http://127.0.0.1:8765/oauth2callback';
const CS_OUTLOOK_DEFAULT_SCOPE = 'offline_access openid profile User.Read Calendars.ReadWrite';
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

/**
 * @param array<string,mixed> $details
 */
function cs_respond_error(
    string $error,
    int $status = 500,
    ?string $hint = null,
    string $code = 'api_error',
    array $details = []
): void {
    $payload = [
        'ok' => false,
        'error' => $error,
        'code' => $code,
    ];
    if (is_string($hint) && trim($hint) !== '') {
        $payload['hint'] = $hint;
    }
    if ($details !== []) {
        $payload['details'] = $details;
    }
    cs_respond($payload, $status);
}

function cs_hint_for_exception(\Throwable $e, string $action = ''): ?string
{
    $message = strtolower($e->getMessage());
    if (str_contains($message, 'device auth start failed: invalid_client')) {
        return 'Upload a valid TV and Limited Input OAuth client JSON, then try Connect Provider again.';
    }
    if (str_contains($message, 'device auth poll failed')) {
        return 'Retry authorization on google.com/device using the latest code, then poll again.';
    }
    if (str_contains($message, 'token file') || str_contains($message, 'token directory')) {
        return 'Ensure the Google config folder is writable and reconnect the provider.';
    }
    if (str_contains($message, 'google config') || str_contains($message, 'client file')) {
        return 'Open Connection Setup checks, upload client secret JSON, and confirm all checks show OK.';
    }
    if (str_contains($message, 'outlook oauth') || str_contains($message, 'microsoftonline')) {
        return 'Verify Outlook OAuth client_id/client_secret/tenant_id/redirect_uri settings and retry.';
    }
    if ($action === 'preview' || $action === 'apply') {
        return 'Open Diagnostics and verify provider connection, selected calendar, and setup checks.';
    }
    return null;
}

function cs_generate_correlation_id(): string
{
    try {
        $suffix = substr(bin2hex(random_bytes(4)), 0, 8);
    } catch (\Throwable) {
        $suffix = substr(md5((string) microtime(true)), 0, 8);
    }
    return gmdate('YmdHis') . '-' . $suffix;
}

function cs_log_correlated_error(string $action, \Throwable $e, string $correlationId): void
{
    $line = sprintf(
        '%s action=%s correlation_id=%s error=%s',
        gmdate('c'),
        $action,
        $correlationId,
        $e->getMessage()
    );

    error_log(sprintf(
        '[CalendarScheduler] action=%s correlation_id=%s error=%s',
        $action,
        $correlationId,
        $e->getMessage()
    ));

    // Keep an explicit plugin log trail even when PHP/webserver error_log routing differs by host.
    @file_put_contents('/home/fpp/media/logs/CalendarScheduler.log', $line . PHP_EOL, FILE_APPEND);
}

/**
 * @return array<string,array<string,int>>
 */
function cs_empty_counts(): array
{
    return [
        'fpp' => ['created' => 0, 'updated' => 0, 'deleted' => 0],
        'calendar' => ['created' => 0, 'updated' => 0, 'deleted' => 0],
        'total' => ['created' => 0, 'updated' => 0, 'deleted' => 0],
    ];
}

/**
 * @param array<int,array<string,mixed>> $actions
 * @return array<string,mixed>
 */
function cs_pending_summary(array $actions): array
{
    $summary = [
        'totalPending' => 0,
        'byType' => ['create' => 0, 'update' => 0, 'delete' => 0],
        'byTarget' => ['fpp' => 0, 'calendar' => 0],
        'sample' => [],
    ];

    foreach ($actions as $action) {
        $type = is_string($action['type'] ?? null) ? strtolower((string) $action['type']) : '';
        $target = is_string($action['target'] ?? null) ? strtolower((string) $action['target']) : '';
        if (!in_array($type, ['create', 'update', 'delete'], true)) {
            continue;
        }

        $summary['totalPending']++;
        $summary['byType'][$type] = ($summary['byType'][$type] ?? 0) + 1;
        if (isset($summary['byTarget'][$target])) {
            $summary['byTarget'][$target] = ($summary['byTarget'][$target] ?? 0) + 1;
        }

        if (count($summary['sample']) < 5) {
            $summary['sample'][] = [
                'type' => $type,
                'target' => $target,
                'identityHash' => is_string($action['identityHash'] ?? null) ? $action['identityHash'] : null,
                'reason' => is_string($action['reason'] ?? null) ? $action['reason'] : null,
            ];
        }
    }

    return $summary;
}

/**
 * @return array<string,mixed>
 */
function cs_diagnostics_payload(?string $requestedSyncMode = null): array
{
    $syncMode = cs_normalize_sync_mode($requestedSyncMode ?? cs_get_sync_mode());
    $google = cs_google_status();
    $lastError = is_string($google['error'] ?? null) && trim((string) $google['error']) !== ''
        ? (string) $google['error']
        : null;
    $selectedCalendarId = is_string($google['selectedCalendarId'] ?? null)
        ? (string) $google['selectedCalendarId']
        : null;

    $counts = cs_empty_counts();
    $pendingSummary = cs_pending_summary([]);
    $previewGeneratedAtUtc = null;

    try {
        $runResult = cs_run_preview_engine($syncMode);
        $preview = cs_preview_payload($runResult, $syncMode);
        $counts = is_array($preview['counts'] ?? null) ? $preview['counts'] : $counts;
        $actions = is_array($preview['actions'] ?? null) ? $preview['actions'] : [];
        $pendingSummary = cs_pending_summary($actions);
        $previewGeneratedAtUtc = is_string($preview['generatedAtUtc'] ?? null) ? $preview['generatedAtUtc'] : null;
    } catch (\Throwable $e) {
        if ($lastError === null || trim($lastError) === '') {
            $lastError = $e->getMessage();
        }
    }

    return [
        'syncMode' => $syncMode,
        'selectedCalendarId' => $selectedCalendarId,
        'counts' => $counts,
        'pendingSummary' => $pendingSummary,
        'lastError' => $lastError,
        'previewGeneratedAtUtc' => $previewGeneratedAtUtc,
    ];
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
 * @param array<string,mixed> $manifest
 * @return array<string,array<string,mixed>>
 */
function cs_index_manifest_events(array $manifest): array
{
    $indexed = [];
    $events = is_array($manifest['events'] ?? null) ? $manifest['events'] : [];
    foreach ($events as $key => $event) {
        if (is_array($event) && is_string($key) && $key !== '') {
            $indexed[$key] = $event;
            continue;
        }
        if (!is_array($event)) {
            continue;
        }
        $id = $event['identityHash'] ?? $event['id'] ?? null;
        if (is_string($id) && $id !== '') {
            $indexed[$id] = $event;
        }
    }

    return $indexed;
}

/**
 * @return array<int,array<string,mixed>>
 */
function cs_actions_for_ui(SchedulerRunResult $result): array
{
    $out = [];
    $currentManifestEvents = cs_index_manifest_events($result->currentManifest());
    foreach ($result->actions() as $action) {
        $manifestEvent = $currentManifestEvents[$action->identityHash] ?? null;
        $rawEvent = is_array($action->event) ? $action->event : [];
        $rawIdentity = is_array($rawEvent['identity'] ?? null) ? $rawEvent['identity'] : [];
        $out[] = [
            'type' => $action->type,
            'target' => $action->target,
            'authority' => $action->authority,
            'identityHash' => $action->identityHash,
            'reason' => $action->reason,
            // Keep minimal event summary for UI table fallback only.
            'event' => [
                'target' => $rawIdentity['target'] ?? $rawEvent['target'] ?? null,
                'type' => $rawIdentity['type'] ?? $rawEvent['type'] ?? null,
            ],
            'manifestEvent' => is_array($manifestEvent) ? $manifestEvent : null,
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

function cs_get_calendar_provider(): string
{
    $googleConfigPath = rtrim(CS_GOOGLE_CONFIG_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'config.json';
    if (is_file($googleConfigPath)) {
        $raw = @file_get_contents($googleConfigPath);
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $provider = strtolower((string)($decoded['provider'] ?? 'google'));
                if ($provider === 'google' || $provider === 'outlook') {
                    return $provider;
                }
            }
        }
    }

    $outlookConfigPath = rtrim(CS_OUTLOOK_CONFIG_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'config.json';
    if (is_file($outlookConfigPath)) {
        $raw = @file_get_contents($outlookConfigPath);
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && strtolower((string)($decoded['provider'] ?? 'outlook')) === 'outlook') {
                return 'outlook';
            }
        }
    }

    return 'google';
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

/**
 * @return array<string,mixed>
 */
function cs_outlook_status(): array
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
            'tokenFilePresent' => false,
            'tokenPathWritable' => false,
            'oauthConfigured' => false,
            'hints' => [],
        ],
    ];

    $autoConfigCreated = false;
    try {
        $autoConfigCreated = cs_bootstrap_outlook_config_if_missing();
    } catch (\Throwable $e) {
        $base['setup']['hints'][] = 'Unable to auto-create Outlook config: ' . $e->getMessage();
    }
    if ($autoConfigCreated) {
        $base['setup']['hints'][] = 'Created default Outlook config.json automatically.';
    }

    if (!is_dir(CS_OUTLOOK_CONFIG_DIR) && !is_file(CS_OUTLOOK_CONFIG_DIR)) {
        $base['setup']['hints'][] = 'Outlook config directory is missing.';
        return $base;
    }
    $base['setup']['configPresent'] = true;

    try {
        $config = new OutlookConfig(CS_OUTLOOK_CONFIG_DIR);
        $oauth = $config->getOauth();
        $base['setup']['configValid'] = true;
        $base['selectedCalendarId'] = $config->getCalendarId();

        $clientId = is_string($oauth['client_id'] ?? null) ? trim((string)$oauth['client_id']) : '';
        $clientSecret = is_string($oauth['client_secret'] ?? null) ? trim((string)$oauth['client_secret']) : '';
        $redirectUri = is_string($oauth['redirect_uri'] ?? null) ? trim((string)$oauth['redirect_uri']) : '';
        $scopes = is_array($oauth['scopes'] ?? null) ? $oauth['scopes'] : [];
        $base['setup']['oauthConfigured'] = $clientId !== '' && $clientSecret !== '' && $redirectUri !== '' && $scopes !== [];
        if ($base['setup']['oauthConfigured']) {
            $base['authUrl'] = (new OutlookOAuthBootstrap($config))->getAuthorizationUrl();
        }

        $tokenPath = $config->getTokenPath();
        $base['setup']['tokenFilePresent'] = is_file($tokenPath);
        $base['setup']['tokenPathWritable'] = is_dir(dirname($tokenPath)) && is_writable(dirname($tokenPath));

        if (!$base['setup']['oauthConfigured']) {
            $base['setup']['hints'][] = 'Outlook OAuth settings are incomplete.';
        }
        if (!$base['setup']['tokenPathWritable']) {
            $base['setup']['hints'][] = 'Token directory is not writable: ' . dirname($tokenPath);
        }

        $client = new OutlookApiClient($config);
        try {
            $calendarsRaw = $client->listCalendars();
            $calendars = [];
            foreach ($calendarsRaw as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $id = is_string($item['id'] ?? null) ? $item['id'] : null;
                $name = is_string($item['name'] ?? null) ? $item['name'] : null;
                if ($id === null || $name === null) {
                    continue;
                }
                $calendars[] = [
                    'id' => $id,
                    'summary' => $name,
                    'primary' => false,
                ];
            }
            $base['connected'] = true;
            $base['calendars'] = $calendars;
            $base['account'] = 'Connected';
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
        $base['setup']['hints'][] = 'Invalid Outlook config: ' . $e->getMessage();
        $base['error'] = $e->getMessage();
        return $base;
    }
}

function cs_bootstrap_outlook_config_if_missing(): bool
{
    if (!is_dir(CS_OUTLOOK_CONFIG_DIR)) {
        if (!@mkdir(CS_OUTLOOK_CONFIG_DIR, 0775, true) && !is_dir(CS_OUTLOOK_CONFIG_DIR)) {
            throw new \RuntimeException('Failed to create Outlook config directory: ' . CS_OUTLOOK_CONFIG_DIR);
        }
    }

    $path = rtrim(CS_OUTLOOK_CONFIG_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'config.json';
    if (is_file($path)) {
        return false;
    }

    $config = [
        'provider' => 'outlook',
        'calendar_id' => 'primary',
        'oauth' => [
            'tenant_id' => 'common',
            'client_id' => '',
            'client_secret' => '',
            'token_file' => 'token.json',
            'redirect_uri' => CS_OUTLOOK_DEFAULT_REDIRECT_URI,
            'scopes' => explode(' ', CS_OUTLOOK_DEFAULT_SCOPE),
        ],
    ];

    $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new \RuntimeException('Failed to encode default Outlook config JSON.');
    }
    if (@file_put_contents($path, $json . "\n") === false) {
        throw new \RuntimeException('Failed to write default Outlook config: ' . $path);
    }

    return true;
}

/**
 * @return array<string,mixed>
 */
function cs_read_outlook_config_json(): array
{
    cs_bootstrap_outlook_config_if_missing();
    $path = rtrim(CS_OUTLOOK_CONFIG_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'config.json';
    $raw = @file_get_contents($path);
    if ($raw === false) {
        throw new \RuntimeException("Outlook config not found: {$path}");
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new \RuntimeException("Outlook config invalid JSON: {$path}");
    }
    return $data;
}

/**
 * @param array<string,mixed> $data
 */
function cs_write_outlook_config_json(array $data): void
{
    $path = rtrim(CS_OUTLOOK_CONFIG_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'config.json';
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new \RuntimeException('Failed to encode Outlook config JSON');
    }
    if (@file_put_contents($path, $json . "\n") === false) {
        throw new \RuntimeException("Failed to write Outlook config: {$path}");
    }
}

/**
 * @param array<string,mixed> $input
 */
function cs_outlook_save_oauth_config(array $input): void
{
    $config = cs_read_outlook_config_json();
    $oauth = is_array($config['oauth'] ?? null) ? $config['oauth'] : [];

    $oauth['tenant_id'] = is_string($input['tenant_id'] ?? null) && trim((string)$input['tenant_id']) !== ''
        ? trim((string)$input['tenant_id'])
        : ($oauth['tenant_id'] ?? 'common');
    $oauth['client_id'] = is_string($input['client_id'] ?? null) ? trim((string)$input['client_id']) : ($oauth['client_id'] ?? '');
    $oauth['client_secret'] = is_string($input['client_secret'] ?? null) ? trim((string)$input['client_secret']) : ($oauth['client_secret'] ?? '');
    $oauth['redirect_uri'] = is_string($input['redirect_uri'] ?? null) && trim((string)$input['redirect_uri']) !== ''
        ? trim((string)$input['redirect_uri'])
        : ($oauth['redirect_uri'] ?? CS_OUTLOOK_DEFAULT_REDIRECT_URI);
    $oauth['token_file'] = is_string($input['token_file'] ?? null) && trim((string)$input['token_file']) !== ''
        ? trim((string)$input['token_file'])
        : ($oauth['token_file'] ?? 'token.json');

    $scopes = $input['scopes'] ?? null;
    if (is_string($scopes)) {
        $parts = preg_split('/\s+/', trim($scopes)) ?: [];
        $scopes = array_values(array_filter($parts, static fn ($v): bool => is_string($v) && $v !== ''));
    }
    if (is_array($scopes) && $scopes !== []) {
        $oauth['scopes'] = array_values(array_filter($scopes, static fn ($v): bool => is_string($v) && trim($v) !== ''));
    } elseif (!is_array($oauth['scopes'] ?? null) || $oauth['scopes'] === []) {
        $oauth['scopes'] = explode(' ', CS_OUTLOOK_DEFAULT_SCOPE);
    }

    if (is_string($input['calendar_id'] ?? null) && trim((string)$input['calendar_id']) !== '') {
        $config['calendar_id'] = trim((string)$input['calendar_id']);
    }

    $config['provider'] = 'outlook';
    $config['oauth'] = $oauth;
    cs_write_outlook_config_json($config);
}

function cs_extract_authorization_code(string $codeOrUrl): string
{
    $candidate = trim($codeOrUrl);
    if ($candidate === '') {
        return '';
    }

    if (preg_match('/^https?:\\/\\//i', $candidate) === 1) {
        $query = parse_url($candidate, PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            parse_str($query, $params);
            $code = $params['code'] ?? '';
            if (is_string($code) && trim($code) !== '') {
                return trim($code);
            }
        }
    }

    return $candidate;
}

function cs_outlook_exchange_authorization_code(string $codeOrUrl): void
{
    cs_bootstrap_outlook_config_if_missing();
    $code = cs_extract_authorization_code($codeOrUrl);
    if ($code === '') {
        throw new \RuntimeException('Authorization code is missing.');
    }

    $config = new OutlookConfig(CS_OUTLOOK_CONFIG_DIR);
    $oauth = $config->getOauth();

    $clientId = is_string($oauth['client_id'] ?? null) ? trim((string)$oauth['client_id']) : '';
    $clientSecret = is_string($oauth['client_secret'] ?? null) ? trim((string)$oauth['client_secret']) : '';
    $redirectUri = is_string($oauth['redirect_uri'] ?? null) ? trim((string)$oauth['redirect_uri']) : '';
    $scopes = is_array($oauth['scopes'] ?? null) ? $oauth['scopes'] : [];
    $scope = implode(' ', array_values(array_filter($scopes, 'is_string')));
    if ($scope === '') {
        $scope = CS_OUTLOOK_DEFAULT_SCOPE;
    }

    if ($clientId === '' || $clientSecret === '' || $redirectUri === '') {
        throw new \RuntimeException('Outlook OAuth config missing client_id/client_secret/redirect_uri.');
    }

    $tokenUrl = 'https://login.microsoftonline.com/' . rawurlencode($config->getTenantId()) . '/oauth2/v2.0/token';
    $resp = cs_http_post_form_json(
        $tokenUrl,
        [
            'code' => $code,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
            'scope' => $scope,
        ]
    );

    if (isset($resp['error'])) {
        $error = is_string($resp['error']) ? $resp['error'] : 'unknown_error';
        $desc = is_string($resp['error_description'] ?? null) ? $resp['error_description'] : '';
        $suffix = $desc !== '' ? " ({$desc})" : '';
        throw new \RuntimeException("Outlook authorization code exchange failed: {$error}{$suffix}");
    }

    $now = time();
    $expiresIn = isset($resp['expires_in']) ? (int)$resp['expires_in'] : 0;
    $token = [
        'access_token' => (string)($resp['access_token'] ?? ''),
        'refresh_token' => (string)($resp['refresh_token'] ?? ''),
        'token_type' => (string)($resp['token_type'] ?? 'Bearer'),
        'scope' => (string)($resp['scope'] ?? $scope),
        'expires_in' => $expiresIn,
        'expires_at' => $expiresIn > 0 ? ($now + $expiresIn - 30) : 0,
        'created_at' => $now,
    ];

    if ($token['access_token'] === '') {
        throw new \RuntimeException('Outlook token exchange returned no access_token.');
    }

    $tokenPath = $config->getTokenPath();
    if ($token['refresh_token'] === '' && is_file($tokenPath)) {
        $existing = json_decode((string) @file_get_contents($tokenPath), true);
        if (is_array($existing) && is_string($existing['refresh_token'] ?? null)) {
            $token['refresh_token'] = $existing['refresh_token'];
        }
    }

    $json = json_encode($token, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || @file_put_contents($tokenPath, $json . "\n") === false) {
        throw new \RuntimeException("Unable to write Outlook token file: {$tokenPath}");
    }
    @chmod($tokenPath, 0600);
}

function cs_outlook_disconnect(): void
{
    cs_bootstrap_outlook_config_if_missing();
    $config = new OutlookConfig(CS_OUTLOOK_CONFIG_DIR);
    $tokenPath = $config->getTokenPath();
    if (is_file($tokenPath) && !@unlink($tokenPath)) {
        throw new \RuntimeException("Unable to remove token file: {$tokenPath}");
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

function cs_get_ui_pref_bool(string $key, bool $default = false): bool
{
    $config = cs_read_google_config_json();
    $ui = is_array($config['ui'] ?? null) ? $config['ui'] : [];
    $value = $ui[$key] ?? null;
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value)) {
        return $value !== 0;
    }
    if (is_string($value)) {
        $v = strtolower(trim($value));
        return in_array($v, ['1', 'true', 'yes', 'on'], true);
    }
    return $default;
}

function cs_set_ui_pref_bool(string $key, bool $value): void
{
    $config = cs_read_google_config_json();
    $ui = is_array($config['ui'] ?? null) ? $config['ui'] : [];
    $ui[$key] = $value;
    $config['ui'] = $ui;
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

function cs_set_outlook_calendar_id(string $calendarId): void
{
    cs_bootstrap_outlook_config_if_missing();
    $path = rtrim(CS_OUTLOOK_CONFIG_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'config.json';
    $raw = @file_get_contents($path);
    if ($raw === false) {
        throw new \RuntimeException("Outlook config not found: {$path}");
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new \RuntimeException("Outlook config invalid JSON: {$path}");
    }
    $data['calendar_id'] = $calendarId;
    unset($data['calendarId']);

    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new \RuntimeException('Failed to encode Outlook config JSON');
    }
    if (@file_put_contents($path, $json . "\n") === false) {
        throw new \RuntimeException("Failed to write Outlook config: {$path}");
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
    $code = cs_extract_authorization_code($code);
    if ($code === '') {
        throw new \RuntimeException('Authorization code is missing.');
    }

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

    // Fail closed in one-way sync modes: never allow opposite-side executable writes.
    if ($syncMode !== CS_SYNC_MODE_BOTH) {
        $allowed = array_fill_keys($targets, true);
        foreach ($result->reconciliationResult()->executableActions() as $action) {
            if (!isset($allowed[$action->target])) {
                throw new \RuntimeException(
                    "Apply safety stop: action target '{$action->target}' is not allowed for sync mode '{$syncMode}'."
                );
            }
        }
    }

    $options = ApplyOptions::apply($targets, false);

    $provider = cs_get_calendar_provider();
    $googleExecutor = null;
    $outlookExecutor = null;

    if ($provider === 'outlook') {
        if (is_dir(CS_OUTLOOK_CONFIG_DIR) || is_file(CS_OUTLOOK_CONFIG_DIR)) {
            $outlookConfig = new OutlookConfig(CS_OUTLOOK_CONFIG_DIR);
            $outlookClient = new OutlookApiClient($outlookConfig);
            $outlookMapper = new OutlookEventMapper();
            $outlookExecutor = new OutlookApplyExecutor($outlookClient, $outlookMapper);
        }
    } else {
        if (is_dir(CS_GOOGLE_CONFIG_DIR) || is_file(CS_GOOGLE_CONFIG_DIR)) {
            $googleConfig = new GoogleConfig(CS_GOOGLE_CONFIG_DIR);
            $googleClient = new GoogleApiClient($googleConfig);
            $googleMapper = new GoogleEventMapper();
            $googleExecutor = new GoogleApplyExecutor($googleClient, $googleMapper);
        }
    }

    $applier = new ApplyRunner(
        new ManifestWriter(CS_MANIFEST_PATH),
        new FppScheduleAdapter(CS_SCHEDULE_PATH),
        new FppScheduleWriter(CS_SCHEDULE_PATH, CS_FPP_STAGE_DIR),
        $googleExecutor,
        $outlookExecutor,
        $provider
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
        $provider = cs_get_calendar_provider();
        $google = cs_google_status();
        $outlook = cs_outlook_status();
        $syncMode = CS_SYNC_MODE_BOTH;
        $connectionCollapsed = false;
        try {
            $syncMode = cs_get_sync_mode();
        } catch (\Throwable $e) {
            // Keep status available even when config/bootstrap is not writable yet.
            $google['error'] = is_string($google['error'] ?? null) && trim((string) $google['error']) !== ''
                ? $google['error']
                : $e->getMessage();
            $hints = is_array($google['setup']['hints'] ?? null) ? $google['setup']['hints'] : [];
            $hints[] = 'Unable to read sync mode from config; using default mode: both.';
            $google['setup']['hints'] = $hints;
        }
        try {
            $connectionCollapsed = cs_get_ui_pref_bool('connection_collapsed', false);
        } catch (\Throwable $e) {
            $hints = is_array($google['setup']['hints'] ?? null) ? $google['setup']['hints'] : [];
            $hints[] = 'Unable to read UI preferences from config; using defaults.';
            $google['setup']['hints'] = $hints;
        }
        cs_respond([
            'ok' => true,
            'provider' => $provider,
            'google' => $google,
            'outlook' => $outlook,
            'syncMode' => cs_normalize_sync_mode($syncMode),
            'ui' => [
                'connectionCollapsed' => $connectionCollapsed,
            ],
        ]);
    }

    if ($action === 'diagnostics') {
        $syncMode = cs_normalize_sync_mode($input['sync_mode'] ?? cs_get_sync_mode());
        cs_respond([
            'ok' => true,
            'diagnostics' => cs_diagnostics_payload($syncMode),
        ]);
    }

    if ($action === 'set_calendar') {
        $calendarId = $input['calendar_id'] ?? '';
        if (!is_string($calendarId) || trim($calendarId) === '') {
            cs_respond_error(
                'calendar_id is required',
                422,
                'Select a calendar first, then retry.',
                'validation_error',
                ['field' => 'calendar_id']
            );
        }
        $provider = cs_get_calendar_provider();
        if ($provider === 'outlook') {
            cs_set_outlook_calendar_id(trim($calendarId));
        } else {
            cs_set_calendar_id(trim($calendarId));
        }
        cs_respond(['ok' => true]);
    }

    if ($action === 'set_sync_mode') {
        $syncMode = $input['sync_mode'] ?? '';
        if (!is_string($syncMode) || trim($syncMode) === '') {
            cs_respond_error(
                'sync_mode is required',
                422,
                'Choose Calendar -> FPP, FPP -> Calendar, or Two-way Merge.',
                'validation_error',
                ['field' => 'sync_mode']
            );
        }
        cs_set_sync_mode($syncMode);
        cs_respond([
            'ok' => true,
            'syncMode' => cs_get_sync_mode(),
        ]);
    }

    if ($action === 'set_ui_pref') {
        $key = $input['key'] ?? '';
        if (!is_string($key) || trim($key) === '') {
            cs_respond_error(
                'key is required',
                422,
                'Provide the UI preference key.',
                'validation_error',
                ['field' => 'key']
            );
        }
        $key = trim($key);
        if ($key !== 'connection_collapsed') {
            cs_respond_error(
                'unsupported key',
                422,
                'Only connection_collapsed is currently supported.',
                'validation_error',
                ['field' => 'key', 'allowed' => ['connection_collapsed']]
            );
        }
        $rawValue = $input['value'] ?? false;
        $value = false;
        if (is_bool($rawValue)) {
            $value = $rawValue;
        } elseif (is_int($rawValue)) {
            $value = $rawValue !== 0;
        } elseif (is_string($rawValue)) {
            $value = in_array(strtolower(trim($rawValue)), ['1', 'true', 'yes', 'on'], true);
        }
        cs_set_ui_pref_bool($key, $value);
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
            cs_respond_error(
                'device_code is required',
                422,
                'Start device auth first, then provide the returned device_code.',
                'validation_error',
                ['field' => 'device_code']
            );
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
            cs_respond_error(
                'code is required',
                422,
                'Paste the full callback URL or authorization code.',
                'validation_error',
                ['field' => 'code']
            );
        }
        $provider = cs_get_calendar_provider();
        if ($provider === 'outlook') {
            cs_outlook_exchange_authorization_code(trim($code));
        } else {
            cs_google_exchange_authorization_code(trim($code));
        }
        cs_respond(['ok' => true]);
    }

    if ($action === 'auth_disconnect') {
        $provider = cs_get_calendar_provider();
        if ($provider === 'outlook') {
            cs_outlook_disconnect();
        } else {
            cs_google_disconnect();
        }
        cs_respond(['ok' => true]);
    }

    if ($action === 'auth_outlook_save_config') {
        cs_outlook_save_oauth_config($input);
        cs_respond(['ok' => true]);
    }

    if ($action === 'auth_outlook_authorize_url') {
        cs_bootstrap_outlook_config_if_missing();
        $config = new OutlookConfig(CS_OUTLOOK_CONFIG_DIR);
        $bootstrap = new OutlookOAuthBootstrap($config);
        cs_respond([
            'ok' => true,
            'auth_url' => $bootstrap->getAuthorizationUrl(),
        ]);
    }

    if ($action === 'set_provider') {
        $provider = is_string($input['provider'] ?? null) ? strtolower(trim((string)$input['provider'])) : '';
        if ($provider !== 'google' && $provider !== 'outlook') {
            cs_respond_error(
                'provider is required',
                422,
                'Set provider to google or outlook.',
                'validation_error',
                ['field' => 'provider', 'allowed' => ['google', 'outlook']]
            );
        }

        if ($provider === 'outlook') {
            cs_bootstrap_outlook_config_if_missing();
            cs_bootstrap_google_config_if_missing();
            $googleConfig = cs_read_google_config_json();
            $googleConfig['provider'] = 'outlook';
            cs_write_google_config_json($googleConfig);
        } else {
            cs_bootstrap_google_config_if_missing();
            $googleConfig = cs_read_google_config_json();
            $googleConfig['provider'] = 'google';
            cs_write_google_config_json($googleConfig);
        }
        cs_respond(['ok' => true]);
    }

    if ($action === 'auth_upload_device_client') {
        $filename = $input['filename'] ?? CS_GOOGLE_DEVICE_CLIENT_FILENAME;
        $json = $input['json'] ?? '';
        if (!is_string($filename)) {
            $filename = CS_GOOGLE_DEVICE_CLIENT_FILENAME;
        }
        if (!is_string($json) || trim($json) === '') {
            cs_respond_error(
                'json is required',
                422,
                'Upload the OAuth client secret JSON file content.',
                'validation_error',
                ['field' => 'json']
            );
        }
        $stored = cs_google_upload_device_client($filename, $json);
        cs_respond([
            'ok' => true,
            'stored' => $stored,
        ]);
    }

    if ($action === 'preview') {
        $syncMode = cs_normalize_sync_mode($input['sync_mode'] ?? cs_get_sync_mode());
        $runResult = cs_run_preview_engine($syncMode);
        cs_respond([
            'ok' => true,
            'preview' => cs_preview_payload($runResult, $syncMode),
        ]);
    }

    if ($action === 'apply') {
        $syncMode = cs_normalize_sync_mode($input['sync_mode'] ?? cs_get_sync_mode());
        $runResult = cs_run_preview_engine($syncMode);
        $applied = cs_apply($runResult, $syncMode);
        $post = cs_run_preview_engine($syncMode);
        cs_respond([
            'ok' => true,
            'applied' => $applied,
            'preview' => cs_preview_payload($post, $syncMode),
        ]);
    }

    cs_respond_error(
        "Unknown action: {$action}",
        404,
        'Use one of: status, diagnostics, preview, apply, auth_device_start, auth_device_poll, auth_exchange_code, auth_disconnect, auth_outlook_save_config, auth_outlook_authorize_url, set_provider.',
        'unknown_action',
        ['action' => $action]
    );
} catch (\Throwable $e) {
    $actionName = is_string($action ?? null) ? $action : 'unknown';
    $correlationId = null;
    if (
        $actionName === 'apply'
        || str_starts_with($actionName, 'auth_')
    ) {
        $correlationId = cs_generate_correlation_id();
        cs_log_correlated_error($actionName, $e, $correlationId);
    }

    $details = ['action' => $actionName];
    if ($correlationId !== null) {
        $details['correlationId'] = $correlationId;
    }
    cs_respond_error(
        $e->getMessage(),
        500,
        cs_hint_for_exception($e, $actionName),
        'runtime_error',
        $details
    );
}
