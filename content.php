<?php
/**
 * GoogleCalendarScheduler
 * content.php
 *
 * Handles POST actions and renders UI.
 *
 * IMPORTANT FPP RULES:
 * - UI rendering MUST always complete
 * - No redirects
 * - No echo during POST handling
 * - Exceptions must never break UI rendering
 */

require_once __DIR__ . '/src/bootstrap.php';
require_once __DIR__ . '/src/FppSchedulerHorizon.php';

$cfg = GcsConfig::load();

/*
 * --------------------------------------------------------------------
 * POST handling (save / sync)
 * --------------------------------------------------------------------
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = (string)$_POST['action'];

    try {

        /*
         * Save settings
         */
        if ($action === 'save') {
            $cfg['calendar']['ics_url'] = trim($_POST['ics_url'] ?? '');
            $cfg['runtime']['dry_run']  = !empty($_POST['dry_run']);

            GcsConfig::save($cfg);
            $cfg = GcsConfig::load();

            GcsLog::info('Settings saved', [
                'dryRun' => !empty($cfg['runtime']['dry_run']),
            ]);
        }

        /*
         * Sync calendar → scheduler (dry-run or live)
         */
        if ($action === 'sync') {
            $dryRun = !empty($cfg['runtime']['dry_run']);

            GcsLog::info('Starting sync', [
                'dryRun' => $dryRun,
            ]);

            $horizonDays = FppSchedulerHorizon::getDays();
            GcsLog::info('Using FPP scheduler horizon', [
                'days' => $horizonDays,
            ]);

            // Phase 6.6: orchestrated scheduler pipeline
            $runner = new SchedulerRunner($cfg, $horizonDays, $dryRun);
            $result = $runner->run();

            GcsLog::info('Sync completed', $result);
        }

    } catch (Throwable $e) {
        // NEVER let exceptions break UI rendering
        GcsLog::error('GoogleCalendarScheduler error', [
            'error' => $e->getMessage(),
        ]);
    }
}

/*
 * --------------------------------------------------------------------
 * UI rendering
 * --------------------------------------------------------------------
 * Everything below this point is unchanged from cc6f086.
 * This MUST always execute to keep the FPP UI stable.
 */
?>

<!-- ============================= -->
<!-- Google Calendar Scheduler UI -->
<!-- ============================= -->

<div class="settings">
    <h2>Google Calendar Scheduler</h2>

    <form method="post">
        <input type="hidden" name="action" value="save">

        <div class="setting">
            <label for="ics_url"><strong>Google Calendar ICS URL</strong></label><br>
            <input
                type="text"
                id="ics_url"
                name="ics_url"
                size="100"
                value="<?php echo htmlspecialchars($cfg['calendar']['ics_url'] ?? '', ENT_QUOTES); ?>"
            >
        </div>

        <div class="setting">
            <label>
                <input
                    type="checkbox"
                    name="dry_run"
                    <?php if (!empty($cfg['runtime']['dry_run'])) echo 'checked'; ?>
                >
                Dry run (do not modify FPP scheduler)
            </label>
        </div>

        <div class="setting">
            <button type="submit" class="buttons">Save Settings</button>
        </div>
    </form>

    <hr>

    <form method="post">
        <input type="hidden" name="action" value="sync">
        <button type="submit" class="buttons">Sync Calendar</button>
    </form>
</div>
