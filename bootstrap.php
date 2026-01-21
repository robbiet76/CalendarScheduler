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
// Core — identity, manifest, invariants
// -----------------------------------------------------------------------------

require_once __DIR__ . '/src/Core/IdentityInvariantViolation.php';
require_once __DIR__ . '/src/Core/ManifestInvariantViolation.php';

require_once __DIR__ . '/src/Core/IdentityCanonicalizer.php';
require_once __DIR__ . '/src/Core/IdentityHasher.php';
require_once __DIR__ . '/src/Core/Sha256IdentityHasher.php';

require_once __DIR__ . '/src/Core/ManifestStore.php';
require_once __DIR__ . '/src/Core/FileManifestStore.php';

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
// Platform — FPP-specific representation (Phase 2.5)
// -----------------------------------------------------------------------------

require_once __DIR__ . '/src/Platform/FppSemantics.php';
require_once __DIR__ . '/src/Platform/FppScheduleTranslator.php';
require_once __DIR__ . '/src/Platform/FppScheduleWriter.php';

// -----------------------------------------------------------------------------
// Outbound — scheduler execution
// -----------------------------------------------------------------------------

require_once __DIR__ . '/src/Outbound/ApplyEngine.php';
require_once __DIR__ . '/src/Outbound/ApplyResult.php';
require_once __DIR__ . '/src/Outbound/SchedulerRunOptions.php';
require_once __DIR__ . '/src/Outbound/SchedulerRunResult.php';
require_once __DIR__ . '/src/Outbound/SchedulerRunner.php';
require_once __DIR__ . '/src/Outbound/FppAdoption.php';

// -----------------------------------------------------------------------------
// Bootstrap complete
// -----------------------------------------------------------------------------

// Nothing else happens here.