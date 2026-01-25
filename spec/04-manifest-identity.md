### Manifest Identity Semantics

> Manifest Identity defines **which scheduler intent the plugin considers equivalent**, not how the FPP scheduler internally enumerates entries.

Manifest Identity is derived from fully normalized Intent.
The Manifest never computes identity directly from raw calendar data or raw FPP scheduler entries.
All identity equivalence decisions are made only after Intent normalization.

#### Platform Reality (FPP)

In the FPP scheduler, **every scheduler entry is structurally distinct**. Any difference in execution geometry — including type, target, date range, time range, or day mask — produces a separate entry. FPP performs a strict top-down scan of entries to determine what executes next and does not attempt to normalize or minimize entries.

#### Plugin Responsibility

The Manifest does **not** mirror raw FPP scheduler fragmentation. Instead, it represents a **normalized scheduler intent** chosen by the plugin to:
- Minimize the number of scheduler entries created
- Preserve identical runtime behavior under FPP’s scan model
- Provide stable identity across imports, edits, and seasonal shifts

Normalization is an **intentional optimization**, not a platform constraint.

This normalization is performed during Intent normalization and never during resolution or apply phases.

#### What Defines Manifest Identity

Manifest Identity is derived from the subset of execution geometry that defines a **logical execution slot** as perceived by the user.

Identity includes:

- **Execution type** (`playlist`, `command`, `sequence`)
- **Execution target** (playlist name, command name, etc.)
- **Timing window** (canonicalized), including normalized date patterns:
  - ``timing`` must be a structured object
  - ``days`` may be null (meaning "Everyday") or a structured weekly selector
  - ``start_time`` is required (hard or symbolic)
  - ``end_time`` is required (hard or symbolic)
  - ``start_date`` and ``end_date`` participate as normalized **DatePatterns** when present

These fields determine whether two scheduler entries could ever be eligible at the same moment during a daily FPP scan. If two entries differ in any of these dimensions, they represent distinct scheduler intents and must not share identity.

#### Timing Canonicalization Rules

Timing participates in Manifest Identity subject to the following rules:

- `timing` must be a structured object
- `days` may be null (meaning "Everyday") or a structured weekly selector
- `start_time` and `end_time` are required
- Each of `start_time` and `end_time` must define **either**:
  - a hard time (`hard`), **or**
  - a symbolic time (`symbolic`)
- `offset` is allowed (typically used with sun times)
- `start_date` and `end_date` (if present) must define at least one of: `hard` (YYYY-MM-DD) or `symbolic` (holiday/alias).
- Resolution is **not** permitted during identity hashing: symbolic dates must not be converted into hard dates for the purpose of identity.

Date fields are structurally validated as part of identity equivalence and hashing.

All timing canonicalization occurs during Intent normalization; resolution and diffing operate only on fully normalized timing structures.

#### What Does *Not* Define Manifest Identity

The following fields affect *when* an entry is active or *how* it executes, but do **not** define logical identity and are therefore excluded from identity hashing:

- Enablement state (`enabled`)
- Playback behavior (`repeat`, `stopType`)
- Execution payload or command arguments
- Provider metadata or correlation identifiers

These fields may differ while still representing the **same normalized scheduler intent**.

#### Dates and Normalization

Dates *do* participate in Manifest Identity, but only as normalized DatePatterns (hard and/or symbolic), never as implicitly resolved values. This supports identity stability across years while still preventing accidental collisions between intents that can be eligible at different times.

Date normalization rules:
- Calendar-derived events may populate both `hard` and `symbolic` when a hard date matches a known symbolic holiday.
- FPP-derived events should preserve symbolic dates in `symbolic` and leave `hard` null (no forward resolution).
- Identity equivalence treats DatePatterns as matching when either their `symbolic` values match or their `hard` values match.

#### Summary Rule

> Two SubEvents share the same Manifest Identity if and only if their execution type, execution target, and fully normalized timing are equivalent. Timing equivalence includes weekly day selection (or null meaning "Everyday"), start/end times, and DatePatterns (when present), where DatePatterns match if either hard or symbolic components match.
