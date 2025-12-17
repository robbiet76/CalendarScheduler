<?php
/**
 * GoogleCalendarScheduler
 * Content entry point for FPP
 *
 * This file:
 * - Handles POST actions if present
 * - Always renders the UI
 *
 * IMPORTANT:
 * Do NOT add $menu guards or redirects in this FPP version.
 */

require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/FppSchedulerHorizon.php';
require_once __DIR__ . '/src/SchedulerSync.php';

// Handle POST actions (Save / Sync)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'save') {
        $cfg = GcsConfig::load();

        $cfg['calendar']['ics_url'] = trim($_POST['ics_url'] ?? '');
        $cfg['runtime']['dry_run']  = !empty($_POST['dry_run']);

        GcsConfig::save($cfg);

        GcsLog::info('Settings saved', [
            'dryRun' => $cfg['runtime']['dry_run'],
        ]);
    }

    if ($action === 'sync') {
        $cfg = GcsConfig::load();
        $dryRun = !empty($cfg['runtime']['dry_run']);

        GcsLog::info('Starting sync', ['dryRun' => $dryRun]);

        $horizonDays = FppSchedulerHorizon::getDays();
        GcsLog::info('Using FPP scheduler horizon', ['days' => $horizonDays]);

        $sync = new SchedulerSync($cfg, $horizonDays, $dryRun);
        $result = $sync->run();

        GcsLog::info('Sync completed', $result);
    }
}

// ALWAYS render UI
require_once __DIR__ . '/src/content_main.php';
