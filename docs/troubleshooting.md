# Calendar Scheduler Troubleshooting

Use this guide together with the `Diagnostics` panel in the UI.

## Diagnostics Key Map
- `syncMode`: active mode (`both`, `calendar`, `fpp`)
- `selectedCalendarId`: currently selected Google calendar
- `counts`: preview action totals by target/type
- `pendingSummary`: condensed pending actions view
- `lastError`: most recent meaningful runtime/setup error

## Symptom: Cannot Connect Provider
Check:
1. In `Connection Setup`, confirm setup checks are all `OK`.
2. Verify `Diagnostics.lastError` and setup hints.

Common causes:
- Missing or invalid client JSON
- Token/config directory not writable
- OAuth credential type not suitable for device flow

Fix:
1. Re-upload OAuth client JSON (`TV and Limited Input`).
2. Retry `Connect Provider`.

## Symptom: Device Auth Poll Fails
Expected API behavior:
- Returns `ok=false`, `code=runtime_error`, and `details.correlationId`.

Check:
1. UI error message for auth failure.
2. `Diagnostics.lastError`.
3. Correlated log entry in `/home/fpp/media/logs/CalendarScheduler.log` using `correlationId`.

Fix:
1. Restart device flow (`Connect Provider`).
2. Enter latest code at `google.com/device`.

## Symptom: Apply Button Disabled
Check:
1. `pendingSummary.totalPending` in diagnostics.
2. `Pending Actions` list.

Expected:
- Apply is enabled only when non-noop actions are present.

Fix:
1. If no actions are pending, this is normal.
2. If actions are expected, refresh and re-run preview.

## Symptom: Unexpected Direction Of Changes
Check:
1. `Diagnostics.syncMode`.
2. Target column in pending actions.

Fix:
1. Set desired sync mode.
2. Preview again before apply.

Mode behavior summary:
- `calendar`: only FPP-target actions should be executable.
- `fpp`: only calendar-target actions should be executable.
- `both`: both directions allowed.

## Symptom: Status Shows Disconnected After Previously Connected
Check:
1. `Diagnostics.lastError`
2. `Connection Setup` checks (`tokenFilePresent`, `deviceFlowReady`)

Likely cause:
- Token removed via disconnect or expired/revoked OAuth grant.

Fix:
1. Click `Connect Provider` and re-authorize.
2. Confirm `Connected Account` returns.

## Symptom: Repeated Pending Actions After Apply
Check:
1. `Diagnostics.counts`
2. `pendingSummary.sample`
3. Sync mode and target direction

Fix:
1. Ensure authoritative side has stable data.
2. Re-run preview/apply once more.
3. If persistent, capture:
   - Diagnostics JSON
   - Correlation IDs from recent errors
   - FPP log excerpt

## Escalation Bundle
When opening an issue, include:
1. `Diagnostics` JSON
2. Current sync mode
3. Steps performed
4. `correlationId` (if present)
5. Relevant lines from `/home/fpp/media/logs/CalendarScheduler.log`
