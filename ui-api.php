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
use CalendarScheduler\Diff\ReconciliationAction;
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
    if (!is_dir(CS_GOOGLE_CONFIG_DIR) && !is_file(CS_GOOGLE_CONFIG_DIR)) {
        return [
            'connected' => false,
            'selectedCalendarId' => null,
            'authUrl' => null,
            'calendars' => [],
            'account' => 'Not configured',
            'error' => null,
        ];
    }

    $config = new GoogleConfig(CS_GOOGLE_CONFIG_DIR);
    $selectedCalendarId = $config->getCalendarId();

    $authUrl = null;
    try {
        $authUrl = (new GoogleOAuthBootstrap($config))->getAuthorizationUrl();
    } catch (\Throwable $e) {
        $authUrl = null;
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

        return [
            'connected' => true,
            'selectedCalendarId' => $selectedCalendarId,
            'authUrl' => $authUrl,
            'calendars' => $calendars,
            'account' => $account,
            'error' => null,
        ];
    } catch (\Throwable $e) {
        return [
            'connected' => false,
            'selectedCalendarId' => $selectedCalendarId,
            'authUrl' => $authUrl,
            'calendars' => [],
            'account' => 'Not connected yet',
            'error' => $e->getMessage(),
        ];
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
