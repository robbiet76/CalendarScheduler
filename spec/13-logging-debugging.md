> **Status:** STABLE  
> **Change Policy:** Intentional, versioned revisions only  
> **Authority:** Behavioral Specification v2

# 13 — Logging, Debugging & Diagnostics

## Purpose

Logging and diagnostics exist to make reconciliation/apply behavior:
- Observable
- Reproducible
- Supportable in production

Silent failure is a defect.

## Runtime Diagnostic Surfaces

Current production diagnostic surfaces are:

1. API response payloads from `ui-api.php`
2. `diagnostics` action payload (operational snapshot)
3. Correlated runtime error records in logs
4. Regression runner artifacts in `/tmp` (`bin/cs-*`)

## Diagnostics Payload Contract

`diagnostics` action returns a stable object with:
- `syncMode`
- `selectedCalendarId`
- `counts`
- `pendingSummary`
- `lastError`
- `previewGeneratedAtUtc`

This payload is for current operational state, not historical audit replay.

## Error Correlation Contract

For runtime failures in:
- `apply`
- `auth_*`

the response details SHOULD include:
- `correlationId`

and logs MUST include matching correlated entries.

Current correlated file:
- `/home/fpp/media/logs/CalendarScheduler.log`

Log entry shape (minimum):
- timestamp
- action
- `correlation_id`
- error summary

## Logging Responsibility Boundaries

Layer ownership:
- UI/API controller: request lifecycle, validation failures, correlated runtime errors
- Engine/planner/diff/apply layers: domain decisions and mutation summaries
- Provider adapters: provider/API-specific diagnostics and error context

Cross-layer log mutation is forbidden.

## Preview vs Apply Observability

Preview:
- Must expose full pending-action and count visibility
- Must not mutate scheduler/provider state

Apply:
- Must fail fast on invariant violations
- Must preserve error correlation and traceability

## Regression/Debug Artifacts

The supported reproducibility path is runner-driven:
- `bin/cs-resolution-regression`
- `bin/cs-api-smoke`
- `bin/cs-full-regression`

Artifacts are emitted to `/tmp` under runner-defined directories and serve as primary validation evidence.

## Security and Data Handling

Diagnostics/logs MUST NOT expose OAuth secrets or token contents.

Allowed in logs/diagnostics:
- IDs, action names, counts, correlation IDs, normalized errors

Forbidden:
- raw credential payloads
- token secret material

## Summary

Production supportability is guaranteed through:
- stable diagnostics payload keys
- stable error envelopes
- correlation IDs for high-impact runtime failures
- reproducible runner artifacts for regression evidence
