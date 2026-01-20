<?php
declare(strict_types=1);

/**
 * Google Calendar Scheduler — V2 Bootstrap
 *
 * FPP REQUIREMENTS:
 * - No autoloading
 * - Explicit requires only
 * - Deterministic load order
 *
 * PURPOSE:
 * - Authoritative dependency map
 * - Zero logic
 * - Zero side effects
 */

// -----------------------------------------------------------------------------
// Global paths
// -----------------------------------------------------------------------------

define('GCS_VERSION', '2.0-dev');

// -----------------------------------------------------------------------------
// Core — domain + invariants (PURE)
// -----------------------------------------------------------------------------

require_once __DIR__ . '/src/Core/ManifestStore.php';
require_once __DIR__ . '/src/Core/FileManifestStore.php';

require_once __DIR__ . '/src/Core/IdentityHasher.php';

require_once __DIR__ . '/src/Core/IdentityInvariantViolation.php';
require_once __DIR__ . '/src/Core/ManifestInvariantViolation.php';

// -----------------------------------------------------------------------------
// Planner — desired-state construction (PURE)
// -----------------------------------------------------------------------------

require_once __DIR__ . '/src/Planner/OrderingKey.php';
require_once __DIR__ . '/src/Planner/PlannedEntry.php';
require_once __DIR__ . '/src/Planner/PlannerResult.php';
require_once __DIR__ . '/src/Planner/Planner.php';

// -----------------------------------------------------------------------------
// Diff — desired vs existing reconciliation (PURE)
// -----------------------------------------------------------------------------

require_once __DIR__ . '/src/Diff/DiffResult.php';
require_once __DIR__ . '/src/Diff/Diff.php';

// -----------------------------------------------------------------------------
// Outbound — apply diff to schedule (PURE, Phase 2.4)
// -----------------------------------------------------------------------------

require_once __DIR__ . '/src/Outbound/ApplyResult.php';
require_once __DIR__ . '/src/Outbound/ApplyEngine.php';

// -----------------------------------------------------------------------------
// Bootstrap complete
// -----------------------------------------------------------------------------

// Nothing else happens here.