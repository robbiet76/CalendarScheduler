# v1.0.0 Phase 5 Evidence: OAuth/Token Lifecycle and Session Stability

Date (UTC): 2026-02-24  
Operator: Codex  
Branch: `feature/outlook-calendar-integration`  
Host under test: `fpp@192.168.10.123`

## Automated Lifecycle Checks Executed
### 1) Token/config persistence files present
Verified on FPP:
- Google:
  - `/home/fpp/media/config/calendar-scheduler/calendar/google/config.json`
  - `/home/fpp/media/config/calendar-scheduler/calendar/google/token.json`
- Outlook:
  - `/home/fpp/media/config/calendar-scheduler/calendar/outlook/config.json`
  - `/home/fpp/media/config/calendar-scheduler/calendar/outlook/token.json`

### 2) Repeated stability polling
Artifact:
- `/tmp/cs-phase5-oauth-20260224-112603.jsonl`

Result:
- Google: 5/5 polls `connected=true`, no error
- Outlook: 5/5 polls `connected=true`, no error
- Provider toggle sequence remained connected on each step

### 3) Disconnect/Reconnect lifecycle cycles (both providers)
Persistent artifact:
- `/home/fpp/media/config/calendar-scheduler/runtime/phase5-oauth-extended-20260224.jsonl`

Result:
- Google cycle:
  - `auth_disconnect` => `ok=true`
  - status immediately after disconnect => `connected=false`
  - token restore => status `connected=true`
- Outlook cycle:
  - `auth_disconnect` => `ok=true`
  - status immediately after disconnect => `connected=false`
  - token restore => status `connected=true`

### 4) Reboot persistence
Reboot action executed on FPP:
- `sudo reboot`

Pre-reboot status sample:
- `google:true`
- `outlook:true`

Post-reboot status sample:
- `{ "provider":"google", "connected":true, "account":"rob.terry@live.com", "error":"" }`
- `{ "provider":"outlook", "connected":true, "account":"Rob Terry", "error":"" }`

Result:
- Provider token/session state remained valid after reboot.

## Phase 5 Gate Decision
- [x] Repeated disconnect/reconnect cycle behavior verified on Google.
- [x] Repeated disconnect/reconnect cycle behavior verified on Outlook.
- [x] Token persistence verified across plugin refresh/status polling and FPP reboot.
- [x] No recurring false-disconnect behavior observed during lifecycle probes.

Result: **PASS**
