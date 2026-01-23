**Status:** STABLE  
> **Change Policy:** Intentional, versioned revisions only  
> **Authority:** Behavioral Specification v2

# 06 — Event Resolution

## Purpose

**Event Resolution** is the process by which **two sets of events (source vs manifest) are compared to determine intent-level differences**.

It answers the question:

> “Given declared intent and existing state, what has changed?”

Event Resolution operates **after identity construction** and **before diff generation**.

This layer is:
- Pure
- Deterministic
- Side-effect free

---

## Position in the System

Calendar Snapshot / FPP Snapshot  
↓  
Event Resolution  
↓  
Diff Intent  
↓  
Apply (FPP / Calendar)

---

## Inputs

Event Resolution consumes two sets of **ResolvableEvents**:

- Source events (calendar, yaml, manual intent)
- Existing manifest events

Each ResolvableEvent must include:
- `identity`
- `identity_hash`
- `ownership`
- `correlation`
- `subEvents`

---

## Outputs

Event Resolution produces a set of **ResolutionResults**.

Each ResolutionResult includes:
- `identity_hash`
- `status` (CREATE | UPDATE | DELETE | CONFLICT | NOOP)
- `reason`
- Optional `DiffIntent`

---

## Resolution Rules

Resolution is performed per identity hash.

Rules are evaluated in order:

1. If identity exists in source but not manifest → CREATE  
2. If identity exists in manifest but not source → DELETE  
3. If identity exists in both:  
   - If subEvents, timing, behavior, or payload differ → UPDATE  
   - If ownership.locked = true and differences exist → CONFLICT  
   - Otherwise → NOOP  

Resolution must never mutate ownership.

---

## Divergence Detection

Two events are divergent if any of the following differ:
- Number of subEvents
- Timing of any subEvent
- Behavior of any subEvent
- Payload of any subEvent

Divergence detection is structural, not semantic.

---

## Explicit Non-Responsibilities

Event Resolution must not:
- Apply changes
- Modify manifest state
- Modify ownership
- Interpret scheduler runtime behavior
- Resolve conflicts heuristically

---

## Relationship to Identity

Identity derivation occurs **before** Event Resolution.

Event Resolution treats identities as immutable.

---

## Relationship to Scheduler Semantics

Event Resolution is scheduler-agnostic and does not reason about runtime execution order or overlap.

---

## Design Rule (Hard)

> **If resolution must guess, the design is wrong.**

Ambiguity must surface explicitly.

---

## Summary

Event Resolution determines **what changed**, not **what should run**.

It produces a precise, auditable intent delta suitable for downstream application.

---

**End of Document**
