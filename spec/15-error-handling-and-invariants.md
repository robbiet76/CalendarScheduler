**Status:** STABLE  
> **Change Policy:** Intentional, versioned revisions only  
> **Authority:** Behavioral Specification v2

# 15 — Error Handling & Invariants

## Purpose

This section defines:
- Stable runtime error envelope behavior
- Fatal vs recoverable expectations
- Invariants that must hold for preview/apply paths
- Explicitly forbidden silent-fix behavior

## Runtime Error Envelope (Current Contract)

Controller errors returned by `ui-api.php` use:

```json
{
  "ok": false,
  "error": "human readable message",
  "code": "stable_machine_code",
  "hint": "actionable hint when available",
  "details": {}
}
```

Required:
- `ok` (false)
- `error`
- `code`

Optional:
- `hint`
- `details`

For `apply` and `auth_*` runtime failures, `details.correlationId` SHOULD be present.

## Error Code Families

Current primary families:
- `validation_error`
- `unknown_action`
- `runtime_error`
- `api_error` (fallback/internal)

## Hard Invariants

The following are non-negotiable:

1. Preview performs no persistent writes.
2. Apply executes only reconciliation-emitted executable actions.
3. One-way sync modes enforce directional apply safety.
4. Managed/unmanaged boundaries are preserved.
5. Unknown/invalid inputs fail explicitly (no silent mutation).

## Preview vs Apply Failure Semantics

### Preview
- May return `ok=true` with pending actions and diagnostics
- May return `ok=false` on runtime/config/provider failure
- Must not mutate schedule/provider state

### Apply
- Uses same planning/reconciliation path as preview
- Fails fast on invariant violations
- Returns correlated errors for runtime failures

## Recoverable Conditions (User-Actionable)

Examples:
- Missing required request field (`validation_error`)
- OAuth setup incomplete (hints in status/setup diagnostics)
- Temporary provider authorization state requiring reconnect

Recoverable conditions must still return explicit machine-readable error codes.

## Logging and Traceability Requirements

All runtime failures must be traceable via:
- API response error envelope
- server/log output

Correlated apply/auth runtime failures must map to:
- `details.correlationId` in API response
- matching `correlation_id` entry in logs

## Forbidden Behaviors

System MUST NOT:
- Swallow runtime errors and return success
- Auto-repair invalid user inputs silently
- Apply writes during preview
- Execute opposite-direction writes in one-way sync mode

## Summary

Error handling is strict and explicit:
- stable envelope
- explicit codes
- actionable hints
- correlated traceability for high-impact runtime paths
