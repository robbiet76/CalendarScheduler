<?php
/**
 * GoogleCalendarScheduler
 * content.php
 *
 * Handles POST actions and renders UI.
 *
 * IMPORTANT:
 *  - All POST actions are delegated to src/api_main.php
 *  - No scheduler logic lives here
 *  - UI rendering continues after POST handling
 */

require_once __DIR__ . '/src/bootstrap.php';

$cfg = GcsConfig::load();

/*
 * Delegate POST actions (save / sync) to api_main.php
 * api_main.php is responsible for:
 *  - reading $_POST['action']
 *  - running SchedulerRunner
 *  - logging
 *  - NOT producing output
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    require_once __DIR__ . '/src/api_main.php';
}

/*
 * ---------------------------------------------------------------------
 * UI rendering continues below (unchanged)
 * ---------------------------------------------------------------------
 *
 * NOTE:
 * Do NOT exit or redirect here.
 * FPP will re-render this page automatically.
 */
