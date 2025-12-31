<?php
declare(strict_types=1);

/**
 * POST action handler (controller-only).
 *
 * RESPONSIBILITIES:
 * - Handle POSTed actions from content.php
 * - Save configuration
 * - Trigger scheduler sync (dry-run or live)
 *
 * HARD RULES:
 * - MUST NOT render UI
 * - MUST NOT echo output
 * - MUST NOT perform GET routing
 *
 * NOTE:
 * - All side effects (writes) are intentional and explicit here
 */

require_once __DIR__ . '/bootstrap.php';

$action = $_POST['action'] ?? '';

/*
 * --------------------------------------------------------------------
 * Save settings
 * --------------------------------------------------------------------
 */
if ($action === 'save') {
    $cfg = SchedulerConfig::load();

    $cfg['calendar']['ics_url'] = trim($_POST['ics_url'] ?? '');
    $cfg['runtime']['dry_run']  = !empty($_POST['dry_run']);

    SchedulerConfig::save($cfg);

    SchedulerLog::info('Settings saved', [
        'dryRun' => $cfg['runtime']['dry_run'],
    ]);
}

/*
 * --------------------------------------------------------------------
 * Run scheduler sync (dry-run or live)
 * --------------------------------------------------------------------
 */
if ($action === 'sync') {
    $cfg    = SchedulerConfig::load();
    $dryRun = !empty($cfg['runtime']['dry_run']);

    SchedulerLog::info('Starting scheduler sync', [
        'dryRun' => $dryRun,
        'mode'   => $dryRun ? 'dry-run' : 'live',
    ]);

    /*
     * Horizon:
     * - Fixed, non-configurable
     * - Passed only to satisfy runner constructor
     * - Planning scope is owned by SchedulerPlanner
     */
    $runner = new SchedulerRunner(
        $cfg,
        365,
        $dryRun
    );

    $result = $runner->run();

    SchedulerLog::info(
        'Scheduler sync completed',
        array_merge(
            $result,
            [
                'dryRun' => $dryRun,
                'mode'   => $dryRun ? 'dry-run' : 'live',
            ]
        )
    );
}

/*
 * Explicit termination:
 * - Prevent accidental output
 * - Prevent fall-through execution
 */
return;
