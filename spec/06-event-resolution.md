**Status:** STABLE  
> **Change Policy:** Intentional, versioned revisions only  
> **Authority:** Behavioral Specification v2

# 06 — Event Resolution & Normalization

## Purpose

The **Event Resolution & Normalization layer** translates **calendar-facing event data** into **scheduler-intent semantics** suitable for Manifest construction.

It answers the question:

> **“What does this calendar event *mean* in scheduler terms?”**

This layer is responsible for:
- Interpreting calendar patterns
- Preserving symbolic meaning
- Producing normalized, provider-agnostic intent
- Detecting unsupported or ambiguous constructs

This layer is **purely semantic**. It does **not** write scheduler entries, compare existing state, or apply guard rules.

---

## Position in the System

```
Calendar I/O
   ↓
Event Resolution & Normalization
   ↓
Manifest (Intent + Identity)
   ↓
Planner → Diff → Apply
```

---

## Inputs

Resolution consumes **provider-neutral calendar events**, as emitted by the Calendar I/O layer.

Inputs:
- May include recurrence rules, exception dates, symbolic times
- May be open-ended or partially specified
- Are not yet scheduler-compatible

Resolution must not assume:
- A specific calendar provider
- A specific transmission format
- A specific scheduler implementation

---

## Outputs

Resolution produces **normalized execution semantics**, expressed as provider-agnostic timing and execution records suitable for SubEvent construction.

Resolution output:
- Preserves symbolic meaning
- Canonicalizes timing semantics
- Identifies base semantics and exception semantics

Outputs are:
- Deterministic
- Provider-agnostic
- Safe for downstream Manifest construction

---

## Core Responsibilities

### 1. Interpret Calendar Semantics

Resolution interprets:
- Recurrence rules
- Date windows
- Day-of-week constraints
- Exception dates
- Time windows

This interpretation is semantic, not mechanical.

---

### 2. Preserve Symbolic Meaning

Resolution must **not eagerly resolve** symbolic constructs such as:
- Dawn / Dusk
- Holidays
- Open-ended date patterns

Instead, it converts them into:
- `TimeToken`
- `DatePattern`

Symbolic intent is preserved until the FPP semantic layer.

---

### 3. Normalize Timing Semantics

All timing information is normalized into canonical forms:
- Unified day masks
- Canonical time tokens
- Structured date patterns

Equivalent calendar expressions must produce identical normalized output.

---

### 4. Detect Unsupported Patterns

Resolution must explicitly detect unsupported or ambiguous patterns, such as:
- Multiple disjoint time windows
- Irregular recurrence rules
- Provider-specific constructs without semantic equivalents

Unsupported patterns:
- Are explicitly flagged
- Include diagnostic context
- Must not be silently coerced

---

### 5. Decompose into Base and Exception Semantics When Required

If a calendar event cannot be expressed as a single execution semantic, resolution must:
- Identify one base semantic
- Generate exception semantics

Resolution produces base and exception semantics but does not order or schedule them.

---

## Explicit Non-Responsibilities

Resolution must not:
- Read `schedule.json`
- Inspect existing scheduler state
- Assign scheduler IDs
- Apply guard dates
- Resolve symbolic dates
- Perform diffing or apply actions
- Construct Manifest Events
- Construct SubEvents

---

## Provider Independence

Resolution operates on abstract calendar concepts.

Provider-specific logic:
- Lives exclusively in Calendar I/O
- Must not leak into Intent or Identity

---

## Determinism Guarantees

Resolution must be:
- Deterministic
- Idempotent
- Order-independent

Same input must always yield the same output.

---

## Failure Modes

Resolution may produce:

1. **Resolved Intent**
2. **Partially Resolved Intent** (symbolic)
3. **Unresolved Event** (explicitly flagged)

Silent failure is forbidden.

---

## Relationship to Manifest Identity

Resolution produces normalized execution semantics, not Identity.

Identity derivation occurs later and must not influence resolution behavior.

---

## Relationship to FPP Semantics

Resolution is scheduler-agnostic.

It applies no FPP-specific rules or defaults.

---

## Design Rule (Hard)

> **If resolution must guess, the design is wrong.**

Ambiguity must surface explicitly.

---

## Summary

Event Resolution & Normalization is the semantic heart of the system.

It ensures clarity, determinism, and long-term correctness by preserving meaning rather than prematurely resolving it.

