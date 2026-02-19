<?php
declare(strict_types=1);

/**
 * Calendar Scheduler — Compatibility Wrapper
 *
 * File: www/fpp-env-export.php
 * Purpose: Preserve legacy direct-web entrypoints by delegating to the
 * canonical root-level `fpp-env-export.php` implementation.
 */

// FPP plugin.php does NOT resolve pages from www/.
// Keep this file as a wrapper for any direct access paths.
require_once __DIR__ . '/../fpp-env-export.php';
