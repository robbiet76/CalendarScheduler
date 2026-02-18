# 19) Regression Test Matrix

## Goal
Provide a repeatable, scenario-based regression pass so behavior can be validated quickly after each patch.

This matrix is intentionally focused on high-value behavior that has regressed during active development: sync direction, tombstones, overrides, ordering, and convergence.

## Runner
Use `bin/cs-regression` for pre/apply/post capture and assertions.

Example:

```bash
php bin/cs-regression \
  --label=sync-baseline \
  --apply \
  --expect-pre-noop=false \
  --expect-post-noop=true
```

Artifacts are written to `/tmp/cs-regression/<timestamp>-<label>/`.

## Core Scenarios
### R1. Baseline Convergence
- Setup:
  - Use currently selected calendar and current FPP schedule.
- Expectation:
  - If pre-run has pending actions, post-run after apply must converge (`post.noop=true`).

### R2. Calendar -> FPP Mirror (New/Empty Calendar)
- Setup:
  - Select a calendar with zero relevant events.
  - Keep populated FPP schedule.
  - Set sync mode to `Calendar -> FPP`.
- Expectation:
  - Pre-run shows FPP deletes/updates needed to mirror calendar.
  - Post-run converges.

### R3. FPP -> Calendar Mirror (New/Empty Calendar)
- Setup:
  - Select a calendar with zero relevant events.
  - Keep populated FPP schedule.
  - Set sync mode to `FPP -> Calendar`.
- Expectation:
  - Pre-run shows calendar creates.
  - Post-run converges.

### R4. Two-Way Merge Calendar Delete Tombstone
- Setup:
  - In a managed calendar, delete one event.
  - Keep corresponding FPP entry present.
  - Sync mode `Both`.
- Expectation:
  - Pre-run reflects delete propagation from calendar side.
  - Tombstone file records calendar-side tombstone.
  - Post-run converges without re-creating deleted event.

### R5. Two-Way Merge FPP Delete Tombstone
- Setup:
  - Delete one FPP entry and save schedule.
  - Keep corresponding calendar event present.
  - Sync mode `Both`.
- Expectation:
  - Pre-run reflects delete propagation from FPP side.
  - Tombstone file records FPP-side tombstone.
  - Post-run converges without re-creating deleted entry.

### R6. EXDATE Split Round-Trip
- Setup:
  - Calendar recurring event range with one deleted occurrence (`EXDATE`).
  - Sync `Calendar -> FPP`, apply.
- Expectation:
  - FPP contains expected split segments.
  - Reverse sync (`FPP -> Calendar`) preserves equivalent segmentation.
  - Final preview converges.

### R7. Override Time Window
- Setup:
  - Base range plus override dates with different end time.
  - Sync both directions in sequence.
- Expectation:
  - Override represented correctly in FPP and calendar.
  - No duplicate drift after second apply.

### R8. Override StopType
- Setup:
  - Base range plus override with alternate stop type (for example Hard Stop).
  - Sync both directions in sequence.
- Expectation:
  - Stop type retained after round-trip.
  - Final preview converges.

### R9. Override Target (Playlist/Sequence Change)
- Setup:
  - One override date uses different target than base.
  - Sync calendar to FPP and reverse.
- Expectation:
  - Override target preserved.
  - No duplicate same-day artifacts after reverse sync.

### R10. Canonical Ordering Enforcement
- Setup:
  - Create overlapping base/override bundle where ordering matters.
  - Manually reorder FPP rows in UI and save.
  - Sync mode `Calendar -> FPP` (or `Both`), apply.
- Expectation:
  - Plugin restores canonical execution order.
  - Final preview converges.

### R11. Ordering Change Reason Clarity
- Setup:
  - Introduce order-only drift between sides.
  - Sync mode `Both`.
- Expectation:
  - Pending reason text indicates order change (not generic content change).

### R12. Calendar Switch Isolation
- Setup:
  - Switch selected calendar from A to B.
  - Keep tombstone file populated from prior runs.
- Expectation:
  - Tombstones are scoped by calendar; no cross-calendar delete leakage.

## Fast Regression Pass (Recommended Daily)
Run this subset after each patch:
- `R1`, `R3`, `R4`, `R6`, `R10`

This catches the majority of high-risk regressions while staying fast.

## Optional CLI Assertions
For deterministic checks, pass expectations into runner:

```bash
php bin/cs-regression \
  --label=example \
  --apply \
  --expect-pre-noop=false \
  --expect-post-noop=true \
  --expect-post-fpp-updated=0 \
  --expect-post-calendar-updated=0
```

## Notes
- This matrix validates behavior, not implementation details.
- Scenario setup (calendar edits, FPP UI edits) is intentionally manual where provider/UI interaction is required.
- Runner outputs are intended for quick comparison and debugging artifacts.

