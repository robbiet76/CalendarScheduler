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

## Future Versions

_Add new entries above this line._

Each entry should include:
- Summary
- Affected sections
- Behavioral impact
- Migration notes (if any)