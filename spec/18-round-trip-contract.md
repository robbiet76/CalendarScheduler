# 18) Round-Trip Contract (FPP <-> Calendar)

## Goal
1. Calendar must represent full scheduling intent, including overlapping entries.
2. Sync must be reversible in both directions:
   1. `FPP -> Calendar -> FPP`
   2. `Calendar -> FPP -> Calendar`
3. Runtime winner/execution behavior is computed from FPP top-down order and shown as derived state (not persisted by deleting/suppressing calendar intent).
4. After a successful apply, a second pass must converge to `noop`.

## Canonical Subevent Model
Each canonical subevent must carry:
1. `type`
2. `target`
3. `timing`
4. `behavior`
5. `executionOrder`
6. `bundleId`
7. `role` (`base` or `override`)

Additional invariants:
1. `executionOrder` is absolute scheduler order (`0` = highest priority).
2. Interval math uses half-open windows (`[start, end)`).
3. Symbolic timing/date tokens remain canonical-first for identity matching.
4. Calendar may carry `executionOrder` metadata, but calendar edits are not treated as an authority to reorder FPP.
5. Global order follows `spec/08-scheduler-ordering.md` (baseline chronology plus overlap-aware precedence).

## Hash Contract
1. `identityHash` includes only stable logical identity.
2. `identityHash` excludes mutable execution state (`executionOrder`, timestamps, provider IDs).
3. `stateHash` includes executable state, including `executionOrder`.
4. Reorder-only changes originating from FPP MUST produce a state diff (`update`).
5. Calendar-side timing/target/behavior edits may change state, but calendar-side reorder attempts are ignored unless an explicit reorder capability is added in a future version.

## Calendar Metadata Contract
Calendar-side private metadata must persist round-trip fields:
1. `cs_manifest_event_id`
2. `cs_subevent_id`
3. `cs_bundle_id`
4. `cs_execution_order`
5. `cs_role`
6. `cs_format_version`

This metadata is used to preserve round-trip fidelity and continuity, but does not override canonical ordering rules.

## FPP -> Calendar Rules
1. Preserve FPP order into `cs_execution_order`.
2. Group related rows into bundle context.
3. Write all subevents as calendar intent, even when overlapping.
4. Do not suppress lower-priority rows using EXDATE or equivalent shadow-pruning.
5. Overlap is valid and expected (including symbolic-time handoff patterns).
6. Write metadata needed for full reverse reconstruction.

## Calendar -> FPP Rules
1. Rebuild bundles from persisted metadata first.
2. Recompute canonical global ordering using the scheduler ordering model.
3. If metadata is missing, use deterministic fallback ordering and mark degraded fidelity.
4. Do not infer or force calendar-side reorder as FPP reorder.
5. Emit FPP rows in stable final execution order while preserving overlaps.

## Reconciliation Rules
1. FPP order changes are state changes.
2. Shadow/winner status is derived runtime interpretation and not a calendar mutation target.
3. Two-way mode applies authority/timestamp only after identity match.
4. One-way modes still preserve metadata for reversibility.
5. Manual FPP reorder that conflicts with canonical ordering is treated as drift and corrected on apply.

## Acceptance Criteria
1. `FPP -> Calendar -> FPP` reproduces equivalent rows (type/target/timing/behavior/order).
2. `Calendar -> FPP -> Calendar` reproduces equivalent events (including overlaps/overrides/metadata).
3. Overlapping entries remain present on calendar after sync (no shadow-pruning).
4. Reorder-only edits on FPP show pending updates and apply restores canonical order.
5. Symbolic-time overlap handoff scenarios round-trip without losing entries.
6. Post-apply second sync is `noop`.
