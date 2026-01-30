**Status:** STABLE  
> **Change Policy:** Intentional, versioned revisions only  
> **Authority:** Behavioral Specification v2

# 03 — Manifest

## Purpose

The **Manifest** is the central, authoritative semantic model and intent translation layer for the system.

It represents the authoritative interpretation of user intent, including:

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
   Every calendar event produces exactly one Manifest Event. Execution details are represented exclusively by SubEvents derived from it.

3. **No persistence in FPP**  \
   The Manifest is *not* stored in `schedule.json`. FPP stores only executable scheduler entries.

4. **Symbolic preservation**  \
   Symbolic dates and times (Dawn, Dusk, Holidays, DatePatterns) are preserved semantically and resolved only at the FPP interface layer.

5. **Dual date preservation**  \
   When a concrete (hard) date is provided, it is always preserved. If that date resolves to a known holiday, the symbolic holiday representation is stored in addition. Date semantics are never collapsed or replaced.  
   Hard dates and symbolic dates are never inferred from FPP output for identity. FPP ingestion may preserve symbolic tokens as symbolic only; any raw FPP payload retention is trace-only under provenance.

6. **Atomic execution units**  \
   Execution details are grouped into SubEvents that are always applied and ordered atomically.

7. **Explicit distinction between identity and state**  \
   The system explicitly distinguishes *identity* (creation and deletion of Manifest Events) from *state* (updates to existing events). Updates are detected via comparison of SubEvent execution state, not Manifest Event identity.

8. **Explicit separation of scheduling and execution semantics**  \
   Scheduling recurrence (dates, days, patterns) and execution behavior (looping, repetition during runtime) are treated as distinct concerns and are never conflated.

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
  id: string,
  type: "playlist" | "command" | "sequence",
  target: string,

  correlation: CorrelationObject,

  identity: IdentityObject,
  subEvents: SubEvent[],

  ownership: OwnershipObject,
  status: StatusObject,
  provenance: ProvenanceObject,
  revert?: RevertObject
}
```

### Identity vs State

- The Manifest Event carries an `identityHash` that represents its semantic identity.
- Changes to the identity imply creation or deletion of the Manifest Event; they never represent updates.
- Manifest Events do not carry executable state directly; executable state is represented exclusively at the SubEvent level.

---

## CorrelationObject (Trace Identifiers)

```ts
CorrelationObject {
  // Trace-only identifiers. Never used for equality/diffing.
  source?: string,        // e.g. "google", "ics", "manual", "fpp"

  // Calendar/provider UID or equivalent (trace only)
  externalId?: string,

  // Optional platform reference for traceability (NOT raw schedule payload)
  fppRef?: {
    index?: number,       // position in schedule.json array when observed
    hash?: string         // optional stable hash/key if available
  }
}
```

Rules:

- Correlation exists for **traceability only**
- Correlation is never used for equality or diffing
- Correlation may collide across providers
- Correlation may be absent for manually created Manifest Events
- Correlation MUST NOT contain raw provider payload blobs (e.g. schedule.json entries).
- Raw provider payload snapshots, when retained, live under ProvenanceObject.trace (see below) and remain non-semantic.

---

## IdentityObject (Semantic Equality)

The **IdentityObject** defines semantic equality for Manifest Events.

It answers:

> **“Are these two Manifest Events the same logical scheduler entry?”**

Identity is:
- Deterministic
- Provider-agnostic
- Stable across time
- Independent of concrete dates and execution behavior; identity includes start-time semantics to prevent unintended event collapse

```ts
IdentityObject {
  type: "playlist" | "command" | "sequence",
  target: string,
  days: { type: "weekly", value: string[] } | null,
  start_time: { symbolic: string|null, hard: string|null, offset: number } | null,
  is_all_day: boolean
}
```

### Identity Invariants

- Identity is intentionally **minimal**.
- Identity includes only:
  - `type`
  - `target`
  - `days`
  - `start_time`
  - `is_all_day`
- Identity explicitly **excludes**:
  - end times
  - start and end dates
  - symbolic vs hard date representations
  - execution behavior (repeat, stopType, enabled)
- Identity fields MUST be derived from the base SubEvent geometry.
- Changing any Identity field creates a *new* Manifest Event and implies a create/delete operation during diff.

**Rationale:**  
Identity represents *what* the user is scheduling, not *when* or *how* it executes.  
Temporal and behavioral changes are treated as state updates, not identity changes.

---

## Intent (User Desire)

The Manifest preserves user intent through SubEvent execution + timing fields and the original provider payloads. Intent is a conceptual term used in UI and adapter discussions; it is not a separately persisted object in the Manifest.

---

## SubEvents (Executable Decomposition)

A **SubEvent** is an executable component derived from a Manifest Event.

SubEvents exist because not all calendar intent maps cleanly to a single FPP scheduler entry.

SubEvents represent FPP-required execution decomposition but remain part of the Manifest because they are derived intent, not FPP state.

```ts
SubEvent {
  type: "playlist" | "command" | "sequence",
  target: string,

  timing: {
    start_date: { symbolic: string|null, hard: string|null },
    end_date:   { symbolic: string|null, hard: string|null },
    start_time: { symbolic: string|null, hard: string|null, offset: number } | null,
    end_time:   { symbolic: string|null, hard: string|null, offset: number } | null,
    days: { type: "weekly", value: string[] } | null,
    is_all_day: boolean
  },

  **Execution vs Scheduling Terminology Guardrail**  
  The `behavior.repeat` field refers exclusively to execution-time looping behavior within FPP (e.g., replaying a playlist or repeating a command during a run).  
  It MUST NOT be used to represent scheduling recurrence, which is expressed only through SubEvent timing fields (`start_date`, `end_date`, `days`, and DatePatterns).

  behavior: {
    enabled: scalar,
    repeat: scalar,
    stopType: scalar
  },

  payload?: object,

  stateHash: string
}
```

**`stateHash`**  
- A deterministic hash of the fully-normalized SubEvent execution state.  
- Used exclusively to detect updates during the diff phase.  
- Provider-agnostic and stable across calendar/FPP sources.
A change to any SubEvent `stateHash` also implies a change to the parent Manifest Event's aggregate state.

### State Semantics

- State is evaluated only at the SubEvent level.  
- Each SubEvent maps 1:1 to an FPP scheduler entry.  
- A change to one SubEvent results in an update to only that execution unit.  
- No calendar- or FPP-specific logic is permitted during state comparison.

Rules:

- Every Manifest Event has **one or more SubEvents**
- SubEvents have **no independent identity**
- Identity is defined at the Manifest Event level and is derived from the base execution geometry (type, target, timing.days, timing.start_time, timing.is_all_day)
- Payload exists only for command-type SubEvents
- All-day SubEvents carry `null` times for `start_time` and `end_time` and never contain hard-coded full-day times (e.g., no 23:59:59 or 24:00:00).

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

DatePattern applies to SubEvent timing and intent realization, not to Manifest Event identity hashing.

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
  controller: "calendar" | "manual" | "fpp" | "unknown",
  locked: boolean
}
```

---
### Managed vs Unmanaged Semantics

The `managed` flag represents an explicit user grant allowing automated reconciliation actions to modify a Manifest Event.

- **managed = true**
  - The system MAY create, update, or delete this Manifest Event through reconciliation.
  - The event is considered under automated control.
  - All Diff and Apply operations are permitted, subject to locking rules.

- **managed = false**
  - The system MUST NOT mutate or delete this Manifest Event.
  - Identity matches MAY be observed for informational or preview purposes only.
  - Unmanaged events are excluded from all Apply operations.
  - This guarantee exists to protect user-authored scheduler state from unintended mutation.

The `managed` flag is never inferred. It MUST be set explicitly during ingestion or by direct user action.

Defaults by ingestion source:

- Calendar-derived events (google/ics): `managed = true`, `controller = "calendar"` (unless explicitly overridden).
- FPP-observed events (schedule.json snapshot): `managed = false`, `controller = "fpp"`.

These defaults are lifecycle semantics (authority), not planner policy.

---

## StatusObject

```ts
StatusObject {
  enabled: boolean,
  deleted: boolean
}
```

Semantics:

- `enabled` expresses the intended enabled state when applying a managed Manifest Event.
  For unmanaged / FPP-observed events, `enabled` is observational only and MUST NOT be treated as permission to mutate.
- `deleted` is a Manifest-level tombstone flag. It does not imply provider deletion.

---

## ProvenanceObject

```ts
ProvenanceObject {
  source: "google" | "ics" | "manual" | "fpp",
  provider: string,
  imported_at: string, // ISO-8601

  // Optional non-semantic snapshots for debugging/traceability.
  // MUST NOT be used for identity, hashing, diffing, or apply decisions.
  trace?: {
    calendar?: object,
    fpp?: {
      raw?: object
    }
  }
}
```

---

## RevertObject (Single-Level Undo)

```ts
RevertObject {
  previous_identity: IdentityObject,
  previous_subEvents: SubEvent[],
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
- Diffing is performed using Manifest Event `identityHash` for creation and deletion, and SubEvent `stateHash` for updates

---

## Invariant Enforcement

- Manifest invariants are enforced strictly during calendar ingestion.
- Manifest consumers may assume Manifest correctness and must not re-validate provider-originated invariants.
- Unmanaged Manifest Events MUST be treated as read-only by all reconciliation and apply processes.

---

## Manifest Event Container

{
  "events": {
    "<eventId>": {
      "type": "playlist | command | sequence",
      "target": "string",

      "correlation": {
        "source": "google | ics | manual | fpp | null",
        "externalId": "string | null"
      },

      "identity": {
        "type": "playlist | command | sequence",
        "target": "string",
        "days": null,
        "start_time": null,
        "is_all_day": false
      },

      "ownership": {
        "managed": true,
        "controller": "calendar | manual | unknown",
        "locked": false
      },

      "status": {
        "enabled": true,
        "deleted": false
      },

      "provenance": {
        "source": "ics",
        "provider": "string",
        "imported_at": "ISO-8601"
      },

      "subEvents": [
        /* FPP scheduler entries live here */
      ]
    }
  }
}

---

## Manifest SubEvent Container

{
  "type": "playlist | command | sequence",
  "target": "string",

  "timing": {
    "start_date": { "hard": "YYYY-MM-DD | null", "symbolic": "string | null" },
    "end_date": { "hard": "YYYY-MM-DD | null", "symbolic": "string | null" },
    "start_time": { "hard": "HH:MM:SS | null", "symbolic": "string | null", "offset": 0 } | null,
    "end_time": { "hard": "HH:MM:SS | null", "symbolic": "string | null", "offset": 0 } | null,
    "days": { "type": "weekly", "value": ["MO","TH"] } | null,
    "is_all_day": boolean
  },

  "behavior": {
    "enabled": true,
    "repeat": "scalar",
    "stopType": "scalar"
  },

  "payload": {
    "<string>": "<scalar | object>"
  },

  "stateHash": "string"
}