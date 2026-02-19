**Status:** STABLE  
> **Change Policy:** Intentional, versioned revisions only  
> **Authority:** Behavioral Specification v2

# 14 — UI & Controller Contract

## Purpose

This section defines the strict contract between:
- UI (`content.php`)
- Controller/API endpoint (`ui-api.php`)
- Planner/diff/apply pipeline (`src/*`)

It exists to enforce deterministic behavior, clear error propagation, and zero hidden scheduling logic in UI code.

## UI Responsibilities

UI is presentation and user-intent capture only.

UI MUST:
- Show provider connection/setup state
- Allow client-secret upload and provider connect/disconnect
- Allow selecting active calendar and sync mode
- Trigger `status`, `diagnostics`, `preview`, and `apply`
- Render pending actions and diagnostics payloads
- Display backend errors without rewriting semantics

UI MUST NOT:
- Compute reconciliation decisions
- Mutate scheduler data directly
- Synthesize identities or timestamps
- Implement planner/diff/apply logic

## Controller Responsibilities

`ui-api.php` is the orchestration boundary.

Controller MUST:
- Accept request actions and validate inputs
- Execute preview/apply through shared pipeline
- Return stable response envelopes
- Preserve fail-safe behavior and actionable hints

Controller MUST NOT:
- Reorder planner output ad hoc
- Hide invariant violations
- Bypass apply safety rules

## Action Contract

Current UI-facing actions are:
- `status`
- `diagnostics`
- `set_calendar`
- `set_sync_mode`
- `set_ui_pref`
- `preview`
- `apply`
- `auth_device_start`
- `auth_device_poll`
- `auth_upload_device_client`
- `auth_disconnect`
- `auth_exchange_code` (manual fallback path)

Unknown actions MUST return:
- `ok=false`
- `code=unknown_action`
- `error`
- `hint`

## Sync Mode Contract

Supported sync modes:
- `both`
- `calendar` (Calendar -> FPP)
- `fpp` (FPP -> Calendar)

Behavior:
- `preview` and `diagnostics` always compute with selected mode
- `apply` enforces directional safety:
  - `calendar`: executable writes to FPP only
  - `fpp`: executable writes to calendar only
  - `both`: writes may target both sides

## Preview vs Apply

### Preview
- Runs full pipeline with no persistent writes
- Returns stable preview payload:
  - `noop`
  - `generatedAtUtc`
  - `counts`
  - `actions`
  - `syncMode`

### Apply
- Runs same planning path as preview
- Applies executable actions via apply layer
- Returns:
  - `applied` count summary
  - post-apply `preview`

## Diagnostics Contract

`diagnostics` action MUST return a stable object containing:
- `syncMode`
- `selectedCalendarId`
- `counts`
- `pendingSummary`
- `lastError`
- `previewGeneratedAtUtc`

Diagnostics is operational state, not long-term audit history.

## Error Handling Contract

Error envelope for failures:
- `ok=false`
- `error`
- `code`
- optional `hint`
- optional `details`

For `apply` and `auth_*` runtime failures, `details` SHOULD include:
- `correlationId`

Correlated errors MUST be traceable to logs.

## Dry-Run Expectations

Preview path is safe to run repeatedly and is used as the no-write validation path.

No UI-side dry-run flag should alter planning semantics independently of controller behavior.

## Forbidden Behaviors

UI/Controller MUST NOT:
- Treat unmanaged scheduler rows as managed by implication
- Rewrite planner output for convenience
- Silently swallow provider/API errors
- Introduce hidden state outside config/runtime files and explicit responses

## Summary

All scheduling intelligence remains below UI/controller boundaries.  
UI collects intent and renders results.  
Controller validates, orchestrates, and returns stable contracts.
