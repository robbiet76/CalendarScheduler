<?php

require_once __DIR__ . '/bootstrap.php';

/*
 * IMPORTANT:
 * FPP does not reliably support redirect-after-POST for plugin pages.
 * We must handle the action and then render the content page directly.
 */

$action = $_POST['action'] ?? '';

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

/*
 * Render the plugin UI directly after handling POST.
 * This guarantees the UI remains visible.
 */
require __DIR__ . '/content_main.php';
