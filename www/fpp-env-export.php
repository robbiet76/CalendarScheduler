<?php
declare(strict_types=1);

/**
 * Calendar Scheduler — Compatibility Wrapper
 *
 * Purpose:
 * - Preserve legacy/direct web entrypoint behavior for environments that still
 *   request this script from the plugin's www/ path.
 * - Delegate execution to the canonical root-level implementation.
 */

// FPP plugin.php does NOT resolve pages from www/.
// Keep this file as a wrapper for any direct access paths.
require_once __DIR__ . '/../fpp-env-export.php';
