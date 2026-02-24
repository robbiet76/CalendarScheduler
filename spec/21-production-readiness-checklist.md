# 21) Production Readiness Checklist (v1.0)

## Purpose
Define the formal go/no-go process for releasing Calendar Scheduler `v1.0.0`.

This checklist is sequential and blocking. A failed gate blocks release until fixed and re-validated.

## Process Rules
1. Freeze a candidate commit/branch before running the full checklist.
2. Run phases in order; do not skip ahead.
3. If a phase fails, capture evidence, apply minimal fix, and re-run that phase.
4. Any fix after Phase 2 requires rerunning Phases 2-8.
5. Do not tag `v1.0.0` until all gates pass on the same frozen commit.

## Release Candidate Metadata
- Candidate branch: `________________`
- Candidate commit: `________________`
- Test operator: `________________`
- Start UTC: `________________`
- End UTC: `________________`

## Phase 1: Code/Spec/Docs Parity Audit
- [ ] Confirm behavioral specs in `spec/` match current runtime behavior (`content.php`, `ui-api.php`, `src/*`).
- [ ] Confirm provider coverage is explicit and consistent for Google + Outlook.
- [ ] Confirm no stale references to removed flows/files remain in specs/docs.
- [ ] Confirm release runbook (`spec/22-release-runbook.md`) matches current commands and branch flow.

Phase 1 gate:
- [ ] No unresolved parity drift items.
- [ ] Any drift found is fixed in the candidate commit.

Evidence:
- `docs/release-evidence/v1.0.0/spec-parity-audit.md`

## Phase 2: Automated Regression Gates
- [ ] Run `bin/cs-resolution-regression --json`.
- [ ] Run `bin/cs-provider-parity-regression --json`.
- [ ] Run `bin/cs-api-smoke --json`.
- [ ] Run `bin/cs-full-regression --json --api-include-apply-noop`.

Phase 2 gate:
- [ ] All automated suites pass with no unexpected failures.

Evidence:
- `docs/release-evidence/v1.0.0/regression/`

## Phase 3: Live E2E Validation on FPP (Google + Outlook)
- [ ] Validate full connect/preview/apply/converge path on Google.
- [ ] Validate full connect/preview/apply/converge path on Outlook.
- [ ] Validate all sync modes (`Calendar -> FPP`, `FPP -> Calendar`, `Both`) on both providers.
- [ ] Confirm post-apply convergence (`follow-up preview noop`) for each validated scenario.

Phase 3 gate:
- [ ] No unresolved pending-action loops in validated scenarios.

Evidence:
- `docs/release-evidence/v1.0.0/e2e-fpp.md`
- Screenshots/video references for both providers

## Phase 4: Convergence Matrix Validation
- [ ] Validate all hard/symbolic start/end date combinations.
- [ ] Validate all hard/symbolic start/end time combinations.
- [ ] Validate non-1:1 calendar-event-to-FPP-entry scenarios.
- [ ] Validate day mask combinations.
- [ ] Validate command scenarios.

Phase 4 gate:
- [ ] Matrix scenarios converge after expected apply cycle(s) with no unexplained drift.

Evidence:
- `docs/release-evidence/v1.0.0/convergence-matrix.md`

## Phase 5: OAuth/Token Lifecycle and Session Stability
- [ ] Verify repeated connect/disconnect/reconnect cycles on Google.
- [ ] Verify repeated connect/disconnect/reconnect cycles on Outlook.
- [ ] Verify token persistence across plugin refresh and FPP reboot.
- [ ] Verify no recurring false-disconnect behavior during normal operation windows.

Phase 5 gate:
- [ ] OAuth/token lifecycle is stable on both providers.

Evidence:
- `docs/release-evidence/v1.0.0/oauth-lifecycle.md`

## Phase 6: Upgrade, Migration, and Packaging Safety
- [ ] Validate upgrade from prior known plugin version(s) to candidate.
- [ ] Validate clean install behavior.
- [ ] Run package build: `bin/cs-package --out-dir /tmp/cs-release`.
- [ ] Run package verify: `bin/cs-verify-package --dir <staged-plugin-dir>`.
- [ ] Confirm runtime package excludes development-only assets.

Phase 6 gate:
- [ ] Upgrade and clean install both succeed without manual repair.
- [ ] Package verifies cleanly.

Evidence:
- `docs/release-evidence/v1.0.0/upgrade-packaging.md`

## Phase 7: Observability, Error Handling, and Security Review
- [ ] Verify diagnostics payload keys remain stable (`provider`, `syncMode`, `selectedCalendarId`, `counts`, `pendingSummary`, `lastError`).
- [ ] Verify correlation IDs for apply/auth runtime failures are present and traceable in logs.
- [ ] Verify OAuth secrets/tokens are never emitted in logs or diagnostics.
- [ ] Verify expected file permissions and token/config handling on FPP runtime paths.

Phase 7 gate:
- [ ] No high-severity observability/security findings remain.

Evidence:
- `docs/release-evidence/v1.0.0/observability-security.md`

## Phase 8: Rollback and Operational Readiness
- [ ] Execute rollback drill to previous known-good commit/tag on FPP.
- [ ] Verify plugin recovery via `status` + `preview` post-rollback.
- [ ] Verify forward redeploy back to candidate commit/tag.
- [ ] Confirm operator steps in `spec/22-release-runbook.md` are complete and accurate.

Phase 8 gate:
- [ ] Rollback and forward redeploy both succeed.

Evidence:
- `docs/release-evidence/v1.0.0/rollback-drill.md`

## Final Go/No-Go Decision
- [ ] All phase gates passed on the same candidate commit.
- [ ] Evidence artifacts are complete and archived.
- [ ] Release tag created: `v1.0.0`
- [ ] Release notes published.

Decision:
- [ ] GO
- [ ] NO-GO

Approvals:
- Engineering: `________________`
- Product/Owner: `________________`
- Operations (if applicable): `________________`

## Blocking Criteria (Automatic No-Go)
- Any unresolved regression in automated suites.
- Any unresolved cross-provider parity failure.
- Any unresolved convergence loop in validated core scenarios.
- Any high-severity OAuth/security/secret-handling issue.
- Inability to perform rollback successfully.
