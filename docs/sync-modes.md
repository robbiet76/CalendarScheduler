# Sync Mode Behavior Guide

Calendar Scheduler supports three sync modes.

## `Both` (Two-way Merge)
Use when both Calendar and FPP can be edited and should converge.

Behavior:
- Reconciliation compares both sides.
- Pending actions may target both `calendar` and `fpp`.
- Apply may write to both systems.

Best for:
- Normal day-to-day bidirectional synchronization.

## `Calendar -> FPP`
Use when Google Calendar is authoritative.

Behavior:
- Only FPP-targeted changes are executable.
- Calendar-targeted changes may appear in diagnostics context but are not applied.
- Apply safety checks block opposite-direction writes.

Best for:
- Teams where scheduling is managed in calendar and pushed to FPP.

## `FPP -> Calendar`
Use when FPP scheduler is authoritative.

Behavior:
- Only calendar-targeted changes are executable.
- FPP-targeted changes are not applied.
- Apply safety checks block opposite-direction writes.

Best for:
- Teams that primarily edit in FPP and publish equivalent calendar state.

## Choosing A Mode
1. Start with your real source of truth (`Calendar -> FPP` or `FPP -> Calendar`).
2. Validate first apply result.
3. Switch to `Both` only if two-way reconciliation is required.

## Verification Checklist Per Mode
1. Confirm `Diagnostics.syncMode` matches selection.
2. Confirm pending-action targets match expected direction.
3. Apply and verify post-preview convergence.
