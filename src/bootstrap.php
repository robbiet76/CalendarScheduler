<?php

define('GCS_CONFIG_PATH', '/home/fpp/media/config/plugin.googleCalendarScheduler.json');
define('GCS_LOG_PATH', '/home/fpp/media/logs/google-calendar-scheduler.log');

/*
 * Core
 */
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Log.php';

/*
 * Calendar input
 */
require_once __DIR__ . '/IcsFetcher.php';
require_once __DIR__ . '/IcsParser.php';

/*
 * Intent & resolution
 */
require_once __DIR__ . '/TargetResolver.php';
require_once __DIR__ . '/SchedulerIntent.php';
require_once __DIR__ . '/IntentConsolidator.php';

/*
 * Scheduler execution
 */
require_once __DIR__ . '/SchedulerSync.php';
require_once __DIR__ . '/SchedulerRunner.php';

/*
 * FPP integration
 */
require_once __DIR__ . '/FppSchedulerHorizon.php';
require_once __DIR__ . '/FppScheduleMapper.php';
require_once __DIR__ . '/SchedulerDiff.php';
require_once __DIR__ . '/SchedulerApply.php';
