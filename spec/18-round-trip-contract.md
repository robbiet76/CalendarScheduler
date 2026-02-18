# 18) Round-Trip Contract (FPP <-> Calendar)

## Goal
1. Calendar must represent what FPP will actually execute.
2. Sync must be reversible in both directions:
   1. `FPP -> Calendar -> FPP`
   2. `Calendar -> FPP -> Calendar`
3. After a successful apply, a second pass must converge to `noop`.

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

## Hash Contract
1. `identityHash` includes only stable logical identity.
2. `identityHash` excludes mutable execution state (`executionOrder`, timestamps, provider IDs).
3. `stateHash` includes executable state, including `executionOrder`.
4. Reorder-only changes MUST produce a state diff (`update`).

## Calendar Metadata Contract
Calendar-side private metadata must persist round-trip fields:
1. `cs_manifest_event_id`
2. `cs_subevent_id`
3. `cs_bundle_id`
4. `cs_execution_order`
5. `cs_role`
6. `cs_format_version`

This metadata is authoritative for rebuilding FPP ordering and bundle semantics during reverse sync.

## FPP -> Calendar Rules
1. Preserve FPP order into `cs_execution_order`.
2. Group related rows into bundle context.
3. Evaluate overlap by date and time window using `executionOrder`.
4. Suppress lower-priority coverage only when fully shadowed by higher-priority coverage on that day.
5. Keep both when overlap is partial.
6. Treat boundary-touch (`end == next start`) as non-overlap.
7. Write metadata needed for full reverse reconstruction.

## Calendar -> FPP Rules
1. Rebuild bundles from persisted metadata first.
2. Rebuild row ordering from `cs_execution_order`.
3. If metadata is missing, use deterministic fallback ordering and mark degraded fidelity.
4. Apply the same overlap/shadow model used in forward direction.
5. Emit FPP rows in final execution order.

## Reconciliation Rules
1. Order changes are state changes.
2. Shadow/exclusion outcomes are state changes.
3. Two-way mode applies authority/timestamp only after identity match.
4. One-way modes still preserve metadata for reversibility.

## Acceptance Criteria
1. `FPP -> Calendar -> FPP` reproduces equivalent rows (type/target/timing/behavior/order).
2. `Calendar -> FPP -> Calendar` reproduces equivalent events (including exclusions/overrides/metadata).
3. Reorder-only edits show pending updates and apply cleanly.
4. Full containment correctly suppresses lower-priority execution on affected day(s).
5. Partial overlaps do not suppress both windows.
6. Post-apply second sync is `noop`.
