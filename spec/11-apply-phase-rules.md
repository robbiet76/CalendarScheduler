**Status:** STABLE  
> **Change Policy:** Intentional, versioned revisions only  
> **Authority:** Behavioral Specification v2

# 11 — Apply Phase Rules

## Purpose

The **Apply Phase** is responsible for **executing an approved DiffResult** against an external provider write target (e.g., the FPP scheduler, Google Calendar via API).

It answers one question only:

> **“Given an approved diff, how do we write it to FPP safely, deterministically, and idempotently?”**

The Apply phase is **procedural**, **write-only**, and **non-decisional**.

---

## Scope & Authority

The Apply phase operates under strict constraints:

- **Input:** A validated `DiffResult`
- **Output:** Mutated provider state (e.g., FPP scheduler state and/or calendar provider state)
- **Authority:** Execution only — never interpretation

The Apply phase does **not** decide *what* should change — only *how* to apply changes already decided.

---

## Inputs

The Apply phase accepts:

- `DiffResult` (creates / updates / deletes)
- Provider target configuration (which single calendar account/calendar is connected; which provider is authoritative is determined upstream)
- Diff operations are event-atomic units (e.g., `PlannedEvent`), each of which may expand to multiple scheduler entries via SubEvents (scheduler entries correspond to SubEvents, e.g., FPP schedule rows)
- Runtime flags:
  - `dry_run`
  - `debug`

The Apply phase must **never** accept:

- Manifest data directly (except as already materialized into DiffResult operations)
- Planner output directly
- Calendar data
- Identity construction inputs

---

## Provider Interaction Model

Apply interacts with providers in **write-only / non-semantic mode**.

Rules:

- Apply may perform **minimal provider reads** only when required for safe writes:
  - Concurrency tokens (e.g., Google `etag`)
  - Provider object IDs needed to address an update/delete
  - Connectivity/authorization validation
- Apply must **not** read provider state to make *semantic* decisions:
  - No matching
  - No identity construction
  - No intent reconstruction
  - No reconciliation

All reconciliation decisions are finalized before Apply begins.

If Apply needs to interpret provider content to decide what to do, that is a design error upstream.

Note that for scheduler-style providers (e.g., FPP), Apply expands Manifest Events into multiple SubEvents (scheduler entries) for writing, whereas for Google Calendar provider, writes operate at the Manifest Event level without SubEvent expansion.

---

## Execution Order

Apply MUST process operations in the following strict order:

1. **Deletes**
2. **Updates**

Updates are applied atomically at the event level: if any SubEvent-derived scheduler entry changes, all entries for that event must be rewritten together; partial SubEvent updates are forbidden.

3. **Creates**
4. **Final ordering enforcement**

Rationale:

- Deletes remove obsolete managed objects
- Updates preserve identity continuity
- Creates introduce new entries cleanly
- Ordering is applied last to avoid transient instability

Note that Apply operates on Manifest Events as atomic units, but writes execution via SubEvents (scheduler entries).

---

## Managed Boundary and Adoption

Apply MUST only mutate objects that are within the **CS-managed set**.

- “Managed” is a **steady-state internal implementation concept**, not a user-facing feature, representing the set of objects under control after initial adoption.
- On first sync/adoption, existing provider objects that participate in scheduling are **adopted** into the managed set, representing a one-time boundary transition.
- After adoption, the expected steady state is: **all schedulable objects in the connected scope are managed**.

Enforcement:

- Apply MAY create/update/delete/reorder **managed** objects.
- Apply MUST NOT mutate objects outside the managed scope.
- If an operation would affect an out-of-scope object, Apply MUST fail fast.

Scope is determined upstream (connection target + adoption state) and supplied to Apply via DiffResult/provenance.

---

## Ordering Enforcement

Ordering enforcement is the **final step** of Apply.

Rules:

- Managed entries are written in Planner-defined order
- Entries derived from a single Manifest Event (base + exception SubEvents) must remain grouped and must never interleave with entries from another event
- Out-of-scope objects are not reordered by Apply
- Ordering decisions must be fully determined by the DiffResult snapshot and must not require semantic inspection of provider state
- No ordering heuristics are permitted

Ordering must be:

- Explicit
- Deterministic
- Repeatable

---

## Dry Run Behavior

When `dry_run` is enabled:

- **No writes** to FPP may occur
- All operations are simulated
- The full execution plan must still be generated

Dry run must:

- Exercise the exact same code paths
- Surface the same errors
- Differ only in the absence of side effects

---

## Idempotency Guarantees

Apply MUST be idempotent.

Given the same `DiffResult`:

- Applying twice must yield the same scheduler state
- No duplicate entries may be created
- No identity drift may occur

Violations of idempotency are critical defects.

---

## Provider Concurrency and Addressing

Apply MUST use provider-safe mechanisms to ensure updates are precise and repeatable:

- Google Calendar API:
  - Updates/deletes MUST be addressed by provider event ID at the Manifest Event level
  - If available, writes SHOULD use `etag` (or equivalent) to prevent overwriting concurrent changes
- FPP scheduler:
  - Writes MUST be deterministic and derived only from the DiffResult
  - When full-file writes are used (e.g., rewriting `schedule.json`), the output must be stable across runs

Concurrency handling is **mechanical** only. Any conflict policy (which side wins) is decided upstream.

---

## Error Handling

Apply MUST fail fast on:

- Invalid DiffResult structure
- Missing identity on managed entries
- Attempts to modify unmanaged entries
- Partial write failures

Apply MUST NOT:

- Continue after a fatal write error
- Attempt recovery heuristics
- Mask or downgrade errors

All errors must be explicit and surfaced to the controller.

---

## Logging & Diagnostics

When debugging is enabled, Apply MUST log:

- Each operation (create / update / delete)
- Entry identity and target
- Final ordering snapshot
- Any write failures

Logs must:

- Be non-invasive
- Never affect behavior
- Never mutate data

---

## Forbidden Behaviors

The Apply phase MUST NOT:

- Recompute identity
- Infer intent
- Reorder entries heuristically
- Read calendar data
- Read Manifest data
- Repair invalid entries
- Perform compatibility shims
- Decide conflict policy for concurrent edits
- Invent provider identifiers or attempt fuzzy matching

If Apply “fixes” something, it is a bug upstream.

---

## Summary

The Apply phase is:

- Procedural
- Write-only
- Idempotent
- Deterministic
- Strictly bounded

It is the **last step** in the pipeline and must never contain business logic.

