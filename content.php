<?php
/**
 * GoogleCalendarScheduler
 * content.php
 *
 * This file is included inside /opt/fpp/www/plugin.php AFTER headers.
 * Any fatal or exit() here will blank the page.
 *
 * Therefore:
 * - Never allow sync code to terminate execution
 * - Catch *everything*
 */

require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/FppSchedulerHorizon.php';
require_once __DIR__ . '/src/SchedulerSync.php';

$cfg = GcsConfig::load();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = (string)$_POST['action'];

    try {
        if ($action === 'save') {
            $cfg['calendar']['ics_url'] = trim($_POST['ics_url'] ?? '');
            $cfg['runtime']['dry_run']  = !empty($_POST['dry_run']);

            GcsConfig::save($cfg);
            $cfg = GcsConfig::load();

            GcsLog::info('Settings saved', [
                'dryRun' => !empty($cfg['runtime']['dry_run']),
            ]);
        }

        if ($action === 'sync') {
            $dryRun = !empty($cfg['runtime']['dry_run']);

            GcsLog::info('Starting sync', ['dryRun' => $dryRun]);

            $horizonDays = FppSchedulerHorizon::getDays();
            GcsLog::info('Using FPP scheduler horizon', ['days' => $horizonDays]);

            // CRITICAL: isolate sync execution
            $sync = new SchedulerSync($cfg, $horizonDays, $dryRun);
            $result = $sync->run();

            GcsLog::info('Sync completed', $result);

            $cfg = GcsConfig::load();
        }
    }
    catch (Throwable $e) {
        // This prevents a blank page
        GcsLog::error('Sync crashed', [
            'exception' => get_class($e),
            'message'   => $e->getMessage(),
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
        ]);
    }
}

// ALWAYS render UI
require __DIR__ . '/src/content_main.php';
