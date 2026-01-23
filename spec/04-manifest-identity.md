### Manifest Identity Semantics

> Manifest Identity defines **which scheduler intent the plugin considers equivalent**, not how the FPP scheduler internally enumerates entries.

#### Platform Reality (FPP)

In the FPP scheduler, **every scheduler entry is structurally distinct**. Any difference in execution geometry — including type, target, date range, time range, or day mask — produces a separate entry. FPP performs a strict top-down scan of entries to determine what executes next and does not attempt to normalize or minimize entries.

#### Plugin Responsibility

The Manifest does **not** mirror raw FPP scheduler fragmentation. Instead, it represents a **normalized scheduler intent** chosen by the plugin to:
- Minimize the number of scheduler entries created
- Preserve identical runtime behavior under FPP’s scan model
- Provide stable identity across imports, edits, and seasonal shifts

Normalization is an **intentional optimization**, not a platform constraint.

#### What Defines Manifest Identity

Manifest Identity is derived from the subset of execution geometry that defines a **logical execution slot** as perceived by the user.

Identity includes:

- **Execution type** (`playlist`, `command`, `sequence`)
- **Execution target** (playlist name, command name, etc.)
- **Timing window** (canonicalized), including normalized date patterns:
  - `timing` must be a structured object
  - `days` may be null for non-weekmask schedules (e.g. calendar-derived events)
  - `start_time` is required (hard or symbolic)
  - `end_time` is required (hard or symbolic)
  - `start_date` and `end_date` (when present) participate as normalized DatePatterns

These fields determine whether two scheduler entries could ever be eligible at the same moment during a daily FPP scan. If two entries differ in any of these dimensions, they represent distinct scheduler intents and must not share identity.

#### Timing Canonicalization Rules

Timing participates in Manifest Identity subject to the following rules:

- `timing` must be a structured object
- `days` is required
- `start_time` and `end_time` are required
- Each of `start_time` and `end_time` must define **either**:
  - a hard time (`hard`), **or**
  - a symbolic time (`symbolic`)
- `offset` is allowed (typically used with sun times)
- `start_date` and `end_date` (if present) must define **either** a hard or symbolic value

Date fields are structurally validated as part of identity equivalence and hashing.

#### What Does *Not* Define Manifest Identity

The following fields affect *when* an entry is active or *how* it executes, but do **not** define logical identity and are therefore excluded from identity hashing:

- Enablement state (`enabled`)
- Playback behavior (`repeat`, `stopType`)
- Execution payload or command arguments
- Provider metadata or correlation identifiers

These fields may differ while still representing the **same normalized scheduler intent**.

#### Dates and Normalization

Dates *can* participate in raw scheduler identity in FPP, but are intentionally **normalized out** of Manifest Identity. The plugin may collapse or expand date ranges as needed, provided that runtime behavior under FPP’s scan model is preserved. This ensures:

- Identity stability across year boundaries
- Reduced diff noise from seasonal edits
- Predictable reconciliation behavior

Date semantics are therefore:
- Preserved in SubEvent timing
- Subject to plugin-controlled normalization
- Explicitly excluded from Manifest Identity hashing (even when present)

#### Summary Rule

> Two SubEvents share the same Manifest Identity if and only if their execution type, execution target, and fully normalized timing (including date and time patterns) are equivalent. Derived execution instances and metadata such as enablement state or playback behavior do not affect identity.

This definition is authoritative and governs diffing, reconciliation, and apply behavior throughout the system.
