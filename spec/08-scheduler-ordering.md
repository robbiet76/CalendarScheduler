**Status:** STABLE
> **Change Policy:** Intentional, versioned revisions only
> **Authority:** Behavioral Specification v2

# 08 — Scheduler Ordering Model

## Purpose

This section defines how scheduler entries are ordered before being written to FPP.

Ordering is execution semantics, not display-only metadata. In FPP, earlier rows win when timing overlaps. The ordering model must therefore guarantee:

- Correct runtime winner selection
- Deterministic, explainable output
- Round-trip stability between FPP and calendar projections

This document defines what ordering must do, not a specific algorithm implementation.

---

## Core Principles

1. Ordering is global.
2. Ordering is deterministic for identical input.
3. Ordering is explainable using explicit precedence rules.
4. Ordering preserves complete intent: overlapping entries remain represented.
5. Calendar-side reorder attempts are not authoritative in current behavior.

---

## Ordering Units

### Bundle Is the Atomic Unit

Ordering decisions are made at bundle scope.

- A bundle is the atomic group derived from one logical event stream.
- Bundles move as units in global ordering.
- Bundle rows remain contiguous in final FPP output.

### Subevent Ordering Inside a Bundle

Rows inside one bundle follow these rules:

1. If rows overlap in active scope, more specific override rows must be above broader/base rows.
2. If rows do not require precedence, order chronologically.
3. If still tied, use deterministic tie-breakers.

---

## Why Chronological-Only Is Insufficient

Pure chronological ordering fails in valid runtime scenarios:

- Later nightly show overriding an earlier background row
- Seasonal override over year-round base
- Symbolic-time handoff windows
- Narrow exception windows inside broader coverage

Chronology is the default, not the full rule system.

---

## Global Ordering Pipeline

Ordering executes in two phases:

1. Baseline chronological placement
2. Overlap-aware precedence resolution

The result is a stable total order of all subevents with absolute `executionOrder` values.

---

## Phase 1 — Baseline Chronological Placement

Initial order is built from effective timing keys:

1. Effective start date
2. Effective start time
3. Effective end date
4. Effective end time
5. Deterministic identity tie-breakers

Notes:

- Symbolic timing/date tokens remain symbolic-first for identity semantics.
- This phase does not settle overlap dominance; it only provides a deterministic baseline.

---

## Phase 2 — Overlap-Aware Precedence Resolution

After baseline placement, precedence is resolved across overlapping bundles.

### Overlap Gate

A precedence decision is only allowed when overlap exists or cannot be safely disproven.

Treat as potentially overlapping when symbolic boundaries prevent definitive disproof.

### Precedence Rules (Priority Order)

1. Later daily start wins.
   For overlapping bundles, the row with later effective daily start is placed above.

2. Later calendar start date wins when daily start is equal.
   This preserves expected seasonal replacement behavior.

3. Specificity wins.
   A narrower active footprint (date/day/time constrained window) is placed above a broader footprint when they overlap and stronger time/date precedence does not already decide the relation.

4. Starvation guard.
   Reject any precedence decision that would make the lower bundle un-runnable within its own active scope.

5. Deterministic tie-breakers.
   If still tied, use stable lexical/hash identity tie-breakers only.

### Non-Overlap Rule

If bundles do not overlap, keep chronological order.

---

## Execution Order Assignment

After final global ordering is resolved:

1. Flatten bundles into contiguous subevent rows.
2. Assign absolute `executionOrder` values (`0..N-1`).
3. Persist those values as executable state metadata.

`executionOrder` is state, not identity.

---

## Authority and Manual Reorder Policy

Current policy:

1. Canonical ordering is enforced by the scheduler.
2. Manual FPP reorder is treated as drift from canonical order.
3. Drift appears as updates; apply restores canonical order.
4. Manual reorder authority is a future optional capability, not current behavior.

---

## Forbidden Heuristics

The following are forbidden as ordering authorities:

- Provider row/index order
- Creation timestamps
- Random or hash-only ordering without semantic rules
- UID-only precedence
- Calendar-side reorder metadata as authoritative intent

---

## Guarantees

1. Overlap intent is preserved on both sides.
2. Bundle atomicity and contiguity are preserved.
3. Ordering is deterministic and explainable.
4. Ordering converges after apply.

---

## Non-Goals

- UI-driven manual ordering controls
- Diff minimization at the expense of correctness
- Hiding lower-priority overlaps from calendar projections

Correct execution semantics take precedence over minimal mutation size.
