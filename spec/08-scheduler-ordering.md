**Status:** STABLE  
> **Change Policy:** Intentional, versioned revisions only  
> **Authority:** Behavioral Specification v2

# 08 — Scheduler Ordering Model

## Purpose

This section defines **how scheduler entries are ordered** before being written to FPP.

Ordering is not cosmetic. In FPP, **ordering determines runtime dominance** when schedules overlap. A correct ordering model is therefore required to ensure that:

- Later-intended schedules actually run
- Seasonal overrides behave correctly
- Higher-priority intent is not starved by background entries

This document defines *what ordering must do* — not how it is implemented.

---

## Fundamental Ordering Constraints

1. **Ordering is global**  \
   Scheduler entries are evaluated top-to-bottom by FPP.

2. **Earlier entries have higher priority**  \
   When two entries overlap, the one appearing earlier in the schedule takes precedence.

3. **Ordering must be deterministic**  \
   Given the same Manifest, ordering must always produce the same result.

4. **Ordering must be explainable**  \
   Every ordering decision must be traceable to an explicit rule.

---

## Managed vs Unmanaged Entries

The scheduler consists of two conceptual groups of entries:

### Unmanaged Entries

Unmanaged entries are scheduler entries that:

- Are not represented in the Manifest
- Were created manually or by another system
- Are not controlled by the calendar integration

**Ordering rules for unmanaged entries:**

- All unmanaged entries appear **before** managed entries
- Unmanaged entries maintain their **existing relative order**
- Unmanaged entries are treated as a single, immutable block
- The planner **must never reorder unmanaged entries**

> **Invariant:** Unmanaged entries always take priority over managed entries.

---

### Managed Entries

Managed entries are derived from Manifest Events and their SubEvents.

- Only managed entries participate in ordering logic
- Managed entries may be reordered relative to each other
- Managed entries are appended *after* the unmanaged block

---

## Atomic Ordering Units

### SubEvents Are Atomic

Ordering is applied at the **Manifest Event** level, not individual scheduler entries.

- A Manifest Event produces one or more SubEvents
- SubEvents **must never be reordered internally**
- SubEvents move as a single atomic unit during ordering

> **Invariant:** If any SubEvent moves, all SubEvents move together.

---

## Why Reordering Is Required

Calendar intent does not map directly to FPP execution semantics.

Examples that require reordering:

- A later-starting nightly show must override an earlier ambient playlist
- A seasonal schedule must override a year-round baseline
- A holiday exception must override its base schedule

Simple chronological ordering is insufficient.

---

## Ordering Model Overview

Ordering proceeds in **two distinct phases**:

1. **Baseline Chronological Ordering**
2. **Dominance Resolution Passes**

This ensures clarity, determinism, and correctness.

---

## Phase 1 — Baseline Chronological Ordering

Managed Manifest Events are first ordered by:

1. Start date (earlier first)
2. Daily start time (earlier first)
3. Type (playlist / command / sequence)
4. Target (lexical)

Baseline chronological ordering operates on **effective ordering keys**, which may be symbolic or concrete.

Symbolic dates (e.g., "Thanksgiving", "Christmas") are ordered **relative to other symbolic dates only** and are never resolved to a specific calendar year during ordering. Ordering must not introduce or assume a concrete year.

Type and target ordering in this phase serve **deterministic tie-breaking only** and must not encode semantic priority. All semantic conflict resolution is performed exclusively during dominance resolution.

> This phase does *not* attempt to resolve conflicts.

---

## Phase 2 — Dominance Resolution

After baseline ordering, dominance rules are applied iteratively.

Dominance rules may move a Manifest Event **above** another *only if they overlap*.

### Overlap Definition

Event timing for overlap and dominance evaluation is derived from the **aggregate effective timing shape** of all SubEvents (base and exceptions).
Individual SubEvents are never evaluated independently.

The aggregate effective timing shape represents the union of all SubEvent timing constraints and reflects the full execution footprint of the Manifest Event.

Two Manifest Events overlap if:

- Their date ranges intersect (exclusive of touching edges)
- Their day masks intersect
- Their daily time windows intersect (including overnight wrap)

If overlap cannot be conclusively disproven due to symbolic boundaries or unresolved timing components, events are treated as **potentially overlapping** for the purposes of dominance evaluation. The planner must prefer conservative dominance over speculative non-overlap.

If no overlap exists, **ordering must not change**.

---

## Dominance Rules (in priority order)

### Rule 1 — Later Daily Start Time Wins

If two overlapping events occur on the same day:

- The event with the **later effective daily start time**, as determined from the aggregate effective timing shape of its SubEvents, dominates

The effective daily start time of a Manifest Event is defined as the **latest daily start time produced by any of its SubEvents**, after normalization.

Rationale:
- Later schedules are intentional overrides
- Early schedules represent background layers

Effective daily start time may be concrete or symbolic (e.g., "Dawn", "Dusk") and may include offsets. Comparison is semantic, not resolutive: symbolic times are compared symbolically and are not converted to concrete clock times during ordering.

---

### Rule 2 — Later Calendar Start Date Wins (Same Start Time)

If two overlapping events have:

- The same daily start time
- Different calendar start dates

Then:

- The event with the **later effective calendar start date** (after SubEvent normalization) dominates

Rationale:
- Seasonal overrides must replace earlier seasons

---

### Rule 3 — Prevent Start-Time Starvation

If placing Event A above Event B would prevent Event B from ever starting at its intended first occurrence, as determined from the aggregate effective timing shape of Event B:

- Event A dominates

Rationale:
- Events must be able to start at least once

This rule ensures that an event’s execution footprint is not completely eclipsed by a dominant event that overlaps all of its possible start opportunities.

If an event’s first occurrence cannot be concretely determined due to symbolic timing, starvation prevention is evaluated conservatively without resolving symbolic values to specific dates.

---

## Iterative Stabilization

Dominance resolution is applied repeatedly until:

- No further swaps occur, or
- A maximum pass limit is reached

The final order must be:

- Stable
- Deterministic
- Repeatable

---

## Explicitly Forbidden Heuristics

The following are **not allowed**:

- Reordering based on scheduler index
- Reordering based on creation time
- Reordering based on UID
- Random or hash-based ordering
- Provider-specific ordering rules
- Manual user ordering overrides

---

## Guarantees

- Unmanaged entries always remain first
- Managed entries never override unmanaged entries
- Manifest Events remain atomic
- Ordering decisions are deterministic
- Ordering is derived solely from Manifest semantics

---

## Non-Goals

- Allowing users to manually reorder managed entries
- Preserving historical ordering artifacts
- Optimizing for minimal diff size

Correctness always outweighs minimal change.
