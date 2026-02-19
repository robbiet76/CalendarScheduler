# Spec Alignment Audit (Pass 1)

Purpose: establish a full trace map from behavioral specs to current implementation before making surgical spec edits.

Date: 2026-02-19  
Branch: `feature/production-readiness`

## Method
1. Enumerated all files under `spec/`, `docs/`, `src/`, and `bin/`.
2. Extracted headings from every `spec/*.md` file.
3. Mapped each spec to concrete implementation anchors (API actions, core classes, regression runners, release scripts).
4. Flagged each spec as:
   - `Aligned`: appears consistent with current code shape.
   - `Partial`: mostly valid, but needs targeted updates.
   - `Stale`: key behavior/contract is out of date.

## Core Implementation Anchors
- UI/API contract: `ui-api.php` (`status`, `diagnostics`, `preview`, `apply`, `auth_*` actions)
- Engine pipeline: `src/Engine/SchedulerEngine.php`, `src/Diff/Reconciler.php`, `src/Apply/ApplyRunner.php`
- Provider integration: `src/Adapter/Calendar/Google/*`
- Regression/release runners: `bin/cs-resolution-regression`, `bin/cs-api-smoke`, `bin/cs-full-regression`, `bin/cs-package`, `bin/cs-verify-package`
- Production docs: `docs/quick-start.md`, `docs/troubleshooting.md`, `docs/sync-modes.md`, `docs/known-limitations.md`

## Spec-To-Code Matrix
| Spec | Primary Anchors | Initial Status | Notes |
|---|---|---|---|
| `spec/00-behavioral-spec-table-of-contents.md` | whole spec set | Aligned | TOC file references and labels were revalidated in this pass. |
| `spec/01-system-purpose.md` | `src/Engine/SchedulerEngine.php`, `src/Planner/*`, `src/Diff/*` | Aligned | Intent/manifest-first framing matches implementation shape. |
| `spec/02-architecture-overview.md` | `bootstrap.php`, `src/*` layer boundaries | Aligned | Updated to current module boundaries, migration posture, and corrected ordering-spec reference. |
| `spec/03-manifest.md` | `src/Planner/ManifestPlanner.php`, `src/Adapter/FppScheduleAdapter.php` | Aligned | Manifest model maps well to emitted structures. |
| `spec/04-manifest-identity.md` | `src/Intent/IntentNormalizer.php`, `src/Adapter/FppScheduleAdapter.php` | Aligned | Identity/state separation is implemented. |
| `spec/05-calendar-io-Google.md` | `ui-api.php`, `src/Adapter/Calendar/Google/*`, `bin/calendar-scheduler` | Aligned | OAuth/setup and flow semantics aligned to device-auth runtime and current API actions. |
| `spec/05-calendar-io.md` | `src/Adapter/Calendar/*`, `src/Engine/SchedulerEngine.php` | Aligned | Removed legacy appendix drift; terminology now matches INI metadata and API-backed provider model. |
| `spec/06-event-resolution.md` | `src/Resolution/*`, `src/Intent/*` | Aligned | Resolution contracts appear consistent with code. |
| `spec/07-events-and-subevents.md` | `src/Resolution/*`, `src/Planner/*`, `src/Adapter/FppScheduleAdapter.php` | Aligned | Atomic subevent model matches usage. |
| `spec/08-scheduler-ordering.md` | `src/Engine/SchedulerEngine.php` ordering methods | Aligned | Complex ordering implementation corresponds to described precedence model. |
| `spec/09-planner-responsibilities.md` | `src/Planner/*`, `src/Engine/SchedulerEngine.php` | Aligned | Planning responsibilities align with code boundaries. |
| `spec/10-diff-and-reconciliation.md` | `src/Diff/Reconciler.php`, `src/Diff/ReconciliationResult.php` | Aligned | Directional reconciliation model is implemented. |
| `spec/11-apply-phase-rules.md` | `src/Apply/ApplyRunner.php`, `ui-api.php` apply path | Aligned | Apply semantics and safeguards are present. |
| `spec/12-fpp-semantics.md` | `src/Platform/FppSemantics.php`, `src/Adapter/FppScheduleAdapter.php` | Aligned | Semantic conversions and defaults implemented. |
| `spec/13-logging-debugging.md` | `ui-api.php`, `src/Adapter/Calendar/Google/*`, `docs/troubleshooting.md` | Aligned | Updated to current diagnostics/correlation/log-path model. |
| `spec/14-ui-controller-contract.md` | `content.php`, `ui-api.php` | Aligned | Updated to current sync mode model and action contract. |
| `spec/15-error-handling-and-invariants.md` | `ui-api.php` response envelope and error paths | Aligned | Updated to current runtime envelope and correlation behavior. |
| `spec/16-non-goals-and-exclusions.md` | system-wide | Aligned | Generally consistent with present architecture. |
| `spec/17-evolution-and-extension-model.md` | provider boundaries, schema evolution | Aligned | Updated provider/examples, manifest version field, and FPP schedule boundary wording. |
| `spec/18-round-trip-contract.md` | `src/Adapter/FppScheduleAdapter.php`, `src/Adapter/Calendar/Google/*` | Aligned | Round-trip directionality still core behavior. |
| `spec/19-regression-test-matrix.md` | `bin/cs-full-regression`, `bin/cs-resolution-regression`, `bin/cs-api-smoke` | Aligned | Runner command examples and scenario framing match current regression workflow. |
| `spec/20-resolution-regression-suite.md` | `bin/cs-resolution-regression` RR cases | Aligned | Command examples updated to current runner invocation style. |
| `spec/21-production-readiness-checklist.md` | current branch state | Aligned | Reflects completed Phases 1–6. |
| `spec/22-release-runbook.md` | `bin/cs-*`, GitHub PR flow, FPP deploy workflow | Aligned | Updated to PR/master flow and root-owned FPP update commands. |
| `spec/resolution_layer_design.md` | `src/Resolution/*` + planning integration | Aligned | Design still maps to runtime data model. |

## High-Priority Drift (Fix First)
- None open from this pass.

## Suggested Surgical Edit Batches
1. Batch A (critical behavioral drift): `spec/14`, `spec/05-calendar-io-Google`
2. Batch B (operational diagnostics/errors): `spec/13`, `spec/15`
3. Batch C (release/test command polishing): `spec/19`, `spec/20`, `spec/22`, `spec/00` TOC consistency sweep

## Safety Notes
- Edits were applied surgically to targeted sections only (OAuth/setup, API contract, diagnostics/errors, runner/runbook commands, TOC file link).
- This document began as mapping baseline and now also records post-edit alignment status for the current pass.
