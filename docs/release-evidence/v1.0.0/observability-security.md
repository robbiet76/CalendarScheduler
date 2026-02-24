# v1.0.0 Phase 7 Evidence: Observability, Error Handling, and Security Review

Date (UTC): 2026-02-24  
Operator: Codex  
Branch: `feature/outlook-calendar-integration`  
Host under test: `fpp@192.168.10.123`

## Diagnostics Contract Check
Runtime diagnostics keys sampled from live endpoint:
- `syncMode`
- `selectedCalendarId`
- `counts`
- `pendingSummary`
- `lastError`
- `previewGeneratedAtUtc`

Status: PASS (expected key contract present).

## Correlation ID Traceability
Verified in `ui-api.php`:
- Correlation IDs generated for `apply` and `auth_*` failures.
- Correlated errors logged to both standard error log and plugin log file.

Live log sample (`/home/fpp/media/logs/CalendarScheduler.log`) confirms entries with:
- `action=<name>`
- `correlation_id=<id>`
- `error=<message>`

Status: PASS.

## Secret/Token Logging Review
Code and runtime checks performed:
- Token files written with `chmod 0600` in both Google/Outlook token writers.
- Runtime file permissions on FPP:
  - `.../calendar/google/token.json` => `-rw-------`
  - `.../calendar/outlook/token.json` => `-rw-------`
- Quick scan of log files (`CalendarScheduler.log`, `fppd.log`, `messages`) for likely secret markers:
  - `access_token`, `refresh_token`, `client_secret`, `Authorization: Bearer`, `device_code`
  - No matches found in sampled output.

Status: PASS.

## File/Config Handling Review
- Provider config files are present and readable by plugin user.
- Provider token files are present and restricted.
- Config directories are writable by plugin user (`fpp`).

Status: PASS.

## Phase 7 Gate Decision
- [x] Diagnostics payload keys remain stable.
- [x] Correlation IDs for apply/auth failures are present and traceable.
- [x] OAuth secrets/tokens are not emitted in inspected logs.
- [x] Expected token/config file permission posture confirmed on FPP.

Result: **PASS**
