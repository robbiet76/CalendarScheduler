# 21) Production Readiness Checklist

## Purpose
Lock a single, ordered execution plan to move Calendar Scheduler from current state to production-ready state.

This checklist is intentionally sequential. If a bug/fix detour is needed, complete the detour, then resume the next unchecked item.

## Process Rules
1. Work top-to-bottom by phase and checklist item.
2. If an issue is discovered mid-phase:
3. Capture the issue briefly.
4. Apply the minimal fix needed.
5. Re-run the phase gate for that phase.
6. Resume the next checklist item.
7. Do not reorder phases unless explicitly approved.
8. A phase is complete only when its gate criteria are met.

## Phase 1: Release Hardening
- [x] Define release checklist doc with preflight, deploy, rollback, and post-verify steps.
- [x] Confirm minimum supported environment versions (FPP image, PHP runtime, plugin dependencies).
- [x] Ensure all UI API actions return stable error shape (`ok=false`, `error`, actionable hint when possible).
- [x] Validate config/bootstrap behavior from clean install (no SSH required path).

Phase 1 gate:
- [x] Clean install bootstrap succeeds.
- [x] All critical API actions fail safely with clear error messages.

## Phase 2: Regression and E2E Test Coverage
- [x] Keep `bin/cs-resolution-regression` as baseline gate.
- [x] Add API smoke checks for `status`, `preview`, `apply`, auth start/poll/disconnect.
- [x] Add one golden-calendar E2E script for FPP host validation.
- [x] Define release gate command set (local + FPP host).

Suggested gate commands:
- `bin/cs-resolution-regression --json`
- `bin/cs-api-smoke --json`
- `bin/cs-full-regression --json --api-include-apply-noop`

Phase 2 gate:
- [x] Resolution regression passes.
- [x] API smoke checks pass.
- [x] Golden-calendar E2E pass is green on FPP host.

## Phase 3: Observability and Diagnostics
- [x] Standardize diagnostics payload sections:
- [x] `syncMode`
- [x] `selectedCalendarId`
- [x] `counts`
- [x] `pendingSummary`
- [x] `lastError` (if any)
- [x] Keep diagnostics sourced from authoritative manifest where possible.
- [x] Add lightweight apply/auth error correlation IDs in logs.

Phase 3 gate:
- [x] Diagnostics output is complete and stable across sync modes.
- [x] Errors can be traced from UI symptom to log entry.

## Phase 4: OAuth and Secret Lifecycle Safety
- [x] Verify client secret upload overwrite behavior (no secret file accumulation).
- [x] Verify token file lifecycle for connect/disconnect/reconnect.
- [x] Verify setup checks and Connect button gating behavior.
- [x] Confirm disconnect always leaves system in predictable disconnected state.

Phase 4 gate:
- [x] Repeated connect/disconnect cycles produce no stale secret/token artifacts.
- [x] OAuth recovery path works without SSH intervention.

## Phase 5: Packaging and Upgrade Safety
- [x] Validate upgrade from existing installs with prior config formats.
- [x] Ensure migrations are idempotent and non-destructive.
- [x] Confirm no duplicate/legacy runtime files are required.

Phase 5 gate:
- [x] Upgrade test matrix passes for at least one old config snapshot and one fresh install.

## Phase 6: User Documentation and Supportability
- [ ] Publish quick start (OAuth setup + first sync).
- [ ] Publish troubleshooting guide mapped to diagnostics keys.
- [ ] Publish sync mode behavior guide (`Calendar -> FPP`, `FPP -> Calendar`, `Both`).
- [ ] Publish known limitations and expected behavior notes.

Phase 6 gate:
- [ ] A new user can complete setup and first apply using docs only.
- [ ] Common troubleshooting paths are documented and reproducible.

## Production Exit Criteria
- [ ] All phase gates passed.
- [ ] Final release branch tagged.
- [ ] FPP-host validation run archived with command outputs and commit hashes.
- [ ] Rollback procedure verified.
