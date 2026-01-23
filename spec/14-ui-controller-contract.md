**Status:** STABLE  
> **Change Policy:** Intentional, versioned revisions only  
> **Authority:** Behavioral Specification v2

# 14 — UI & Controller Contract

## Purpose

This section defines the **strict contract** between:

- The **User Interface (UI)**
- The **Controller**
- The **Planner and downstream pipeline**

It exists to ensure:
- Determinism
- Predictability
- Zero hidden logic
- Zero accidental coupling

The UI is *not* part of the scheduling system.  
The Controller is *not* part of the planning system.

**Note:** The UI models *relationships between systems* (e.g., Calendar ↔ FPP) rather than individual schedule entries. Users opt into calendar relationships and sync authority levels, not per-event actions.

The term ‘calendar’ is provider-agnostic. While Google Calendar is the initial provider, the contract supports future calendar sources without changing UI semantics.

---

## High-Level Responsibilities

### UI (User Interface)

The UI is responsible for **presentation and user intent capture only**.

It MUST:
- Allow users to connect exactly one calendar at a time
- Allow users to switch the active calendar relationship (e.g., different seasons, years, or providers), with exactly one active at any time
- Allow users to select a sync authority mode (Create-only, Create & Update, Full control) for each calendar relationship
- Trigger preview and apply actions for a given calendar relationship
- Display planner output and diff summaries
- Display errors exactly as returned

The UI enforces a single active calendar relationship. Multiple calendars may be configured over time, but only one may be active and authoritative at any given moment.

It MUST NOT:
- Infer intent
- Modify planner output
- Read or write scheduler data
- Perform reconciliation logic
- Perform identity logic
- Parse or mutate schedule.json
- Present or require per-event opt-in
## Sync Authority Modes

The following sync authority modes are available for each calendar relationship:
- Create-only
- Create & Update
- Full control (includes delete)

These modes gate which Diff operations may be applied to FPP for a given calendar relationship. They do **not** affect planning or preview generation.

---

### Controller

The Controller is the **orchestration boundary**.

It MUST:
- Accept requests from the UI
- Load configuration and runtime flags
- Invoke the Planner deterministically
- Route output to Preview or Apply paths
- Surface errors without modification

It MUST NOT:
- Modify planner output
- Repair invalid data
- Skip invariant checks
- Implement scheduling logic
- Read schedule.json directly

---

## Preview vs Apply Lifecycle

### Preview Mode

Preview mode:
- Executes the full planning pipeline
- Produces desired state and diff
- Performs **no writes**
- Is safe to run repeatedly

Preview MUST:
- Use identical logic to Apply
- Fail on the same invariants
- Surface identity issues clearly
- Output an *impact summary* suitable for user review (e.g., counts of creates, updates, deletes), without exposing raw scheduler entries.

---

### Apply Mode

Apply mode:
- Executes the same pipeline as Preview
- Applies the diff to FPP
- Is write-only with respect to FPP

Apply MUST:
- Be idempotent
- Fail fast on invariant violations
- Never re-plan or re-diff independently

---

## Dry Run Semantics

Dry run is a **controller-level flag**.

Rules:
- Dry run = Preview + Apply UI flow, but no persistence
- Planner output must be identical
- Diff output must be identical
- Only the final write step is suppressed

The UI must visually indicate dry-run mode.

---

## Data Flow Contract

```
UI
  ↓
Controller
  ↓
Planner
  ↓
Diff
  ↓
Apply (optional)
```

Rules:
- Data only flows downward
- No component reads upstream state
- No circular dependencies
- No implicit state sharing

---

## Error Handling

Errors:
- Must propagate upward unchanged
- Must never be swallowed
- Must never be “fixed” in the UI or Controller

The UI:
- Displays errors
- Does not interpret them

The Controller:
- Routes errors
- Does not transform them

---

## Forbidden Behaviors

The UI MUST NOT:
- Generate scheduler entries
- Assign identities
- Resolve times or dates
- Apply guard logic
- Expose Manifest Events, SubEvents, or internal identities as first-class UI objects

The Controller MUST NOT:
- Read schedule.json
- Compare scheduler entries
- Modify ordering
- Synthesize identities

---

## Summary

The UI & Controller contract enforces:

- Clean separation of concerns
- Deterministic planning
- Debuggable behavior
- No hidden state

All intelligence lives **below** this boundary.

Violating this contract invalidates the system design.
