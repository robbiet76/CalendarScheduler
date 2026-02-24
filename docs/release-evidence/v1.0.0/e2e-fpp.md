# v1.0.0 Phase 3 Evidence: Live E2E Validation on FPP (Google + Outlook)

Date (UTC): 2026-02-24  
Operator: Codex  
Branch: `feature/outlook-calendar-integration`  
Host under test: `fpp@192.168.10.123` (`/home/fpp/media/plugins/CalendarScheduler`)

## Scope
Phase 3 gate requirements covered:
- Google connect/status + preview/apply/converge path
- Outlook connect/status + preview/apply/converge path
- Sync modes validated per provider: `both`, `calendar`, `fpp`
- Post-apply convergence validated via `--expect-post-noop=true`

## Commands Executed on FPP
Endpoint used for provider/status checks:
```bash
http://127.0.0.1/plugin.php?plugin=CalendarScheduler&page=ui-api.php&nopage=1
```

Provider/mode matrix runner (executed over SSH session):
- `set_provider` + `status` for each provider
- `php bin/cs-regression --label=phase3-<provider>-<mode> --sync-mode=<mode> --apply --expect-post-noop=true`

Raw matrix output file on FPP:
- `/tmp/cs-phase3-e2e-20260224-112325.jsonl`

## Provider Connection Status
- Google:
  - `connected: true`
  - `account: rob.terry@live.com`
  - `selectedCalendarId` present
  - `calendarCount: 4`
- Outlook:
  - `connected: true`
  - `account: Rob Terry`
  - `selectedCalendarId` present
  - `calendarCount: 3`

## E2E Matrix Results
- Google / `both`: PASS
  - Artifacts: `/tmp/cs-regression/20260224-162328-phase3-google-both`
  - Pre noop: `true`, Apply noop: `true`, Post noop: `true`
- Google / `calendar`: PASS
  - Artifacts: `/tmp/cs-regression/20260224-162332-phase3-google-calendar`
  - Pre noop: `true`, Apply noop: `true`, Post noop: `true`
- Google / `fpp`: PASS
  - Artifacts: `/tmp/cs-regression/20260224-162336-phase3-google-fpp`
  - Pre noop: `true`, Apply noop: `true`, Post noop: `true`
- Outlook / `both`: PASS
  - Artifacts: `/tmp/cs-regression/20260224-162342-phase3-outlook-both`
  - Pre noop: `true`, Apply noop: `true`, Post noop: `true`
- Outlook / `calendar`: PASS
  - Artifacts: `/tmp/cs-regression/20260224-162347-phase3-outlook-calendar`
  - Pre noop: `true`, Apply noop: `true`, Post noop: `true`
- Outlook / `fpp`: PASS
  - Artifacts: `/tmp/cs-regression/20260224-162352-phase3-outlook-fpp`
  - Pre noop: `true`, Apply noop: `true`, Post noop: `true`

## Pending-Action Loop Check
- No unresolved pending-action loops were observed in the validated scenarios.
- All post-apply previews converged to noop as required.

## Screenshots / Video References
- Existing UI validation screenshots are in the development thread history.
- This phase evidence artifact is command/log based and authoritative from FPP runtime.

## Phase 3 Gate Decision
- [x] Full connect/status + preview/apply/converge paths validated on Google and Outlook.
- [x] All sync modes (`both`, `calendar`, `fpp`) validated on both providers.
- [x] Post-apply convergence confirmed for each validated scenario.
- [x] No unresolved pending-action loops in validated scenarios.

Result: **PASS**
