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

Calendar Provider Snapshot (raw)  
FPP Schedule Snapshot (raw)  
↓  
Intent Normalization (Resolution-owned)  
↓  
Event Resolution  
↓  
Diff Intent  
↓  
Apply

---

## Intent-First Enforcement (Architectural Requirement)

Event Resolution operates exclusively on **intent-normalized events**.

Resolution MUST NOT compare:
- Raw calendar provider records
- Provider-specific formats (e.g., ICS fields)
- Raw FPP scheduler entries
- Partially-normalized or mixed-shape data
- Manifest-shaped inputs directly

All inputs MUST be normalized into **Canonical Intent Events** by Intent Normalization before any comparison occurs.

During migration and refactoring phases, Resolution MAY accept partially-normalized inputs **only** in diagnostic or read-only modes. In such cases, Resolution MUST surface ambiguity explicitly and MUST NOT silently compensate or infer intent.

---

## Canonical Intent Contract

Event Resolution consumes ONLY **Canonical Intent Events**.

Intent is the single human-readable representation of scheduling meaning.

Manifest entries are serialized intent, not source facts.

---

## Inputs

Event Resolution consumes:

- **Source inputs:** calendar provider snapshot records (provider-agnostic, raw)  
- **Existing inputs:** plugin manifest events OR normalized scheduler snapshot events

Resolution is responsible for producing canonical Intent Events before comparison.

Resolution does **not** assume manifest-shaped inputs from CalendarSnapshot or any upstream identity construction.

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

## Normalization Responsibility

Resolution is the final authority for all normalization required for comparison, including:

- Recurrence expansion
- YAML semantic application
- Timing normalization
- Identity construction
- SubEvent construction

Upstream components (CalendarSnapshot, FPP ingestion, parsers):
- MAY extract raw data and provenance
- MUST NOT construct identity
- MUST NOT construct subEvents
- MUST NOT emit manifest-shaped intent

If normalization has not occurred, Resolution MUST NOT proceed.

### Intent Normalization Boundary

- CalendarSnapshot and FPP ingestion MUST emit raw facts only.
- Identity construction, timing resolution, recurrence interpretation, and YAML semantic application occur exclusively in Intent Normalization.
- Resolution compares only fully-normalized Intent Events.

### Invalid Comparison Rule (Strict Mode)

If two inputs have not passed through the same normalization pipeline,  
Resolution MUST reject comparison.

Resolution MAY support a diagnostic or inspection mode in which mismatched normalization is reported but comparison does not proceed to mutation or diff generation.

Resolution MUST NOT:
- Guess intent
- Infer equivalence
- Apply heuristics
- Compensate for upstream format differences

Ambiguity MUST surface explicitly and block resolution.

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

### Canonical Intent Event
A Canonical Intent Event is a canonical post-normalization structure produced inside Resolution for comparison.

Fields:
- `identity_hash` (string)
- `identity` (array)
- `ownership` (array)
- `correlation` (array)
- `subEvents` (array)

Factories:
- `CanonicalIntentEvent::fromManifestEvent(array $event): CanonicalIntentEvent`

Rules:
- MUST represent final intent, never raw or partially-resolved data
- MUST require `identity_hash` (via `id` or `identity_hash`) and `identity`
- MUST NOT compute identity hashes outside Resolution
- MUST treat input as already canonicalized by Intent Normalization

### ResolutionInputs
ResolutionInputs factories accept raw calendar provider records and existing manifest events, internally performing normalization and identity construction to produce:

- `sourceByHash: array<string, CanonicalIntentEvent>`
- `existingByHash: array<string, CanonicalIntentEvent>`

Upstream components MUST NOT perform identity construction or subEvent normalization.

### Resolver
Resolver consumes ResolutionInputs and produces ResolutionResult.
Resolver MUST be pure and deterministic.

## Resolution Rules

Resolution is performed per `identity_hash` over the union of:
- source (calendar snapshot) hashes
- existing (scheduler manifest) hashes

For each identity:
1. Present in calendar only → propose CREATE intent
2. Present in FPP only → propose DELETE intent (policy-gated)
3. Present in both:
   - If structural differences exist → propose UPDATE intent
   - Otherwise → NOOP

Policies may restrict which operations are emitted.

Resolution outputs intent deltas, not scheduler actions.

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
- Repair or compensate for improperly normalized inputs

---

## Relationship to Identity

Identity derivation occurs **inside** Event Resolution normalization.

Event Resolution treats identities as immutable once constructed.

---

## Relationship to Scheduler Semantics

Event Resolution is scheduler-agnostic and does not reason about runtime execution order or overlap.

---

## Design Rule (Hard)

> **If resolution must guess, the design is wrong.**  
> **If normalization is incomplete, resolution must stop.**  
> **If two inputs are not shape-identical, they MUST NOT be compared.**

Ambiguity must surface explicitly.

---

## Architectural Invariant

If two events cannot be expressed in the same Canonical Intent shape, they MUST NOT be compared.

---

## Summary

Event Resolution determines **what changed**, not **what should run**.

It produces a precise, auditable intent delta suitable for downstream application.

---

**End of Document**
