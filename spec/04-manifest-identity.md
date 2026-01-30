### Manifest Identity Semantics

> Manifest Identity defines **which scheduler intent the plugin considers equivalent**, not how the FPP scheduler internally enumerates entries.

Manifest Identity is derived from fully normalized Intent at the Event level.  
The Manifest never computes identity directly from raw calendar data or raw FPP scheduler entries.  
All identity equivalence decisions are made only after Intent normalization.

#### Manifest State Semantics

In addition to Manifest Identity, the Manifest defines **StateHash**, which represents the fully normalized configuration of a scheduler intent, including all execution parameters, timing details, enablement state, and payload. StateHash is used primarily for update detection during diff operations, allowing the system to recognize changes that do not affect identity but modify execution behavior, timing, or metadata. StateHash is computed at the SubEvent level.

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

Manifest Identity is derived from the subset of execution geometry that defines a **logical execution slot** as perceived by the user at the Event level.

Identity answers the question **"What is this scheduled thing?"**, not **"How or when does it execute?"**

Identity includes only the structural fields:

- **Execution type** (`playlist`, `command`, `sequence`)
- **Execution target** (playlist name, command name, etc.)
- **Weekly day selection** (`days`), which may be null meaning "Everyday"
- **Start time** (`start_time`), including symbolic or hard value and offset

Identity explicitly excludes timing details such as end_time, start_date, end_date, and DatePatterns, as well as any execution behavior, enablement, or payload.

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

- Timing details excluding start_time (`end_time`, `start_date`, `end_date`, DatePatterns)
- Enablement state (`enabled`)
- Playback behavior (`repeat`, `stopType`)
- Execution payload or command arguments
- Provider metadata or correlation identifiers

These fields may differ while still representing the **same normalized scheduler intent** at the Event level.

#### Dates and Timing Normalization

Dates and timing participate in Manifest StateHash, not Manifest Identity. They are normalized to ensure stable and consistent state comparison across imports, edits, and seasonal shifts.

Date normalization rules and timing canonicalization apply during Intent normalization and influence StateHash computation. Symbolic and hard dates are preserved to maintain identity stability at the state level but do not impact Event-level Manifest Identity.

#### Summary Rule

> Two Manifest Events share the same identity if and only if their execution type, execution target, start time, and weekly day selection are equivalent. All other differences are state differences evaluated via SubEvent StateHash.
