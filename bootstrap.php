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
require_once __DIR__ . '/src/Core/IdentityBuilder.php';

require_once __DIR__ . '/src/Core/ManifestStore.php';
require_once __DIR__ . '/src/Core/FileManifestStore.php';

// -----------------------------------------------------------------------------
// Intent — canonical, source-agnostic scheduling intent (Phase 3)
// -----------------------------------------------------------------------------

require_once __DIR__ . '/src/Intent/Intent.php';
require_once __DIR__ . '/src/Intent/IntentNormalizer.php';
require_once __DIR__ . '/src/Intent/CalendarRawEvent.php';
require_once __DIR__ . '/src/Intent/FppRawEvent.php';
require_once __DIR__ . '/src/Intent/NormalizationContext.php';

// -----------------------------------------------------------------------------
// TEMPORARILY DISABLED — Intent-first architecture (will be re-enabled later)
// -----------------------------------------------------------------------------
// require_once __DIR__ . '/src/Planner/OrderingKey.php';
// require_once __DIR__ . '/src/Planner/PlannedEntry.php';
// require_once __DIR__ . '/src/Planner/PlannerResult.php';
// require_once __DIR__ . '/src/Planner/Planner.php';

// -----------------------------------------------------------------------------
// TEMPORARILY DISABLED — Intent-first architecture (will be re-enabled later)
// -----------------------------------------------------------------------------
// require_once __DIR__ . '/src/Diff/DiffResult.php';
// require_once __DIR__ . '/src/Diff/Diff.php';

// -----------------------------------------------------------------------------
// TEMPORARILY DISABLED — Intent-first architecture (will be re-enabled later)
// -----------------------------------------------------------------------------
// require_once __DIR__ . '/src/Resolution/ResolvableEvent.php';
// require_once __DIR__ . '/src/Resolution/ResolutionOperation.php';
// require_once __DIR__ . '/src/Resolution/ResolutionResult.php';
// require_once __DIR__ . '/src/Resolution/ResolutionPolicy.php';
// require_once __DIR__ . '/src/Resolution/ResolutionInputs.php';
// require_once __DIR__ . '/src/Resolution/EventResolver.php';
// require_once __DIR__ . '/src/Resolution/CalendarManifestResolver.php';

// -----------------------------------------------------------------------------
// Platform — FPP-specific representation (Phase 2.5)
// -----------------------------------------------------------------------------

// -----------------------------------------------------------------------------
// TEMPORARILY DISABLED — Intent-first architecture (will be re-enabled later)
// -----------------------------------------------------------------------------
// require_once __DIR__ . '/src/Platform/FppSemantics.php';
// require_once __DIR__ . '/src/Platform/FppScheduleTranslator.php';
// require_once __DIR__ . '/src/Platform/FppScheduleWriter.php';
// require_once __DIR__ . '/src/Platform/HolidayResolver.php';
// require_once __DIR__ . '/src/Platform/SunTimeDisplayEstimator.php';
require_once __DIR__ . '/src/Platform/IcsFetcher.php';
require_once __DIR__ . '/src/Platform/IcsParser.php';
require_once __DIR__ . '/src/Platform/CalendarTranslator.php';
require_once __DIR__ . '/src/Platform/YamlMetadata.php';

// -----------------------------------------------------------------------------
// TEMPORARILY DISABLED — Intent-first architecture (will be re-enabled later)
// -----------------------------------------------------------------------------
// require_once __DIR__ . '/src/Outbound/ApplyEngine.php';
// require_once __DIR__ . '/src/Outbound/ApplyResult.php';
// require_once __DIR__ . '/src/Outbound/SchedulerRunOptions.php';
// require_once __DIR__ . '/src/Outbound/SchedulerRunResult.php';
// require_once __DIR__ . '/src/Outbound/SchedulerRunner.php';

// -----------------------------------------------------------------------------
// Inbound — external systems -> manifest (draft snapshot)
// -----------------------------------------------------------------------------

// -----------------------------------------------------------------------------
// TEMPORARILY DISABLED — Intent-first architecture (will be re-enabled later)
// -----------------------------------------------------------------------------
// require_once __DIR__ . '/src/Inbound/FppAdoption.php';
require_once __DIR__ . '/src/Inbound/CalendarSnapshot.php';

// -----------------------------------------------------------------------------
// Bootstrap complete
// -----------------------------------------------------------------------------

// Nothing else happens here.