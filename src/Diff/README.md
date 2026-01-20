# Phase 2.3 — Diff (spec v2.3.1)

Inputs:
- Desired: PlannerResult (PlannedEntry[])
- Current: existing scheduler entries (arrays/objects)

Identity:
- Canonical reconciliation key is `identity_hash` (derived from canonicalized identity; immutable).

Managed/unmanaged rules:
- Planned entries are managed intent.
- Existing unmanaged entries are never deleted/updated/reordered.
- If an unmanaged existing entry has an `identity_hash` that collides with a planned identity, Diff fails fast.

Update semantics:
- Ordering differences alone do NOT trigger updates.
- Equivalence compares only `target` and `timing` (normalized deep-key sort).

Output:
- DiffResult { creates, updates, deletes } only.
- No unchanged list.