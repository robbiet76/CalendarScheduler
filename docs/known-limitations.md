# Known Limitations And Expected Behavior

## OAuth Device Flow Dependency
- Initial connect requires access to Google device authorization.
- If token is revoked/expired, reconnect is required.

Expected behavior:
- Disconnect removes local token file.
- Reconnect recreates token via device flow.

## Sync Direction Is Mode-Scoped
- In one-way modes, opposite-direction writes are intentionally blocked.

Expected behavior:
- `Calendar -> FPP`: executable actions should target FPP only.
- `FPP -> Calendar`: executable actions should target calendar only.

## Preview/Apply Are State-Dependent
- Pending actions depend on current external state and timestamps.
- Rapid external edits during apply windows can produce additional follow-up actions.

Expected behavior:
- A second preview/apply pass may be needed to fully converge after concurrent edits.

## Diagnostics Are Operational, Not Historical
- `Diagnostics` reports current operational snapshot.
- It is not a long-term audit log.

Expected behavior:
- Use `correlationId` + `/home/fpp/media/logs/CalendarScheduler.log` for error tracing.

## Calendar API/Permissions Boundaries
- Calendar visibility and writable operations depend on OAuth account scopes and permissions.

Expected behavior:
- Missing permissions surface as API/runtime errors with hints.

## Packaging Scope Is Runtime-Only
- Development assets (specs, dev scripts, local mirrors) are intentionally excluded from runtime package.

Expected behavior:
- Release packages contain only runtime-required plugin files.
