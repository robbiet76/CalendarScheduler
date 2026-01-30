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
// Core — unused currently
// -----------------------------------------------------------------------------


// -----------------------------------------------------------------------------
// Adapter
// -----------------------------------------------------------------------------

require_once __DIR__ . '/src/Adapter/CalendarSnapshot.php';
require_once __DIR__ . '/src/Adapter/CalendarTranslator.php';
require_once __DIR__ . '/src/Adapter/FppScheduleTranslator.php';

// -----------------------------------------------------------------------------
// Intent — canonical, source-agnostic scheduling intent (Phase 3)
// -----------------------------------------------------------------------------

require_once __DIR__ . '/src/Intent/Intent.php';
require_once __DIR__ . '/src/Intent/IntentNormalizer.php';
require_once __DIR__ . '/src/Intent/CalendarRawEvent.php';
require_once __DIR__ . '/src/Intent/FppRawEvent.php';
require_once __DIR__ . '/src/Intent/NormalizationContext.php';

// -----------------------------------------------------------------------------
// Planner  - TEMPORARILY DISABLED (will be re-enabled later)
// -----------------------------------------------------------------------------
// require_once __DIR__ . '/src/Planner/OrderingKey.php';
// require_once __DIR__ . '/src/Planner/PlannedEntry.php';
// require_once __DIR__ . '/src/Planner/PlannerResult.php';
// require_once __DIR__ . '/src/Planner/Planner.php';

// -----------------------------------------------------------------------------
// Diff - TEMPORARILY DISABLED (will be re-enabled later)
// -----------------------------------------------------------------------------
// require_once __DIR__ . '/src/Diff/DiffResult.php';
// require_once __DIR__ . '/src/Diff/Diff.php';

// -----------------------------------------------------------------------------
// Platform
// -----------------------------------------------------------------------------

// require_once __DIR__ . '/src/Platform/FppScheduleWriter.php';
require_once __DIR__ . '/src/Platform/HolidayResolver.php';
require_once __DIR__ . '/src/Platform/SunTimeDisplayEstimator.php';
require_once __DIR__ . '/src/Platform/FppSemantics.php';
require_once __DIR__ . '/src/Platform/IcsFetcher.php';
require_once __DIR__ . '/src/Platform/IcsParser.php';
require_once __DIR__ . '/src/Platform/IniMetadata.php';

// -----------------------------------------------------------------------------
// Manifest
// -----------------------------------------------------------------------------

require_once __DIR__ . '/src/Manifest/ManifestWriter.php';

// -----------------------------------------------------------------------------
// Apply - TEMPORARILY DISABLED (will be re-enabled later)
// -----------------------------------------------------------------------------
// require_once __DIR__ . '/src/Apply/ApplyEngine.php';
// require_once __DIR__ . '/src/Apply/ApplyResult.php';
// require_once __DIR__ . '/src/Apply/SchedulerRunOptions.php';
// require_once __DIR__ . '/src/Apply/SchedulerRunResult.php';
// require_once __DIR__ . '/src/Apply/SchedulerRunner.php';

// -----------------------------------------------------------------------------
// Bootstrap complete
// -----------------------------------------------------------------------------

// Nothing else happens here.