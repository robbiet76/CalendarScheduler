**Status:** STABLE  
> **Change Policy:** Intentional, versioned revisions only  
> **Authority:** Behavioral Specification v2

# 03 — Manifest

## Purpose

The **Manifest** is the central, authoritative semantic model for the system.

It represents the *truth* about:

- What exists in the scheduler
- Why it exists
- Where it came from
- How it should be compared, ordered, reverted, and explained

All inbound (calendar → scheduler) and outbound (scheduler → calendar) flows are mediated through the Manifest. No other file or data structure is allowed to encode identity or ownership semantics.

Backwards compatibility is **explicitly not a goal**. The Manifest is free to evolve based on correct understanding.

---

## Core Principles

1. **Manifest-first architecture**  \
   The Manifest is the single source of truth. Scheduler entries and calendar events are projections.

2. **Event-centric model**  \
   Every calendar event produces exactly one Manifest Event. All execution details are derived from it.

3. **No persistence in FPP**  \
   The Manifest is *not* stored in `schedule.json`. FPP stores only executable scheduler entries.

4. **Symbolic preservation**  \
   Symbolic dates and times (Dawn, Dusk, Holidays, DatePatterns) are preserved semantically and resolved only at the FPP interface layer.

5. **Dual date preservation**  \
   When a concrete (hard) date is provided, it is always preserved. If that date resolves to a known holiday, the symbolic holiday representation is stored in addition. Date semantics are never collapsed or replaced.  
   Hard dates and symbolic dates are never inferred from FPP output; preservation occurs only during calendar ingestion.

6. **Atomic execution units**  \
   Execution details are grouped into SubEvents that are always applied and ordered atomically.

---

## Manifest Event (Top-Level Unit)

A **Manifest Event** is the authoritative semantic representation of exactly one calendar event.

> **Invariant:** `1 Calendar Event → 1 Manifest Event`

Manifest Events are the unit of:

- Identity
- Diffing
- Ordering
- Apply
- Revert

```ts
ManifestEvent {
  uid: UID,
  id: string,
  hash: string,
  identity: IdentityObject,
  intent: IntentObject,
  subEvents: SubEvent[],
  ownership: OwnershipObject,
  status: StatusObject,
  provenance: ProvenanceObject,
  revert?: RevertObject
}
```

---

## UID (Provider Trace Identifier)

```ts
UID {
  provider: string, // e.g. "google", "ics", "manual"
  value: string
}
```

Rules:

- UID exists for **traceability only**
- UID is never used for equality or diffing
- UID may collide across providers
- UID may be absent for manually created Manifest Events

---

## IdentityObject (Semantic Equality)

The **IdentityObject** defines semantic equality for Manifest Events.

It answers:

> **“Are these two Manifest Events the same scheduler intent?”**

Identity is:

- Deterministic
- Provider-agnostic
- Stable across time
- Year-invariant

```ts
IdentityObject {
  type: "playlist" | "command" | "sequence",
  target: string,
  days: string,
  start_time: TimeToken,
  end_time: TimeToken
}
```

### Identity Invariants

- Identity fields must be fully specified and non-null after ingestion.
- Identity must not include stopType, repeat, enabled flags, or any execution-only settings.
- Identity explicitly excludes all date semantics, including DatePattern, hard dates, symbolic dates, and year-specific constraints.
- Identity must be provider-agnostic.

Rules:

- Identity excludes operational settings (e.g. stopType, repeat)
- Identity excludes resolved dates
- Identity excludes provider artifacts
- Changing any Identity field creates a *new* Manifest Event identity

---

## IntentObject (User Desire)

The **IntentObject** captures what the user expressed.

It answers:

> **“What behavior did the user intend?”**

```ts
IntentObject {
  type: "playlist" | "command" | "sequence",
  target: string,
  args?: string[],
  sequence?: number,
  enabled: boolean,
  timing: TimingObject
}
```

Rules:

- Intent may be symbolic or open-ended
- Intent may differ from Identity during preview or revert
- Intent is preserved for UI and calendar round-tripping

---

## SubEvents (Executable Decomposition)

A **SubEvent** is an executable component derived from a Manifest Event.

SubEvents exist because not all calendar intent maps cleanly to a single FPP scheduler entry.

SubEvents represent FPP-required execution decomposition but remain part of the Manifest because they are derived intent, not FPP state.

1 SubEvent ≠ 1 IdentityObject; identity exists only at the Manifest Event level.

```ts
SubEvent {
  role: "base" | "exception",
  entry: FppScheduleEntry
}
```

Rules:

- Every Manifest Event has **one or more SubEvents**
- Exactly one SubEvent has `role: "base"`
- Zero or more SubEvents may have `role: "exception"`
- SubEvents have **no independent identity**
- SubEvents are never diffed, ordered, or applied independently
- All SubEvents inherit the parent Manifest Event Identity verbatim; SubEvents never define or modify identity fields.

---

## DatePattern (Intent-Level Date Semantics)

The Manifest preserves both **concrete** and **symbolic** date intent.

DatePattern supports dual representation when applicable.

```ts
DatePattern {
  hard?: string,      // "YYYY-MM-DD" or pattern forms like "0000-MM-DD", "YYYY-00-DD"
  symbolic?: string   // Holiday token as defined by FPP (e.g. "Christmas", "Thanksgiving")
}
```

### DatePattern Resolution Rules

- Hard date → holiday resolution occurs only if the hard date exactly matches an FPP-defined holiday.
- Resolution is performed during ingestion, not during planning or apply.
- DatePattern supports all FPP `0000-XX-XX` and `YYYY-00-XX` repeating semantics.

Rules:

- A hard date is always preserved if provided by the source
- When a hard date resolves to a known FPP holiday, the symbolic value is stored in addition
- If only a symbolic date is provided, only `symbolic` is stored
- Symbolic dates are never invented if no resolver match exists
- DatePattern is immutable after ingestion
- Expansion into executable dates occurs only during Apply

DatePattern applies to SubEvent timing and intent realization, not to Manifest Event identity.

Examples:

Hard date only:
```json
{ "hard": "2025-11-22" }
```

Hard date resolving to holiday:
```json
{ "hard": "2025-12-25", "symbolic": "Christmas" }
```

Symbolic-only:
```json
{ "symbolic": "Thanksgiving" }
```

---

## OwnershipObject

```ts
OwnershipObject {
  managed: boolean,
  controller: "calendar" | "manual" | "unknown",
  locked: boolean
}
```

---

## StatusObject

```ts
StatusObject {
  enabled: boolean,
  deleted: boolean
}
```

---

## ProvenanceObject

```ts
ProvenanceObject {
  source: "ics",
  provider: string,
  imported_at: string // ISO-8601
}
```

---

## RevertObject (Single-Level Undo)

```ts
RevertObject {
  previous_identity: IdentityObject,
  previous_intent: IntentObject,
  reverted_at: string // ISO-8601
}
```

Rules:

- Revert restores the last applied semantic state
- Revert is non-recursive
- Revert does not imply calendar mutation

---

## Guarantees

- Manifest Events are deterministic
- SubEvents are atomic
- Scheduler state is reproducible from the Manifest alone
- Date semantics (hard and symbolic) are fully preserved and never lossy

---

## Invariant Enforcement

- Manifest invariants are enforced strictly during calendar ingestion.
- Manifest consumers may assume Manifest correctness and must not re-validate provider-originated invariants.
