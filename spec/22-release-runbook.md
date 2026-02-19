# 22) Release Runbook

## Purpose
Provide an operator-safe release procedure for Calendar Scheduler that includes preflight checks, deployment, rollback, and post-verify steps.

## Preflight
1. Confirm working tree is clean on release branch.
2. Run local syntax gate:
   - `php -l ui-api.php`
   - `php -l content.php`
3. Run regression suite:
   - `bin/cs-resolution-regression --json`
   - `bin/cs-api-smoke --json`
   - Optional integrated run: `bin/cs-full-regression --json --api-include-apply-noop`
4. Verify key API actions on FPP host return `ok=true`:
   - `status`, `preview`, `auth_device_start`, `auth_device_poll` (pending/connected path), `apply` (dry-safe fixture).
5. Record release metadata:
   - commit hash
   - branch
   - date/time (UTC)
   - operator

## Deploy
1. Push release commit to `origin/implementation-v2`.
2. On FPP host, update plugin repo:
   - `cd /home/fpp/media/plugins/GoogleCalendarScheduler`
   - `git pull --ff-only origin implementation-v2`
3. Refresh plugin page in browser and confirm UI loads without console/runtime errors.
4. Run initial UI checks:
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
   - `cd /home/fpp/media/plugins/GoogleCalendarScheduler`
   - `git checkout <known-good-commit>`
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
