# v1.0.0 Phase 8 Evidence: Rollback and Operational Readiness

Date (UTC): 2026-02-24  
Operator: Codex  
Branch: `feature/outlook-calendar-integration`  
Host under test: `fpp@192.168.10.123`

## Drill Steps Executed on FPP
Working directory:
- `/home/fpp/media/plugins/CalendarScheduler`

Metadata captured during drill:
- Branch: `feature/outlook-calendar-integration`
- Candidate commit before rollback: `495603c`
- Rollback target commit (`HEAD~1`): `8b1828e`

Sequence:
1. Roll back to previous commit (detached)
```bash
git checkout --detach 8b1828e
```
2. Validate plugin health after rollback
- `status` action: `ok=true`
- `preview` action: `ok=true`
3. Forward redeploy to candidate branch
```bash
git checkout feature/outlook-calendar-integration
git pull --ff-only
```
4. Validate plugin health after redeploy
- `status` action: `ok=true`
- `preview` action: `ok=true`

Raw drill artifact on FPP:
- `/tmp/cs-phase8-rollback-20260224-113008.json`

## Result Snapshot
```json
{
  "branch": "feature/outlook-calendar-integration",
  "currentCommit": "495603c",
  "previousCommit": "8b1828e",
  "rollbackHead": "8b1828e",
  "rollback": { "statusOk": true, "previewOk": true },
  "restoredCommit": "495603c",
  "restored": { "statusOk": true, "previewOk": true }
}
```

## Runbook Accuracy Check
- The rollback + forward redeploy flow in `spec/22-release-runbook.md` remains operationally accurate for the tested host.

## Phase 8 Gate Decision
- [x] Rollback drill to previous known-good commit succeeded.
- [x] Plugin status + preview were healthy after rollback.
- [x] Forward redeploy back to candidate succeeded.
- [x] Operator flow remains valid.

Result: **PASS**
