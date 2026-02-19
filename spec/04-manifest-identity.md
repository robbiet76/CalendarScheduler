### Manifest Identity Semantics

> Manifest Identity defines **which scheduler intent the plugin considers equivalent**, not how the FPP scheduler internally enumerates entries.

Manifest Identity is derived from fully normalized Intent at the Event level.  
The Manifest never computes identity directly from raw calendar data or raw FPP scheduler entries.  
All identity equivalence decisions are made only after Intent normalization.

#### Manifest State Semantics

In addition to Manifest Identity, the Manifest defines **StateHash**, which represents the fully normalized configuration of a scheduler intent, including all execution parameters, timing details, enablement state, and payload. StateHash is used primarily for update detection during diff operations, allowing the system to recognize changes that do not affect identity but modify execution behavior, timing, or metadata. StateHash is computed at the SubEvent level.

**StateHash is inclusive of IdentityHash.**  
Any change in Manifest Identity MUST result in a different StateHash.  
A StateHash change does not necessarily imply an Identity change.

In contrast, Manifest Identity defines structural equivalence of scheduler intents at the Event level and is used to determine create or delete operations.

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

Manifest Identity is derived from the subset of execution geometry that defines a **single calendar event** (a user-editable unit) as perceived at the Event level.

Identity answers the question **"What calendar event is this?"** (i.e., what would require creating a *new* calendar event), not **"What is its current configured run state?"**.

Identity includes only the structural fields:

- **Execution type** (`playlist`, `command`, `sequence`)
- **Execution target** (playlist name, command name, etc.)
- **All-day flag** (`all_day`)
- **Weekly day selection** (`days`), which may be null meaning "Everyday"
- **Start time** (`start_time`), including symbolic or hard value and offset
- **End time** (`end_time`), including symbolic or hard value and offset

Identity explicitly excludes *date-range activation* details (`start_date`, `end_date`, DatePatterns) as well as execution behavior, enablement, and payload. Changing date range is treated as an UPDATE to the same calendar event, not a new identity.

SubEvents inherit the Manifest Identity of their parent Event. Timing, date patterns, execution behavior, enablement, and payload differences are captured in the SubEvent-level StateHash.

#### Identity vs State Boundary

- Manifest Identity exists at the Event level and defines structural equivalence for create or delete operations.
- SubEvents inherit this identity and have their own StateHash computed from the full normalized Intent object, including timing, execution behavior, payload, and enablement state.
- Changes in Manifest Identity result in delete + create operations.
- Changes in SubEvent StateHash result in update operations.
- Identity changes take precedence over state changes; if identity differs, state changes are irrelevant.

#### Manifest StateHash Scope

StateHash encompasses all normalized timing details, including start_time, end_time, DatePatterns, and execution parameters such as enablement, playback behavior, and payload. Timing canonicalization and normalization rules apply here to ensure consistent state comparison but do not affect Manifest Identity.

#### What Does *Not* Define Manifest Identity

The following fields affect *when* an entry is active or *how* it executes, but do **not** define logical identity and are therefore excluded from identity hashing:

- Date-range activation details (`start_date`, `end_date`, DatePatterns)
- Enablement state (`enabled`)
- Playback behavior (`repeat`, `stopType`)
- Execution payload or command arguments
- Provider metadata or correlation identifiers

These fields may differ while still representing the **same normalized scheduler intent** at the Event level.


#### Dates and Timing Normalization

Dates and timing participate in Manifest StateHash, not Manifest Identity. They are normalized to ensure stable and consistent state comparison across imports, edits, and seasonal shifts.

Date normalization rules and timing canonicalization apply during Intent normalization and influence StateHash computation. Symbolic and hard dates are preserved to maintain identity stability at the state level but do not impact Event-level Manifest Identity.

##### Timezone Normalization (FPP-Local)

All dates and times used anywhere within the plugin MUST be normalized to the FPP local timezone prior to any identity or state computation.

The FPP-configured timezone is the sole authoritative timezone for:
- Intent normalization
- Manifest Identity derivation
- StateHash computation
- Diff and reconciliation
- Apply and UI presentation

External sources (e.g., calendar providers) may supply timestamps in arbitrary timezones or UTC. These MUST be converted into FPP local time during normalization. Timezone offsets, UTC instants, or source-specific timezone identifiers MUST NOT participate in identity or state hashing.

Symbolic times (e.g., `dawn`, `dusk`) are interpreted relative to the FPP local timezone and location and are preserved symbolically through identity and state computation.

#### Summary Rule

> Scheduler intents are grouped into a single Manifest Event if and only if their execution type, execution target, all-day flag, start time, end time, and weekly day selection are equivalent.
