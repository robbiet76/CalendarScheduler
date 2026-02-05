**Status:** STABLE  
> **Change Policy:** Intentional, versioned revisions only  
> **Authority:** Behavioral Specification v2

# 07 — Events & SubEvents (Atomic Scheduling Units)

## Purpose

This section defines how **calendar events**, **manifest events**, and **FPP scheduler entries** relate to one another.

It establishes the atomic execution model used throughout the system and replaces the earlier term *bundle* with clearer, domain-aligned language.

**Terminology Note (Intentional):**  
The term **SubEvent** refers to a single, executable scheduler entry.  
In scheduler backends such as FPP, a SubEvent maps 1:1 to what users typically call a *schedule entry*.  
The name *SubEvent* is historical and reflects its derivation from a higher‑level Manifest Event.  
A future major revision may rename this concept to *ScheduleEntry* for clarity.

The canonical relationship is:

```
Calendar Event
        ↓
Manifest Event
        ↓
{ SubEvents }   (execution units)
        ↓
Scheduler Entries (FPP, etc.)
```

---

## Core Definitions

### Calendar Event

A **Calendar Event** is a provider-level object (ICS, Google, etc.) that expresses user intent.

Rules:
- Calendar events are **inputs only**
- They may be symbolic, repeating, incomplete, or provider-specific
- They are never executed directly

---

### Manifest Event

A **Manifest Event** is the authoritative semantic representation of exactly one calendar event.

> **Invariant (Post‑Import):** `1 Calendar Event → 1 Manifest Event`

During FPP adoption and calendar export, no SubEvent decomposition occurs  
beyond the single base SubEvent derived directly from the scheduler entry.

This base SubEvent immediately establishes the Manifest Event’s identity  
prior to persistence.

Manifest Events are the unit of:
- Identity
- Diffing
- Ordering
- Apply
- Revert

A Manifest Event contains:
- **One IdentityObject** (semantic equality)
- **One or more SubEvents** (executable decomposition)
- Ownership, status, provenance, and revert metadata

---

### SubEvents

A **SubEvent** is a deterministic, executable scheduling unit derived from a Manifest Event.

SubEvents are introduced only after calendar import and resolution.  
SubEvents receive fully normalized timing boundaries.  
They do not interpret, expand, or modify recurrence rules or  
provider-specific date semantics.

During FPP adoption and calendar export, no SubEvent decomposition  
occurs beyond the single base SubEvent derived directly from a  
scheduler entry.

SubEvents exist because:
- Scheduler backends operate on discrete execution entries
- Some calendar intent cannot be expressed as a single scheduler entry
- Exceptions, unsupported day masks, or date patterns may require decomposition

Rules:
- SubEvents **always live inside** the Manifest Event
- SubEvents **do not have independent identity**
- Identity exists at the Manifest Event level and is derived from the base SubEvent; SubEvents do not participate in identity
- SubEvents are never diffed, ordered, or reverted independently
- SubEvents are never persisted outside the Manifest

SubEvents participate in update detection only through their computed
state hash.

SubEvents are never diffed structurally or compared field-by-field.
A change in any SubEvent’s execution semantics is detected solely by
a change in its `stateHash`.

Each SubEvent has an associated `stateHash` computed during Intent
Normalization.

The `stateHash` represents the complete executable state of the SubEvent
and is used exclusively during the diff phase to determine whether an
update is required.

---

## SubEvent Roles

Every Manifest Event contains one or more SubEvents.

Exactly one SubEvent must have:

```ts
role: "base"
```

Zero or more SubEvents may have:

```ts
role: "exception"
```

---

### Base SubEvent

The **base SubEvent** represents the primary, continuous scheduling intent.

Characteristics:
- Always present
- Represents the dominant schedule
- Ordered last *within* the Manifest Event

All date arithmetic and recurrence resolution has already been completed
before the base SubEvent is created.

The base SubEvent is the exclusive source of Manifest Event identity; exception SubEvents never influence identity construction.

---

### Exception SubEvents

**Exception SubEvents** represent deviations from the base behavior.

Examples:
- Date exclusions
- Unsupported day masks
- Calendar exception dates

Characteristics:
- Zero or more per Manifest Event
- Ordered *above* the base SubEvent
- Never reordered internally

---

## Identity Model Clarification

- **IdentityObject exists at the Manifest Event level**
- **SubEvents never have identity**
- SubEvents correspond to scheduler execution entries but are never treated as first‑class semantic objects
- Identity comparison, hashing, and reconciliation operate on Manifest Events only

This guarantees:
- Stable diffing
- Predictable ordering
- Safe revert
- No semantic fragmentation

### Identity Derivation Scope

A Manifest Event’s identity is derived **exclusively** from its base SubEvent’s  
execution geometry, not from its full structure.

Identity is constructed from:
- `type`
- `target`
- `timing` (normalized; see Identity specification)

Identity explicitly excludes:
- Execution range semantics beyond normalized date patterns
- Behavior flags
- Payload contents
- Exception SubEvents
- Calendar provenance

Date patterns (`start_date`, `end_date`) participate in identity only in normalized, symbolic-aware form, ensuring stable identity across calendar and FPP sources.

For date fields, the identity layer treats `hard` and `symbolic` as alternative representations of the same semantic boundary. If both are present, either one may be used for equivalence (see Date Semantics). If only one is present, that value alone is used.

This ensures:
- Stable identity across calendar edits
- Identity consistency between FPP and Calendar sources
- Predictable diff and merge behavior

## Identity vs State

Manifest Event identity determines **what** an event is.
Execution state determines **how** that event behaves.

Two Manifest Events with identical identity may differ in execution
state due to changes in timing boundaries, payload, or behavior flags.

Identity equality alone does not imply execution equality.

---

## Weekday Constraints (`timing.days`)

The `timing.days` field constrains execution to specific weekdays within the
inclusive `[start_date → end_date]` window.

This field is **optional** and participates in identity when present.

### Allowed Values

`timing.days` MUST be one of:

- `null`  
  Indicates execution on **all days** within the date window.  
  This is the default and is equivalent to an unconstrained daily schedule.

- An object of the form:

```ts
{
  type: "weekly",
  value: Weekday[]
}
```

- An object of the form:

```ts
{
  type: "date_parity",
  value: "odd" | "even"
}
```

Where `Weekday` is one of:

```
SU, MO, TU, WE, TH, FR, SA
```

### Semantics

- `type: "weekly"` constrains execution to the specified weekdays only.
- `value` represents a **set**, not a sequence:
  - Order is not significant
  - Duplicate values are invalid
- Weekday constraints do **not** alter date arithmetic.
  They only restrict which days within the window are eligible for execution.
- `type: "date_parity"` constrains execution to odd or even calendar dates within the date window.
- Date parity is evaluated against the calendar day-of-month, not weekday.

### Identity Rules

When present, `timing.days` participates in Manifest Event identity.

Normalization rules for identity:
- `value` MUST be normalized to:
  - uppercase
  - two-letter weekday identifiers
  - sorted lexicographically
- Two Manifest Events that differ only by `timing.days` are **not identical**.
- When `type` is `"date_parity"`, the values `"odd"` and `"even"` are distinct and participate in identity equivalence.

### Calendar Mapping (Informative)

Calendar recurrence rules may populate `timing.days` as follows:

- `RRULE:FREQ=DAILY`  
  → `timing.days = null`

- `RRULE:FREQ=WEEKLY;BYDAY=…`  
  → `timing.days.type = "weekly"`  
  → `timing.days.value = BYDAY`

- No direct RRULE mapping exists for date parity; this form originates from FPP scheduler semantics (Odd/Even).

Non-daily recurrence rules remain unsupported unless explicitly mapped.

### Execution Notes

- `timing.days` does not cause SubEvent expansion.
- Exactly one base SubEvent is still generated.
- Platform-specific execution details (e.g. FPP day bitmasks)
  are applied only during materialization and never stored in Intent.

## Date Semantics (Hard vs Symbolic)

Intent-level dates are stored in a provider-neutral structure that preserves both:
- **Hard** date patterns (explicit, user-authored or provider-authored)
- **Symbolic** dates (named holidays / locale-defined symbols originating from FPP)

Calendar recurrence semantics (including `DTSTART`, `RRULE`, and `UNTIL`)
MUST be fully resolved prior to Manifest Event construction.

No date arithmetic, inclusivity/exclusivity adjustment, or provider-specific
recurrence interpretation occurs at the Manifest Event or SubEvent layer.

Each SubEvent contains:

```ts
start_date: DateValue
end_date:   DateValue
```

Where:

```ts
type DateValue = {
  hard: DatePattern | null,
  symbolic: string | null
}
```

### DatePattern

A `DatePattern` is an FPP-aligned date pattern string:

- `YYYY-MM-DD` → absolute date
- `0000-MM-DD` → applies every year on that month/day
- `0000-00-DD` / `0000-MM-00` / `0000-00-00` → wildcard patterns (FPP semantics)

`DatePattern` values are:
- Preserved verbatim in the Manifest
- Never expanded during planning
- Expanded only during materialization using the FPP semantics layer

### Symbolic Dates

A symbolic date is a locale-defined identifier (for example: `Thanksgiving`, `Christmas`, `Epiphany`) originating from FPP's holiday definitions.

Symbolic dates are:
- Preserved verbatim
- Not expanded or converted to a concrete calendar date during normalization
- Interpreted only when materializing to FPP (or when explicitly rendered for display)

### Rule: When `hard` is allowed to be non-null

`hard` MUST be non-null only when the source provides an explicit `DatePattern`.

This means:
- **Calendar import:** If the provider supplies a concrete date (e.g. `2025-11-27`), it is stored in `hard`.
- **YAML / user intent:** If the user supplies a `DatePattern` (including wildcard patterns), it is stored in `hard`.
- **FPP adoption:** If the scheduler entry uses a holiday name, it is stored in `symbolic` and `hard` MUST remain `null`.

**Prohibited:** Deriving `hard` from a `symbolic` value during normalization.

Rationale: symbolic dates do not uniquely determine a year and may represent recurring seasonal intent.

### Optional annotation (non-normative)

If a concrete `hard` date is recognized as a known holiday for that specific year/locale, implementations MAY also populate `symbolic` as an informational annotation. This does not change identity semantics and does not imply expansion of `symbolic`.

### Examples

| start_date | end_date | Meaning |
|-----------|----------|--------|
| `{ hard: "2025-12-25", symbolic: "Christmas" }` | `{ hard: "2025-12-25", symbolic: "Christmas" }` | Christmas Day in 2025 (annotated) |
| `{ hard: null, symbolic: "Thanksgiving" }` | `{ hard: null, symbolic: "Christmas" }` | A holiday-to-holiday seasonal window (year-agnostic) |
| `{ hard: "0000-02-14", symbolic: null }` | `{ hard: "0000-02-14", symbolic: null }` | Every Feb 14 |
| `{ hard: "0000-01-01", symbolic: null }` | `{ hard: "0000-12-31", symbolic: null }` | Entire year, every year |

Resolved date values directly participate in SubEvent state hashing
but never in identity construction beyond normalized symbolic equivalence.

---

## Atomicity Guarantees

SubEvents are **atomic as a group**.

This means:
- A Manifest Event moves as a unit during global ordering
- Internal SubEvent order is fixed and deterministic
- No SubEvent may be inserted, removed, or reordered independently

> There is no such thing as a partially applied Manifest Event.

---

## Ordering Relationship

Ordering operates at **two distinct levels**:

### 1. Internal Ordering (Within a Manifest Event)

Fixed and deterministic:

```
[ Exception SubEvents ]
        ↓
[ Base SubEvent ]
```

This ordering never changes.

---

### 2. Global Ordering (Across Manifest Events)

Rules:
- Manifest Events are ordered relative to one another
- SubEvents never participate directly
- Ordering rules are defined in **08 — Scheduler Ordering Model**

---

### Non‑Goals

SubEvents are not a semantic unit and must never be treated as such.  
They exist solely as executable artifacts required by scheduler backends.

---

## Invariants

- 1 Calendar Event → 1 Manifest Event
- Every Manifest Event has ≥ 1 SubEvent
- Exactly one SubEvent has `role: "base"`
- SubEvents share the Manifest Event’s identity
- SubEvents are immutable once derived
- SubEvents never escape the Manifest
