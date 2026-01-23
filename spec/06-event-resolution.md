**Status:** STABLE  
> **Change Policy:** Intentional, versioned revisions only  
> **Authority:** Behavioral Specification v2

# 06 — Event Resolution

## Purpose

**Event Resolution** is the process by which **two sets of normalized events (calendar snapshot vs FPP schedule) are compared to determine intent-level differences**.

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

Event Resolution consumes **two sets of manifest-shaped events** (Option A):

- **Source events:** CalendarSnapshot manifest `events`
- **Existing events:** Scheduler manifest `events` (the plugin manifest store)

Resolution does **not** consume `schedule.json` directly.

### Required event shape

Each event MUST include:
- `id` (string, equals `identity_hash`)
- `identity` (object)
- `subEvents` (array; may be empty but typically contains 1 base subEvent)
- `ownership` (object)
- `correlation` (object)

Each subEvent MUST include:
- `identity` (object)
- `identity_hash` (string; equals parent identity hash)
- `timing` (object)
- `behavior` (object)
- `payload` (object|null)

---

## Outputs

Event Resolution produces a single **ResolutionResult** containing:
- Matched events (by identity_hash)
- Calendar-only events
- FPP-only events
- Proposed ResolutionOperations (read-only / dry-run by default)

Resolution produces *plans*, not mutations.

---

## Frozen Contracts (Implementation Targets)

### ResolvableEvent
A ResolvableEvent is a canonical wrapper over a manifest event used for comparison.

Fields:
- `identity_hash` (string)
- `identity` (array)
- `ownership` (array)
- `correlation` (array)
- `subEvents` (array)

Factories:
- `ResolvableEvent::fromManifestEvent(array $event): ResolvableEvent`

Rules:
- MUST require `identity_hash` (via `id` or `identity_hash`) and `identity`
- MUST NOT compute identity hashes
- MUST treat input as already canonicalized manifest output

### ResolutionInputs
ResolutionInputs is normalization glue only.

Factories:
- `ResolutionInputs::fromManifests(array $sourceManifest, array $existingManifest, ?ResolutionPolicy $policy=null, array $context=[]): ResolutionInputs`

Outputs:
- `sourceByHash: array<string, ResolvableEvent>`
- `existingByHash: array<string, ResolvableEvent>`

### Resolver
Resolver consumes ResolutionInputs and produces ResolutionResult.
Resolver MUST be pure and deterministic.

## Resolution Rules

Resolution is performed per `identity_hash` over the union of:
- source (calendar snapshot) hashes
- existing (scheduler manifest) hashes

For each identity:
1. Present in calendar only → propose UPSERT_TO_FPP
2. Present in FPP only → propose DELETE_FROM_FPP (policy-gated)
3. Present in both:
   - If structural differences exist → propose UPDATE_FPP
   - Otherwise → NOOP

Policies may restrict which operations are emitted.

---

## Divergence Detection

Two events are divergent if any of the following differ:
- Number of subEvents
- Timing of any subEvent
- Behavior of any subEvent
- Payload of any subEvent

Divergence detection is structural, not semantic.

Resolution does not attempt semantic equivalence or scheduler inference.

---

## Policy Control

All safety, ownership, and mutation rules are controlled exclusively by ResolutionPolicy.

Resolvers must not infer or override policy behavior.

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
