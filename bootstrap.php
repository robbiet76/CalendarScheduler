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
// Platform
// -----------------------------------------------------------------------------

require_once __DIR__ . '/src/Platform/IniMetadata.php';
require_once __DIR__ . '/src/Platform/FppSemantics.php';
require_once __DIR__ . '/src/Platform/HolidayResolver.php';
require_once __DIR__ . '/src/Platform/SunTimeDisplayEstimator.php';
require_once __DIR__ . '/src/Platform/FppEnvExporter.php';

// -----------------------------------------------------------------------------
// Adapter
// -----------------------------------------------------------------------------

require_once __DIR__ . '/src/Adapter/FppScheduleTranslator.php';
require_once __DIR__ . '/src/Adapter/FppScheduleAdapter.php';

// Calendar — provider-agnostic boundary
require_once __DIR__ . '/src/Adapter/Calendar/OverrideIntent.php';
require_once __DIR__ . '/src/Adapter/Calendar/SnapshotEvent.php';
require_once __DIR__ . '/src/Adapter/Calendar/CalendarSnapshot.php';

// Calendar — Google provider
require_once __DIR__ . '/src/Adapter/Calendar/Google/GoogleConfig.php';
require_once __DIR__ . '/src/Adapter/Calendar/Google/GoogleOAuthBootstrap.php';
require_once __DIR__ . '/src/Adapter/Calendar/Google/GoogleApiClient.php';
require_once __DIR__ . '/src/Adapter/Calendar/Google/GoogleCalendarProvider.php';
require_once __DIR__ . '/src/Adapter/Calendar/Google/GoogleCalendarTranslator.php';
require_once __DIR__ . '/src/Adapter/Calendar/Google/GoogleEventMapper.php';
require_once __DIR__ . '/src/Adapter/Calendar/Google/GoogleApplyExecutor.php';

// -----------------------------------------------------------------------------
// Intent
// -----------------------------------------------------------------------------

require_once __DIR__ . '/src/Intent/Intent.php';
require_once __DIR__ . '/src/Intent/IntentNormalizer.php';
require_once __DIR__ . '/src/Intent/NormalizationContext.php';

// -----------------------------------------------------------------------------
// Resolution
// -----------------------------------------------------------------------------

require_once __DIR__ . '/src/Resolution/Dto/ResolutionRole.php';
require_once __DIR__ . '/src/Resolution/Dto/ResolutionScope.php';
require_once __DIR__ . '/src/Resolution/Dto/ResolvedSubevent.php';
require_once __DIR__ . '/src/Resolution/Dto/ResolvedBundle.php';
require_once __DIR__ . '/src/Resolution/Dto/ResolvedSchedule.php';
require_once __DIR__ . '/src/Resolution/ResolutionEngineInterface.php';
require_once __DIR__ . '/src/Resolution/ResolutionEngine.php';

// -----------------------------------------------------------------------------
// Planner
// -----------------------------------------------------------------------------

require_once __DIR__ . '/src/Planner/Dto/PlannerIntent.php';
require_once __DIR__ . '/src/Planner/ResolvedSchedulePlanner.php';
require_once __DIR__ . '/src/Planner/ResolvedScheduleToIntentAdapter.php';
require_once __DIR__ . '/src/Planner/OrderingKey.php';
require_once __DIR__ . '/src/Planner/PlannedEntry.php';
require_once __DIR__ . '/src/Planner/PlannerResult.php';
require_once __DIR__ . '/src/Planner/Planner.php';
require_once __DIR__ . '/src/Planner/ManifestPlanner.php';

// -----------------------------------------------------------------------------
// Diff & Reconciliation
// -----------------------------------------------------------------------------

require_once __DIR__ . '/src/Diff/DiffResult.php';
require_once __DIR__ . '/src/Diff/Diff.php';
require_once __DIR__ . '/src/Diff/ReconciliationAction.php';
require_once __DIR__ . '/src/Diff/ReconciliationResult.php';
require_once __DIR__ . '/src/Diff/Reconciler.php';

// -----------------------------------------------------------------------------
// Engine
// -----------------------------------------------------------------------------

require_once __DIR__ . '/src/Engine/SchedulerRunResult.php';
require_once __DIR__ . '/src/Engine/SchedulerEngine.php';

// -----------------------------------------------------------------------------
// Apply
// -----------------------------------------------------------------------------

require_once __DIR__ . '/src/Apply/ApplyTargets.php';
require_once __DIR__ . '/src/Apply/ApplyOptions.php';
require_once __DIR__ . '/src/Apply/ApplyEvaluation.php';
require_once __DIR__ . '/src/Apply/FppScheduleMutator.php';
require_once __DIR__ . '/src/Apply/FppScheduleWriter.php';
require_once __DIR__ . '/src/Apply/ManifestWriter.php';
require_once __DIR__ . '/src/Apply/ApplyRunner.php';

// -----------------------------------------------------------------------------
// Bootstrap complete
// -----------------------------------------------------------------------------

// Nothing else happens here.
