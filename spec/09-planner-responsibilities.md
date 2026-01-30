**Status:** STABLE  
> **Change Policy:** Intentional, versioned revisions only  
> **Authority:** Behavioral Specification v2

# 09 — Planner Responsibilities

## Purpose

The **Planner** is the deterministic transformation engine that converts **Manifest Events** into an ordered, executable plan for the scheduler.

It answers one question only:

> **“Given the current Manifest, what scheduler entries *should* exist, and in what order?”**

The Planner does **not** write to FPP, does **not** mutate the Manifest, and does **not** interpret existing scheduler state as authoritative.

---

## Core Responsibilities

The Planner **MUST**:

1. Ingest the current Manifest as the *only* source of truth
2. Expand each Manifest Event into its SubEvents
3. Preserve Manifest Event atomicity at all times
4. Apply the Scheduler Ordering Model
5. Produce a deterministic desired state
6. Support preview and apply modes without behavior drift
7. Reuse existing helper components (e.g. time resolution, holiday resolution, export adapters) as pure functions where applicable

The Planner **MUST NOT**:

- Read from `schedule.json`
- Infer intent from existing scheduler entries
- Persist any state
- Modify Manifest Events or identities
- Apply compatibility shims for legacy behavior
- Reimplement or duplicate invariant enforcement already guaranteed by ManifestStore

---

## Inputs

The Planner accepts **only**:

- A complete, validated Manifest
- Runtime configuration flags (e.g. preview, debug)

It must **never**:

- Read from FPP directly
- Read calendar data
- Perform provider-specific logic
- Re-validate Manifest identity invariants (these are enforced exclusively by ManifestStore)

> Note: Preservation of unmanaged entry ordering refers only to unmanaged entries
> supplied to the Planner as part of an external snapshot. The Planner MUST NOT
> read from FPP or query `schedule.json` directly.

---

## Outputs

### Planner Output Model (Non-Persistent)

The Planner produces a deterministic *desired scheduling state*. It does not determine whether entries should be created, updated, or deleted. Those decisions are handled exclusively by the Diff phase.

The following structures are **internal Planner artifacts**. They are not persisted and must not be written back to the Manifest:
- PlannedEntry
- PlannerResult
- OrderingKey

```ts
PlannerResult {
  creates: FppScheduleEntry[]
  updates: FppScheduleEntry[]
  deletes: FppScheduleEntry[]
  desiredEntries: FppScheduleEntry[]
}
```

Rules:

- Output is fully deterministic
- Two identical Manifests MUST yield identical PlannerResults
- Output contains **no side effects**

---

## Manifest Event Expansion

For each **Manifest Event**:

1. SubEvents are expanded in their defined internal order
2. Exactly one SubEvent is designated as the `base`
3. Zero or more SubEvents may be `exceptions`

Rules:

- SubEvents are **never merged across Manifest Events**
- SubEvents are **never reordered internally**
- All SubEvents move together as an atomic unit

---

## Ordering Responsibilities

The Planner is responsible for **all ordering decisions** for managed entries.

Ordering decisions are based on the effective timing of each Manifest Event, derived from the combined effect of all its SubEvents (base and exceptions).

Effective timing MAY be symbolic.

The Planner MUST compare symbolic timing values without resolving them into
concrete dates or times. Symbolic values are ordered only relative to other
symbolic values according to the Scheduler Ordering Model.

If ordering cannot be conclusively determined due to symbolic ambiguity,
the Planner MUST apply a deterministic, conservative ordering that preserves
safety and atomicity. Arbitrary or hash-based ordering is forbidden.

It MUST:

- Apply the Scheduler Ordering Model exactly
- Preserve unmanaged entry ordering and grouping
- Ensure deterministic ordering across runs

It MUST NOT:

- Use hash order, insertion order, or array index as a heuristic
- Perform partial ordering
- Defer ordering decisions to later phases

---

## Planner Execution Modes (Preview / Apply Context)

The Planner behaves **identically** in preview and apply modes with one exception:

- In preview mode, invalid or incomplete identities MAY be surfaced
- In apply mode, invalid identities MUST fail fast

Rules:

- Preview MUST NOT mask logic errors
- Preview output MUST be a truthful representation of apply output

---

## Identity Handling

The Planner:

- Consumes Manifest identities **once** per Manifest Event

The Planner does not compute or interpret state hashes. It produces the fully normalized desired state that downstream diff logic compares using stateHash to determine whether updates are required.

The Planner produces the fully normalized desired state that downstream diff logic compares using stateHash to determine whether updates are required.

Identity fields are a strict subset of overall state. Any identity change inherently implies a state change and will be treated as a create/delete rather than an update.

- Attaches identity metadata to desired entries
- Treats identity as immutable

Identity validation and invariant enforcement are **out of scope** for the Planner. The Planner assumes that all Manifest identity invariants have already been enforced at the ManifestStore boundary and must not duplicate or re-check them.

The Planner MUST NOT:

- Recompute identity during diff or apply
- Patch identity fields conditionally

---

## Determinism Guarantees

The Planner guarantees:

- Stable ordering given identical input
- No dependency on runtime timing
- No dependency on external state
- Deterministic behavior in the presence of symbolic or ambiguous timing,
  using defined conservative ordering rules

Violations of determinism are considered **critical defects**.

---

## Error Handling

The Planner MUST fail fast on:

- Invalid Manifest structure
- Missing required identity fields (presence only; identity correctness is enforced by ManifestStore)
- Violations of atomicity

Symbolic or unresolved timing values are NOT considered invalid and MUST NOT
cause Planner failure.

The Planner MUST surface:

- Clear invariant violations
- Structured error messages

Silent failures are forbidden.

---

## Debugging & Diagnostics

When debugging is enabled, the Planner MUST:

- Emit ordering traces
- Expose pre- and post-order states
- Clearly label dominance decisions

Debug output MUST:

- Never affect behavior
- Be fully optional

---

## Summary

The Planner is:

- Pure
- Deterministic
- Manifest-driven
- Ordering-authoritative

Any deviation from these principles is a design error.
