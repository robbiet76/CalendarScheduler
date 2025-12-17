<?php

/**
 * GoogleCalendarScheduler
 * bootstrap.php
 *
 * Central include point for all plugin classes.
 *
 * IMPORTANT:
 *  - This file MUST have no side effects
 *  - Only class / constant loading is allowed
 *  - content.php controls execution
 */

define('GCS_CONFIG_PATH', '/home/fpp/media/config/plugin.googleCalendarScheduler.json');
define('GCS_LOG_PATH', '/home/fpp/media/logs/google-calendar-scheduler.log');

/*
 * --------------------------------------------------------------------
 * Core utilities
 * --------------------------------------------------------------------
 */
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Log.php';

/*
 * --------------------------------------------------------------------
 * Calendar input (Google Calendar ICS)
 * --------------------------------------------------------------------
 */
require_once __DIR__ . '/IcsFetcher.php';
require_once __DIR__ . '/IcsParser.php';

/*
 * --------------------------------------------------------------------
 * Target resolution
 * --------------------------------------------------------------------
 */
require_once __DIR__ . '/TargetResolver.php';

/*
 * --------------------------------------------------------------------
 * Scheduler orchestration (Phase 6.6)
 * --------------------------------------------------------------------
 */
require_once __DIR__ . '/SchedulerSync.php';
require_once __DIR__ . '/SchedulerRunner.php';

/*
 * --------------------------------------------------------------------
 * FPP integration helpers
 * --------------------------------------------------------------------
 */
require_once __DIR__ . '/FppSchedulerHorizon.php';

/*
 * --------------------------------------------------------------------
 * (Intentionally NOT loaded here yet)
 *
 * These remain unused until Phase 7 (live apply):
 *
 *   - SchedulerDiff.php
 *   - SchedulerApply.php
 *   - FppScheduleMapper.php
 *
 * They are intentionally excluded to avoid side effects
 * and accidental writes during dry-run validation.
 * --------------------------------------------------------------------
 */
