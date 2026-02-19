# 22) Release Runbook

## Purpose
Provide an operator-safe release procedure for Calendar Scheduler that includes preflight checks, deployment, rollback, and post-verify steps.

## Supported Baseline
- FPP image: v9.0 or newer.
- PHP runtime: 8.0 or newer.
- Plugin dependencies: none beyond built-in FPP/PHP modules already required by Calendar Scheduler.

## Preflight
1. Confirm working tree is clean on release branch.
2. Confirm release target is `master` and branch protection requirements are satisfied (PR review/checks).
3. Run local syntax gate:
   - `php -l ui-api.php`
   - `php -l content.php`
4. Run regression suite:
   - `bin/cs-resolution-regression --json`
   - `bin/cs-api-smoke --json`
   - Optional integrated run: `bin/cs-full-regression --json --api-include-apply-noop`
5. Verify key API actions on FPP host return `ok=true`:
   - `status`, `preview`, `auth_device_start`, `auth_device_poll` (pending/connected path), `apply` (dry-safe fixture).
6. Record release metadata:
   - commit hash
   - branch
   - date/time (UTC)
   - operator

### Phase 2 Gate Command Set
Run in this order to avoid invalidating OAuth state before live validation.

1. Local (repo root):
   - `bin/cs-resolution-regression --json`
2. FPP host (plugin repo):
   - `bin/cs-full-regression --json --api-include-apply-noop`
3. FPP host (plugin repo, API contract sweep including auth lifecycle):
   - `bin/cs-api-smoke --json --include-auth-cycle --include-apply-noop`

Notes:
- `auth_disconnect` intentionally clears token state; run it after live E2E validation.
- If `apply_noop` is gated because preview is not noop, run on a known dry-safe fixture first.

## Deploy
1. Build lightweight release artifact from runtime payload:
   - `bin/cs-package --out-dir /tmp/cs-release`
2. Push release branch to origin and open PR to `master`.
3. Obtain required GitHub approvals and merge PR to `master`.
4. On FPP host, install/update from packaged artifact (preferred) or pull `master` for validation:
   - `cd /home/fpp/media/plugins/CalendarScheduler`
   - `sudo git fetch --prune origin`
   - `sudo git checkout master`
   - `sudo git reset --hard origin/master`
5. Refresh plugin page in browser and confirm UI loads without console/runtime errors.
6. Run initial UI checks:
   - Connection Setup renders correctly.
   - Pending Actions renders or cleanly hides when disconnected.
   - Apply button state follows pending-action availability.

## Post-Verify
1. Run API sanity checks:
   - `status` reports expected provider/account state.
   - `preview` returns stable payload shape (`ok`, `preview`, `counts`, `actions`).
2. Run one real preview/apply cycle in each sync mode:
   - `Calendar -> FPP`
   - `FPP -> Calendar`
   - `Both`
3. Confirm no stuck pending actions after convergence.
4. Capture verification artifact:
   - command transcript
   - screenshots (connection, pending, apply, diagnostics)
   - final commit hash

## Rollback
1. Determine previous known-good commit hash.
2. On FPP host:
   - `cd /home/fpp/media/plugins/CalendarScheduler`
   - `sudo git checkout <known-good-commit>`
3. Reload plugin UI and rerun `status` + `preview` API checks.
4. If rollback is clean, pin and document incident:
   - failed commit
   - rollback commit
   - observed failure mode
   - follow-up owner/action

## Release Gate (Must Pass)
1. Regression suite green.
2. FPP-host deploy succeeded.
3. API sanity checks green.
4. One end-to-end sync cycle validated with no unresolved pending actions.
5. Rollback procedure validated at least once on current release line.
