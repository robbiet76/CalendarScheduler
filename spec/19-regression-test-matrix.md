# 19) Regression Test Matrix

## Goal
Provide a repeatable, provider-parity regression matrix so behavior is validated quickly after each patch.

This matrix is intentionally focused on high-value behavior that has regressed during active development: sync direction, tombstones, overrides, ordering, and convergence.

## Runners
Use the following runners together:
- `bin/cs-resolution-regression`: deterministic in-memory scenario suite (`RR-01..RR-29`).
- `bin/cs-regression`: live pre/apply/post convergence.
- `bin/cs-provider-parity-regression`: adapter parity checks for **Google + Outlook**.
- `bin/cs-full-regression`: one-command orchestrator for all suites.

Use `bin/cs-regression` for pre/apply/post capture and assertions.

Example:

```bash
bin/cs-regression \
  --label=sync-baseline \
  --apply \
  --expect-pre-noop=false \
  --expect-post-noop=true
```

Artifacts are written to `/tmp/cs-regression/<timestamp>-<label>/`.

For one-command automation of the full flow (resolution + live + provider parity):

```bash
bin/cs-full-regression --label=nightly
```

Artifacts are written to `/tmp/cs-full-regression/<timestamp>-<label>/`.

## Provider Parity Dimensions (Must Pass)
For every patch, parity must hold for both providers:
- `metadata roundtrip`: provider metadata schema read/write is stable.
- `mapper parity`: recurrence/timezone/managed metadata are emitted correctly.
- `translator parity`: recurrence + managed metadata normalization are stable.
- `convergence gate`: apply once, second preview is noop.

Run:

```bash
bin/cs-provider-parity-regression
```

Or JSON mode:

```bash
bin/cs-provider-parity-regression --json
```

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

### R13. Time Boundary Combination Sweep
- Setup:
  - Run RR time-combo cases (`RR-01`, `RR-12`, `RR-13`, `RR-30`).
- Expectation:
  - All four hard/symbolic start/end time combinations converge and preserve intended symbolic tokens.

### R14. Date Boundary Combination Sweep
- Setup:
  - Validate hard/hard date via automated RR baseline.
  - Validate symbolic-date combinations in live provider scenarios (holiday/date symbolic flows).
- Expectation:
  - No drift in resolved start/end date boundaries; symbolic date behavior remains stable across sync/apply.

### R15. Day Mask Sweep
- Setup:
  - Run multiple weekly masks (`RR-15`, `RR-31`, `RR-32`).
- Expectation:
  - BYDAY masks are preserved exactly and remain deterministic after round-trip.

### R16. Multi-Entry Expansion Scenarios
- Setup:
  - Run segment-rich one-event scenarios (`RR-10`, `RR-20`, `RR-33`).
- Expectation:
  - Single calendar events can expand into multi-subevent/multi-FPP row outputs without convergence drift.

### R17. Command Variants
- Setup:
  - Run command-centric scenarios (`RR-11`, `RR-33`).
- Expectation:
  - Command type, override behavior, and segmentation remain stable and converge on second pass.

## Fast Regression Pass (Recommended Daily)
Run this subset after each patch:
- `R1`, `R3`, `R4`, `R6`, `R10`
- Provider parity runner (`bin/cs-provider-parity-regression`)
- RR combination sweep: `RR-12`, `RR-13`, `RR-30`, `RR-31`, `RR-33`

This catches the majority of high-risk regressions while staying fast.

## Optional CLI Assertions
For deterministic checks, pass expectations into runner:

```bash
bin/cs-regression \
  --label=example \
  --apply \
  --expect-pre-noop=false \
  --expect-post-noop=true \
  --expect-post-fpp-updated=0 \
  --expect-post-calendar-updated=0
```

If you only want the in-memory resolution suite from the full runner:

```bash
bin/cs-full-regression --label=resolution-only --skip-live
```

If you only want live convergence checks:

```bash
bin/cs-full-regression --label=live-only --skip-resolution
```

If you want to isolate provider parity:

```bash
bin/cs-full-regression --label=provider-only --skip-resolution --skip-live --skip-api-smoke
```

## Notes
- This matrix validates behavior, not implementation details.
- Scenario setup (calendar edits, FPP UI edits) is intentionally manual where provider/UI interaction is required.
- Runner outputs are intended for quick comparison and debugging artifacts.
