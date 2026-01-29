# Resolution

This directory contains the **Event Resolution layer** of GoogleCalendarScheduler.

## Purpose
Resolution is responsible for **analyzing differences** between:
- Provider-derived state (e.g. calendar snapshots, manifests)
- Existing system state (e.g. plugin manifest, FPP schedule)

…and producing a **pure, side-effect-free description of intent**.

No mutation, writing, or execution happens here.

## What Resolution Does
- Normalizes disparate event sources into `ResolvableEvent` objects
- Compares identity + timing + ownership semantics
- Produces a deterministic list of `ResolutionOperation` objects
- Enforces safety and ownership rules via `ResolutionPolicy`
- Supports dry-run / read-only analysis

## What Resolution Does *Not* Do
- Does **not** write to FPP or manifests
- Does **not** mutate schedules
- Does **not** infer policy or ownership
- Does **not** apply diffs

## Key Files
- `ResolvableEvent` – canonical event representation
- `ResolutionInputs` – explicit, validated resolver inputs
- `ResolutionPolicy` – safety, ownership, and mode controls
- `ResolutionOperation` – atomic CREATE / UPDATE / DELETE / NOOP intent
- `ResolutionResult` – full, inspectable resolution output
- `CalendarManifestResolver` – concrete resolver for calendar → manifest

## Design Goals
- Deterministic
- Auditable
- Testable
- Safe by default

Resolution output is intended to be:
- Reviewed by humans
- Visualized by UI
- Translated into Diff / Apply steps later

This separation is intentional and foundational to user trust.