# Phase 2.2 Planner Core — Architecture & Execution Handoff

**Status:** LOCKED  
**Audience:** Architect / Project Manager  
**Purpose:** Provide a clean, authoritative handoff for implementing Phase 2.2 (Planner Core) in one controlled pass, while preserving and reusing validated V1 helper logic.

---

## 1. Context & Problem Statement

Phase 2 introduced a **new Manifest-centric architecture**. During early Phase 2.2 attempts, implementation drift occurred due to:

- Incremental scaffolding instead of full-file delivery
- Mixing architectural intent with low-level coding decisions
- Rebuilding logic that already exists and is production-hardened in V1
- Over-validating data paths that are already guaranteed clean (FPP side)

This document resets Phase 2.2 with a **clear execution contract**.

---

## 2. Guiding Principles (Non-Negotiable)

### 2.1 Role Separation

- **Architect / PM (You):**
  - Owns behavior, invariants, data contracts
  - Reviews structure, not code mechanics
  - Does *not* validate PHP syntax or implementation details

- **Implementation (ChatGPT):**
  - Delivers *complete, working files*
  - No partial scaffolds
  - No speculative abstractions
  - No re-architecture during coding

---

### 2.2 “One-Pass File Rule”

For Phase 2.2:

- Each file is written **once**, fully
- No placeholder methods
- No TODO blocks
- No “we’ll fill this in later”
- If a dependency is required, it must already exist or be written in the same pass

If this cannot be done → **stop and redesign before coding**.

---

## 3. Phase 2.2 Scope (Planner Core)

### 3.1 What Phase 2.2 *Does*

The Planner Core:

- Consumes a **valid Manifest**
- Produces a **deterministic, ordered plan**
- Emits **PlannedEntry objects**
- Does **no I/O**
- Does **no FPP writing**
- Does **no diffing**

Planner output is *pure intent*, ready for Diff + Apply.

---

### 3.2 What Phase 2.2 Explicitly Does NOT Do

The Planner must **not**:

- Read or write `schedule.json`
- Repair malformed calendar data
- Guess intent
- Enforce provider-specific rules
- Apply scheduler guards
- Mutate identities
- Log extensively (minimal debug hooks only)

Hard failures are acceptable when invariants are violated.

---

## 4. Core Planner Files (Phase 2.2 Deliverables)

These files must be delivered **together**, in one pass:

```
src/Planner/
├── Planner.php
├── PlannerResult.php
├── PlannedEntry.php
├── OrderingKey.php
```

### 4.1 PlannedEntry

Represents **one FPP scheduler entry** derived from:

- One Manifest Event
- One SubEvent

Must include:

- eventId
- subEventId
- identityHash
- target (playlist / command / sequence)
- timing (already normalized)
- orderingKey (value object)

No business logic beyond construction and validation.

---

### 4.2 OrderingKey

Encodes **total ordering** rules defined in spec §08.

Characteristics:

- Comparable (lexicographically sortable)
- Immutable
- Computed at construction
- Contains:
  - Managed vs unmanaged priority
  - Event-level order
  - SubEvent order
  - Start-time ordering

No external dependencies.

---

### 4.3 PlannerResult

A simple container:

- `entries: PlannedEntry[]`
- Already sorted
- No lazy evaluation
- No recomputation

---

### 4.4 Planner

Responsibilities:

- Iterate manifest events
- Expand subEvents
- Construct PlannedEntry objects
- Apply OrderingKey
- Sort deterministically
- Return PlannerResult

Planner must **trust** the ManifestStore invariants.

---

## 5. V1 Helper Reuse Strategy

### 5.1 Critical Principle

> **We are not rewriting solved problems.**

V1 contains production-hardened helpers that remain valid.

We will **reuse**, not reimplement.

---

### 5.2 Candidate Helpers for V2 Reuse

From `/archive/v1/src/Core`:

| Helper | Status | Notes |
|------|-------|------|
| HolidayResolver | ✅ Reuse | Canonical, well-tested |
| FPPSemantics | ✅ Reuse | Remains authoritative |
| SunTimeEstimator | ✅ Reuse | No Manifest coupling |
| IcsParser | ✅ Reuse | Calendar I/O only |
| IcsWriter | ✅ Reuse | Export symmetry |
| YamlMetadata | ✅ Reuse | Lightweight |
| ScheduleEntryExportAdapter | ✅ Reuse | Export-only path |

These should be **moved (not copied)** into appropriate V2 folders when needed.

---

### 5.3 Helpers We Do NOT Bring Forward

- SchedulerPlanner (V1)
- SchedulerDiff (V1)
- SchedulerComparator
- Inventory / Snapshot logic

These are replaced by Manifest + Planner.

---

## 6. Error Handling Philosophy (Planner Scope)

- Assume **Manifest is valid**
- Assume **FPP data is clean**
- Treat calendar/provider errors as fatal unless intent is unambiguous
- Prefer **hard failure over silent correction**
- Minimal validation inside Planner

Validation belongs to:
- ManifestStore
- IdentityCanonicalizer
- Provider ingestion

---

## 7. Folder Structure Validation

Current V2 structure is sufficient.

Helpers should live under:

```
src/Core/        ← semantics, identity, manifest
src/Planner/     ← planning only
src/Calendar/    ← ingestion / export (future)
```

No new top-level folders required for Phase 2.2.

---

## 8. Execution Contract for Next Chat

When starting Phase 2.2:

1. Reference this document explicitly
2. Implement **all Planner files in one pass**
3. Do not touch ManifestStore or Identity logic
4. Do not introduce new abstractions
5. Ask questions **before coding**, not during

Suggested opening line:

> “Proceed with Phase 2.2 Planner Core per HANDOFF_PHASE_2_2.md.  
> Deliver all Planner files in one pass.”

---

## 9. Definition of Success

Phase 2.2 is complete when:

- Planner produces deterministic output
- Ordering matches spec exactly
- No V1 logic is reimplemented unnecessarily
- No Planner file requires follow-up edits

---

**This document is authoritative until Phase 2.2 is complete.**
