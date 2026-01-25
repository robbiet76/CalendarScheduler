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

Symbolic timing fields (e.g. holidays, seasonal markers):

- Are first-class identity-stable inputs
- Must not be resolved to concrete dates during Diff
- Must be compared symbolically
- Must not influence identity matching

Resolved dates are an execution concern and are out of scope for Diff.

If identity is missing or invalid:

- **Preview mode:** Surface the issue
- **Apply mode:** Fail fast

---

## Diff Categories

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

- Identity matches an existing scheduler entry
- One or more non-identity fields differ

Rules:

- Identity fields are immutable
- Ordering differences alone do not trigger updates.

If two entries are otherwise identical, a change in scheduler index or position is not considered a diff.

- No partial updates are allowed

Updates are atomic at the Manifest Event level. If any SubEvent realization differs, the entire event is classified as an update; partial SubEvent updates are forbidden.

Comparison is performed against the canonical Manifest representation.

Rules:
- Structural equivalence is evaluated, not textual representation
- Omitted and explicit `null` values are considered equivalent
- Defaulted values are normalized prior to comparison
- Symbolic timing fields are compared symbolically, not by resolved hard dates
- Hard date fields may be null by design and do not imply difference

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

It is not intelligent, adaptive, or forgiving by design.

