**Status:** STABLE  
> **Change Policy:** Intentional, versioned revisions only  
> **Authority:** Behavioral Specification v2

# 10 — Diff & Reconciliation Model

## Purpose

The **Diff & Reconciliation** phase compares the **desired scheduler state** produced by the Planner against the **existing scheduler state** in FPP.

It answers one question only:

> **“Given what *****should***** exist and what *****does***** exist, what minimal changes are required?”**

This phase is strictly comparative. It does not infer intent, repair data, or compensate for upstream mistakes.

---

## Authoritative Sources

- **Desired State:** Output of the Planner (manifest-driven, ordered, deterministic)
- **Existing State:** Current FPP scheduler entries

Rules:

- The Manifest is authoritative for *intent*
- The Planner is authoritative for *ordering*
- FPP scheduler state is authoritative only for *what currently exists*

`schedule.json` is **not** authoritative and must never be used as a source of truth.

---

## Identity-Based Reconciliation

### Timezone Normalization Requirement

All temporal values used anywhere in Diff & Reconciliation MUST be normalized to the **FPP local timezone**.

Rules:

- FPP local timezone (as reported by the FPP environment) is the single authoritative timezone for the entire plugin.
- All calendar-derived times MUST be converted to FPP local time **before**:
  - identityHash computation
  - stateHash computation
  - diff comparison
- All FPP-derived times are assumed to already be in FPP local time.
- UTC, calendar-native timezones, or user browser timezones MUST NOT participate in Diff logic.

This guarantees that:
- identityHash and stateHash are stable and deterministic
- timezone offsets never produce false updates
- Diff behavior is identical regardless of calendar provider or hosting region

Timezone conversion is a **normalization concern** and MUST be completed prior to Diff. The Diff phase itself does not perform timezone math.

All reconciliation is performed using **Manifest Identity**.

### Identity Matching Rules

Two scheduler states are considered representations of the *same Manifest Event* if and only if:

- Their associated Manifest Identity `id` matches exactly

Explicitly forbidden:

- Matching by index or position
- Matching by target name alone
- Matching by start time alone
- Matching by UID alone

Identity matching is performed at the Manifest Event level. Individual scheduler entries produced from SubEvents are never matched independently.

### Symbolic Timing Considerations

Symbolic timing fields that participate in Manifest Identity (e.g. symbolic dates or symbolic time markers) are first-class identity-stable inputs.

- Must not be resolved to concrete dates or times during Diff and must be compared symbolically when participating in identity

Resolved dates are an execution concern and are out of scope for Diff.

If identity is missing or invalid:

- **Preview mode:** Surface the issue
- **Apply mode:** Fail fast

---


Diff operations are classified into **creates**, **updates**, and **deletes** based on identity and state comparisons. Directionality (calendar→fpp vs fpp→calendar) is resolved by the Authority & Direction rules and attached as metadata for Preview and Apply.

## Diff Categories

## Phase 2.1 — Authority & Direction Rules

This phase determines **which side is considered authoritative for a given change** and **in which direction a mutation must occur**.

### Equal Authority Model

- The Calendar source and the FPP scheduler are **equal authorities**.
- Neither side is globally dominant.
- The Manifest is the **only reconciliation surface** and is the single source of truth for Apply.

### Change Detection vs Change Direction

The Diff phase classifies **what has changed**, not **where the change originated**.

Directionality is determined by **temporal authority**, not by source type.

### Temporal Authority Rule

For a given Manifest Identity:

- If both sides differ, the side with the **most recent stateHash change** is authoritative.
- Recency is determined by:
  - Calendar: last-modified timestamp from the calendar snapshot / export
  - FPP: last-modified timestamp from the scheduler entry or derived metadata

If timestamps are equal or unavailable:

- Default authority is the **Desired State** (Planner output)
- This preserves deterministic, idempotent behavior

### Directional Outcomes

Each Diff operation MUST carry a direction:

- **Apply → FPP** (mutate scheduler)
- **Apply → Calendar** (mutate calendar source)
- **No-op** (already converged)

This direction is metadata on the DiffResult and does not alter diff classification.

### Create / Update / Delete Semantics with Direction

- **Create**
  - Calendar newer → create on FPP
  - FPP newer → create on Calendar
- **Update**
  - The authoritative side overwrites the non-authoritative side
- **Delete**
  - Deletion is only valid if the authoritative side no longer contains the identity
  - Unmanaged entries remain protected regardless of authority

### Conflict Handling

If both sides have diverged and neither can be proven newer:

- Preview mode: surface a **conflict**
- Apply mode: **fail fast**

Silent conflict resolution is forbidden.

### Future OAuth Considerations

When OAuth is enabled:

- Calendar mutations become first-class Apply operations
- Direction rules remain unchanged
- Authority resolution continues to rely on timestamps and stateHash comparison

### Non-Goals

This phase does NOT:

- Attempt semantic merges
- Infer user intent
- Resolve partial-field conflicts

It only selects **which side wins** and **where the mutation must be applied**.

The diff produces exactly three result sets:

```ts
DiffResult {
  creates: PlannedEvent[]
  updates: PlannedEvent[]
  deletes: PlannedEvent[]
}
```

### Creates

An entry is classified as **create** when:

- It exists in the desired state
- No existing scheduler entry shares its identity

---

### Updates

An entry is classified as **update** when:

- Its Manifest Event identity matches an existing scheduler entry
- Its Manifest Event stateHash differs from the existing Manifest Event stateHash

Rules:

- Identity fields are immutable
- Ordering differences alone do not trigger updates.

If two entries are otherwise identical, a change in scheduler index or position is not considered a diff.

- No partial updates are allowed

Updates are atomic at the Manifest Event level. If the Manifest Event stateHash differs, the entire Manifest Event is classified as an update; partial SubEvent updates are forbidden.

Comparison is performed exclusively via deterministic `stateHash` values produced during normalization. Field-level comparison is explicitly forbidden during Diff.

StateHash MUST be computed over the full Manifest Event state, including (at minimum) identityHash plus all SubEvent state. Therefore, any identity change necessarily implies a stateHash change as well.

Rules:
- Structural equivalence is evaluated, not textual representation
- Omitted and explicit `null` values are considered equivalent
- Defaulted values are normalized prior to comparison
- Symbolic timing fields are compared symbolically, not by resolved hard dates
- Hard date fields may be null by design and do not imply difference

The Diff phase has no knowledge of timing semantics, execution behavior, or payload structure beyond identity and `stateHash` equality.

---

### Deletes

An entry is classified as **delete** when:

- It exists in the scheduler
- Its identity does not exist in the desired state
- It is marked as **managed** by the Manifest

Manifest Identity must be stable across symbolic date changes.

Resolved or inferred dates must never be incorporated into identity.

Unmanaged entries must never be deleted.

---

## Managed vs Unmanaged Entries

Ownership (managed vs unmanaged) is defined by the Manifest and is authoritative.

Rules:

- Unmanaged entries are never deleted
- Unmanaged entries are never reordered
- Unmanaged entries may be surfaced in Preview
- Unmanaged entries must never produce DiffOperations
- Unmanaged entries must never be mutated by Apply

---

## Ordering Considerations

Ordering is evaluated **before** diffing.

The diff phase:

- Receives entries in final desired order
- Does not reorder entries
- Does not infer dominance

If an update would only change order:

- No update is emitted

Ordering is enforced during apply, not diff.

---

## Forbidden Behaviors

The diff phase MUST NOT:

- Repair invalid identities
- Guess intent
- Collapse or merge entries
- Create synthetic identities
- Modify ordering
- Mask planner errors

---

## Error Handling

The diff phase must fail fast on:

- Duplicate identities in desired state
- Duplicate identities in existing state
- Invalid or missing identity on managed entries

All failures must be explicit and surfaced.

Silent reconciliation is forbidden.

---

## Summary

The Diff & Reconciliation phase is:

- Identity-driven
- Minimal
- Deterministic
- Strict


---

## Phase 2.4 — Apply Phase (Execution Planning)

### Purpose

The Apply phase is responsible for turning a resolved DiffResult into concrete mutations against external systems (FPP and Calendar), while preserving:

- Authority rules (Phase 2.1)
- Time normalization (Phase 2.2)
- Adapter symmetry (Phase 2.3)

The Apply phase does NOT determine what has changed.  
It only determines how and where changes are executed.

---

### Directional, Not Symmetric

Although Calendar and FPP are equal contributors to the Manifest, each individual Diff operation has exactly one execution direction.

There is never a bidirectional mutation for a single identity within one reconciliation run.

---

### Apply Classification

Each Diff entry MUST be mapped to exactly one Apply action.

#### Create

- If identity exists in Calendar but not in FPP → Create in FPP
- If identity exists in FPP but not in Calendar → Create in Calendar
- If identity exists in neither → hard failure

#### Update

- Updates are always applied to the non-authoritative side
- Authority is resolved per Phase 2.1

Updates are atomic at the Manifest Event level.

#### Delete

- Deletes are only valid when the authoritative side no longer contains the identity
- Deletes apply only to managed identities
- Unmanaged identities are never deleted

---

### Execution Ordering

Apply actions MUST be executed in the following order:

1. Deletes
2. Updates
3. Creates

This ordering prevents identity collisions and preserves deterministic scheduler behavior.

---

### Apply Plan Model

Apply MUST first construct a deterministic execution plan before performing any I/O.

```
ApplyPlan {
  deletes: ApplyAction[]
  updates: ApplyAction[]
  creates: ApplyAction[]
}
```

Each ApplyAction includes:

```
{
  identityHash: string
  direction: "calendar→fpp" | "fpp→calendar"
  adapter: "calendar" | "fpp"
  payload: ManifestEvent
}
```

Execution planning MUST be pure and side-effect free.

---

### Adapter Responsibilities

The Apply phase MUST NOT perform:

- Data shape transformation
- Timezone conversion
- Semantic inference

All transformations are delegated to adapters.

Adapters MUST provide symmetric read and write behavior to prevent drift between ingestion and mutation.

---

### Failure Semantics

Apply failures are hard failures.

Rules:

- No partial Apply is permitted
- No silent downgrade is permitted
- On failure, the Manifest MUST remain unchanged

---

### Observability Requirement

Apply MUST emit a user-visible summary describing:

- Number of creates, updates, deletes
- Direction of each mutation
- Target system (FPP or Calendar)

This is mandatory groundwork for UI and auditability.

---

### Non-Goals

The Apply phase does NOT:

- Resolve conflicts
- Infer intent
- Merge SubEvents
- Modify identities
- Perform scheduling semantics


All such concerns are handled upstream.

---

## Phase 2.5 — Preview & UX Semantics

### Purpose

Preview provides a **human-auditable**, deterministic summary of what Apply *would* do, including **direction**, **authority**, and **why**.

Preview MUST be safe:
- No mutations
- No writes
- No network side effects beyond the minimum required to read sources

### Terminology

- **Operation**: create/update/delete classification for a Manifest Identity.
- **Direction**: where the mutation would be applied.
  - `calendar→fpp` means Apply will mutate FPP to match the calendar-derived desired state.
  - `fpp→calendar` means Apply will mutate the calendar source to match the FPP-derived desired state.
- **Authority**: which side “wins” for the given identity.

### Preview Output Contract

Preview MUST render (CLI and later UI) a stable list of operations, each including:

- `identityHash`
- `type` and `target`
- `operation`: create | update | delete
- `direction`: calendar→fpp | fpp→calendar
- `authoritativeSide`: calendar | fpp | planner-default
- `reason` (one of):
  - `identity-missing-on-side`
  - `statehash-different`
  - `unmanaged-protected`
  - `timestamp-unavailable-defaulted`
  - `conflict`

### Update Granularity

- Preview MUST treat updates as **atomic at the Manifest Event level**.
- Preview MUST NOT list “partial” subEvent-level updates as separate operations.

### Conflict Semantics

If both sides differ and neither can be proven newer (Phase 2.1):

- Preview MUST surface the identity as a **conflict**.
- Apply MUST fail fast on any conflict.

Preview MUST show:
- both observed stateHash values
- both observed timestamps (if available)
- why authority could not be resolved

### Unmanaged Entries

Preview MAY surface unmanaged entries for visibility, but MUST mark them as:

- `operation: none`
- `reason: unmanaged-protected`

Unmanaged entries MUST NOT appear in creates/updates/deletes.

### Idempotence Guarantee

Running Preview twice with no source changes MUST produce byte-for-byte identical output (except for logging timestamps).

### Human-Facing Summaries

Preview MUST include a concise summary block:

- total creates / updates / deletes
- totals grouped by direction (calendar→fpp vs fpp→calendar)
- total conflicts (must be 0 to apply)

This summary is required for CLI ergonomics and future UI.

### Non-Goals

Preview does NOT:

- infer user intent
- perform semantic merges
- repair identities
- resolve conflicts automatically

