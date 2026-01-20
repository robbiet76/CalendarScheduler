# 📘 Behavioral Specification — Change Log

This document records **intentional, versioned changes** to the Behavioral Specification.

The specification is considered **STABLE** by default.  
Any modification MUST be logged here.

---

## Versioning Rules

- The specification does **not** follow semantic versioning.
- Versions advance only when behavior meaningfully changes.
- Editorial or formatting changes do **not** require a version bump.

**Format:**
vX.Y — YYYY-MM-DD

---

## Change Policy

A change MUST:
1. Be intentional
2. Be discussed and agreed upon
3. Be recorded in this log
4. Reference the affected spec sections

Unlogged changes are considered **invalid**.

---

## v2.0 — 2026-01-15  
**Status:** Initial Stable Specification

### Summary
First frozen release of the Behavioral Specification after architecture redesign.

### Scope
All sections:

- 01 — System Purpose & Design Principles
- 02 — Architecture Overview
- 03 — Manifest
- 04 — Manifest Identity Model
- 05 — Calendar I/O
- 06 — Event Resolution & Normalization
- 07 — Events & SubEvents
- 08 — Scheduler Ordering Model
- 09 — Planner Responsibilities
- 10 — Diff & Reconciliation Model
- 11 — Apply Phase Rules
- 12 — FPP Semantic Layer
- 13 — Logging, Debugging & Diagnostics
- 14 — UI & Controller Contract
- 15 — Error Handling & Invariants
- 16 — Non-Goals & Explicit Exclusions
- 17 — Evolution & Extension Model

### Notes
- Manifest defined as the single authoritative source of truth
- Events/SubEvents model adopted (replacing bundles)
- Identity decoupled from scheduler settings
- Calendar provider abstraction formalized
- Backwards compatibility explicitly excluded
- All specification documents explicitly marked with **STABLE** headers at top-level

---

## v2.0.1 — 2026-01-15
**Status:** Editorial Stabilization

### Summary
Non-behavioral clarification pass to reinforce specification immutability.

### Scope
- All spec documents

### Changes
- Added explicit **STABLE** designation to every specification file
- No behavioral changes introduced

### Notes
- This version does not change system behavior
- No migration or implementation impact

---


# ## v2.3.1 — 2026-01-16
# **Status:** Specification Alignment
#
# ### Summary
# Explicitly aligned Planner-related specification sections with Phase 2.2 implementation handoff to eliminate ambiguity and prevent over-validation.
#
# ### Scope
# - 07 — Events & SubEvents
# - 08 — Scheduler Ordering Model
# - 09 — Planner Responsibilities
#
# ### Changes
# - Clarified that the Planner:
#   - Assumes a valid Manifest and does not re-enforce identity or structural invariants
#   - Operates as a pure, deterministic, non-persistent transformation stage
# - Explicitly documented that:
#   - Planner artifacts (`PlannedEntry`, `PlannerResult`, `OrderingKey`) are internal-only and never persisted
#   - Validation and invariant enforcement are exclusive to ManifestStore and ingestion boundaries
# - Codified that existing production-ready helper utilities (e.g. holiday resolution, parsing helpers) may be reused by the Planner **only** as pure functions
#
# ### Notes
# - No behavioral change to the Manifest, Diff, or Apply phases
# - This update is clarifying and constraining, not additive
# - Backwards compatibility remains intentionally unsupported

## v2.3 — 2026-01-16
**Status:** Behavioral Clarification

### Summary
Refined Manifest Identity semantics and hashing rules to ensure long-term stability and correct SubEvent handling.

### Scope
- 03 — Manifest
- 04 — Manifest Identity Model
- 07 — Events & SubEvents

### Changes
- Explicitly removed **start_date** and **end_date** from Manifest Identity.
- Formalized that Manifest Identity is derived **only** from:
  - type
  - target
  - days
  - start_time
  - end_time
- Clarified that:
  - Date information belongs to intent and SubEvent realization, not identity
  - Identity must remain stable across years and date changes
- Codified that:
  - One Calendar Event maps to one Manifest Event and one Identity
  - All SubEvents inherit the parent Event identity
  - SubEvents may have **distinct hashes**, but never distinct identities
- Explicitly prohibited date-derived hashing.

### Notes
- This change is identity-defining and constrains both Planner and ManifestStore implementations
- Prevents accidental duplication across seasonal or recurring schedules
- Backwards compatibility remains intentionally unsupported

---

## v2.2 — 2026-01-16
**Status:** Behavioral Clarification

### Summary
Clarified invariant enforcement philosophy and error-handling scope, with emphasis on provider-originated data.

### Scope
- 03 — Manifest
- 05 — Calendar I/O
- 15 — Error Handling & Invariants

### Changes
- Clarified that invariant enforcement is primarily focused on **calendar provider input**, not FPP scheduler output.
- Codified a **hard-fail strategy** for invalid or ambiguous provider data:
  - Fail fast
  - No silent correction
  - No speculative healing
- Explicitly documented that:
  - FPP-originated scheduler data is assumed structurally valid
  - Defensive validation of FPP output is intentionally minimal
- Reinforced the principle that error-handling infrastructure must remain **small, explicit, and intentional**.

### Notes
- This change constrains implementation strategy but does not introduce new features
- Emphasizes correctness and clarity over resilience to malformed input
- Backwards compatibility remains intentionally unsupported

---

## v2.1 — 2026-01-16
**Status:** Behavioral Clarification

### Summary
Formalized dual-date handling and DatePattern semantics within the Manifest.

### Scope
- 03 — Manifest
- 04 — Manifest Identity Model (reference alignment)

### Changes
- Introduced **dual-date representation** in the Manifest:
  - Hard dates are always preserved when provided
  - Symbolic dates are additionally stored when a hard date resolves to an FPP-defined holiday
- Replaced single `date_pattern` usage with explicit:
  - `start_date: DatePattern`
  - `end_date: DatePattern`
- Clarified that:
  - `DatePattern` and `0000-XX-XX` style FPP patterns are equivalent concepts
  - No date information is inferred, collapsed, or discarded
- Codified lossless date preservation as a Manifest invariant

### Notes
- No behavioral change to Apply or Planner phases yet
- This change is preparatory and constrains future implementation
- Backwards compatibility remains intentionally unsupported

## Future Versions

_Add new entries above this line._

Each entry should include:
- Summary
- Affected sections
- Behavioral impact
- Migration notes (if any)