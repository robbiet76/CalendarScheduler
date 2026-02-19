# 20) Resolution Regression Suite

## Goal
Define a complete regression set for the Resolution layer, with emphasis on cases where a single calendar event must map to multiple FPP schedule entries.

This suite is the authoritative checklist for segment creation, override shaping, cancellation handling, and ordering behavior.

## Scope
In scope:
- Calendar recurrence expansion behavior
- EXDATE/cancellation splitting
- Override segment creation and merge rules
- Bundle ordering constraints (override vs base)
- Manifest subevent geometry and round-trip invariants

Out of scope:
- OAuth flow correctness
- General UI styling/interaction
- Provider auth setup details

## Core Invariants (Must Hold For Every Case)
1. No semantic loss: all executable behavior represented in subevents.
2. Deterministic output: same input yields same segment set and ordering.
3. Bundle integrity: base + overrides remain coherent atomic grouping.
4. Ordering correctness:
- Override rows above base rows when overlap requires precedence.
- Cross-bundle overlap resolved by canonical precedence rules from `spec/08-scheduler-ordering.md`.
- Non-overlap fallback is chronological ordering by start date/time.
- Command rows are grouped at the bottom and remain chronological within the command group.
- Symbolic timing precedence uses estimated display-time windows (FPP env timezone/lat/lon), with deterministic fallback when estimates are unavailable.
5. Convergence: after apply, next preview converges (`noop=true`) unless source changed.
6. Round-trip safety: Calendar -> FPP -> Calendar (or reverse) preserves intent.

## Test Data Convention
For each case, capture:
- `calendar fixture`: recurrence, timezone, EXDATE, override edits
- `expected segments`: count and date boundaries
- `expected ordering`: execution order within and across bundles
- `expected target mapping`: playlist/sequence/command and behavior
- `expected convergence`: pre/apply/post counters

## Resolution Case Matrix
### RR-01 Base Recurrence (No Exceptions)
- Pattern: Daily/weekly recurring event with no EXDATE/override.
- Expected: Exactly one base segment (or minimal canonical segmentation).
- Assert: no redundant splits.

### RR-02 Single Mid-Range EXDATE Split
- Pattern: Date range with one deleted occurrence in middle.
- Expected: Two base segments (before/after deleted date).
- Assert: deleted day absent from FPP execution.

### RR-03 Multiple EXDATE Split
- Pattern: Date range with multiple deleted dates.
- Expected: N+1 segments where N is number of internal deletion boundaries.
- Assert: all deleted dates removed, no accidental gap collapse.

### RR-04 EXDATE At Range Start
- Pattern: First occurrence deleted.
- Expected: Leading segment trimmed; no empty segment emitted.

### RR-05 EXDATE At Range End
- Pattern: Last occurrence deleted.
- Expected: Trailing segment trimmed; no empty segment emitted.

### RR-06 All Occurrences Deleted
- Pattern: EXDATE removes every occurrence.
- Expected: No executable subevents emitted for that event.
- Assert: apply removes prior managed entries cleanly.

### RR-07 Single-Date Time Override
- Pattern: Override day changes end time.
- Expected: Override subevent plus base coverage on non-overridden days.
- Assert: overlap/precedence represented correctly in FPP order.

### RR-08 Multi-Date Contiguous Time Override
- Pattern: Override spans contiguous date block.
- Expected: One merged override segment (not fragmented per-day unless required).

### RR-09 Multi-Date Non-Contiguous Time Overrides
- Pattern: Two override blocks separated by base days.
- Expected: Two override segments + base segmentation around them.

### RR-10 StopType Override
- Pattern: Override modifies stop type only.
- Expected: Separate override subevent due to behavior change.
- Assert: stop type preserved round-trip.

### RR-11 Target Override
- Pattern: Override changes playlist/sequence target.
- Expected: Distinct override subevent with alternate target.
- Assert: no accidental collapse into base target.

### RR-12 Command Payload Override and Command Grouping
- Pattern: Command row with override payload delta.
- Expected: Override subevent preserved with payload keys.
- Assert: command args/options round-trip and resulting command rows remain in the bottom command cluster.

### RR-13 Symbolic Date Boundary
- Pattern: Holiday symbolic date in range boundary.
- Expected: Symbolic token preserved in identity/hash path; hard date only resolution fallback.
- Assert: no hash drift from resolved hard date.

### RR-14 Symbolic Time Boundary (Sunrise/Sunset)
- Pattern: Symbolic start/end time with offset.
- Expected: Overlap handoff behavior preserved; no unintended collapsing.

### RR-15 Overnight Window
- Pattern: Start time > end time semantics or cross-midnight behavior as supported.
- Expected: Correct segment interpretation and ordering with neighboring rows.

### RR-16 Weekday-Constrained Recurrence
- Pattern: Weekly subset days (`MO/WE/FR`, etc).
- Expected: Canonical weekday normalization and stable segment set.

### RR-17 Timezone-Sensitive Boundary
- Pattern: Same recurrence interpreted across timezone transitions.
- Expected: Stable canonical timing in manifest/FPP semantics.

### RR-18 Bundle Atomic Ordering
- Pattern: Base + multiple overrides in one bundle.
- Expected:
- Within bundle: override precedence above base where required.
- Across bundle rows: chronological unless exception required.

### RR-19 Cross-Bundle Overlap Precedence
- Pattern: Multiple independent bundles.
- Expected: Non-overlapping bundles remain chronological; overlapping bundles follow precedence rules; bundle atomicity preserved.

### RR-20 Manual FPP Reorder Recovery
- Pattern: User manually reorders FPP rows, then sync.
- Expected: Canonical order restored by scheduler rules.
- Assert: final preview converges.

### RR-21 Calendar->FPP Mirror With Segments
- Pattern: Segment-rich calendar event synced in one-way calendar mode.
- Expected: FPP mirrors segmented structure exactly.

### RR-22 FPP->Calendar Mirror With Segments
- Pattern: Segment-rich FPP schedule synced in one-way FPP mode.
- Expected: Calendar receives equivalent event/exception geometry.

### RR-23 Two-Way Merge Segment Stability
- Pattern: Start with segmented structure, edit one side, reconcile both.
- Expected: Only necessary mutations; no duplicate/phantom segment creation.

### RR-24 Tombstone Interaction With Segmented Event
- Pattern: Delete one segmented logical event on one side.
- Expected: Proper delete propagation without partial orphan segments.

### RR-25 Idempotent Re-Apply
- Pattern: Apply same resolved state twice.
- Expected: second apply is no-op.

### RR-26 Overlap Same Daily Start, Later Calendar Start Wins
- Pattern: Two overlapping bundles with identical daily start/end; one starts later in calendar date.
- Expected: Later-starting bundle executes above earlier season bundle.

### RR-27 Overlap Specific Window Over Broad Background
- Pattern: Broad background bundle overlaps narrower window bundle.
- Expected: Specific/narrow window bundle executes above broad background bundle.

### RR-28 Non-Overlap Chronological Fallback
- Pattern: Two independent bundles with no overlap.
- Expected: Chronological ordering remains intact.

### RR-29 Later-Start Show Over Static Overlap
- Pattern: Broad static layer overlaps a later-start show window on intersecting days.
- Expected: Later-start show executes above overlapping static layer.

## Minimum Regression Gate (Per Patch)
Run at least:
- RR-02
- RR-07
- RR-10
- RR-11
- RR-12
- RR-18
- RR-19
- RR-23
- RR-25
- RR-26
- RR-27
- RR-29

## Full Regression Gate (Before Release)
Run all RR-01 through RR-29.

Automated command:

```bash
php bin/cs-full-regression --label=release-gate --skip-live
```

## Execution Workflow
1. Prepare scenario in calendar/FPP.
2. Run:
```bash
php bin/cs-regression --label=<CASE_ID> --apply --expect-post-noop=true
```
3. Capture and archive:
- `/tmp/cs-regression/<timestamp>-<CASE_ID>/report.json`
- preview/apply raw outputs
4. Validate segment geometry and order with focused debug dump for touched identities.

## Failure Classification
When a case fails, classify as:
- `SEGMENT_GEOMETRY`: wrong split/merge boundaries
- `OVERRIDE_SEMANTICS`: override payload/behavior/target mismatch
- `ORDERING`: execution order incorrect
- `ROUND_TRIP`: reverse-direction mismatch
- `CONVERGENCE`: post-apply preview not clean
- `TOMBSTONE`: delete propagation mismatch

This classification is required for triage and prevents ambiguous bug reports.
