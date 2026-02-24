# v1.0.0 Phase 6 Evidence: Upgrade, Migration, and Packaging Safety

Date (UTC): 2026-02-24  
Operator: Codex  
Branch: `feature/outlook-calendar-integration`

## Packaging Commands
1. Build package
```bash
bin/cs-package --out-dir /tmp/cs-release
```
Result:
- PASS
- Built: `/tmp/cs-release/CalendarScheduler-0.0.1.zip`

2. Stage package and verify runtime exclusions
```bash
unzip -q -o /tmp/cs-release/CalendarScheduler-0.0.1.zip -d /tmp/cs-release/staged
bin/cs-verify-package --dir /tmp/cs-release/staged/CalendarScheduler
```
Result:
- PASS: no excluded development paths in staged runtime payload.

## FPP Runtime Packaging Validation
Executed on FPP host:
```bash
bin/cs-package --out-dir /tmp/cs-release-fpp
unzip -q -o /tmp/cs-release-fpp/CalendarScheduler-0.0.1.zip -d /tmp/cs-stage-fpp
bin/cs-verify-package --dir /tmp/cs-stage-fpp/CalendarScheduler
```
Artifacts/logs:
- `/tmp/cs-package-fpp.log`
- `/tmp/cs-verify-fpp.log`

Result:
- PASS (`cs-verify-package` succeeded).

## Clean Install Simulation (Staged Package)
Executed staged plugin CLI smoke directly from packaged output:
```bash
cd /tmp/cs-stage-fpp/CalendarScheduler
php bin/calendar-scheduler --refresh-calendar --dry-run --format=json
```
Artifacts:
- `/tmp/cs-stage-clean-install-smoke.json`
- `/tmp/cs-stage-clean-install-smoke.err`

Result:
- PASS (`stage_smoke_ok=true`).

## Upgrade Validation
Upgrade/rollback flow validated in Phase 8 drill:
- Candidate: `495603c`
- Rolled back to previous: `8b1828e`
- Forward restored to candidate: `495603c`
- `status` + `preview` healthy after both rollback and restore.

Reference:
- `docs/release-evidence/v1.0.0/rollback-drill.md`

## Phase 6 Gate Decision
- [x] Package build succeeds.
- [x] Package verification succeeds (no dev-only leakage).
- [x] Clean install simulation from staged package succeeds.
- [x] Upgrade/rollback path validated on FPP without manual repair.

Result: **PASS**
