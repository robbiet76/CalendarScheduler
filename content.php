<?php
/**
 * GoogleCalendarScheduler
 * content.php
 *
 * Handles POST actions and renders UI.
 */

require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/FppSchedulerHorizon.php';

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

            // ✅ NEW: run full scheduler pipeline
            $runner = new SchedulerRunner($cfg, $horizonDays, $dryRun);
            $result = $runner->run();

            GcsLog::info('Sync completed', $result);
        }

    } catch (Throwable $e) {
        GcsLog::error('Sync failed', [
            'error' => $e->getMessage(),
        ]);
    }
}
