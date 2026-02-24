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

Event Resolution operates exclusively on intent-normalized events.
Resolution does not perform semantic normalization, identity construction, or timing interpretation.
All such work MUST have occurred prior to Resolution.
Identity hashes are treated as immutable and authoritative within this layer.

> **Identity is defined strictly as the combination of event type, target, and days. Identity does not include specific dates or times.**

During migration and refactoring phases, Resolution MAY accept partially-normalized inputs **only** in diagnostic or read-only modes. In such cases, Resolution MUST surface ambiguity explicitly and MUST NOT silently compensate or infer intent.

---

## Canonical Intent Contract

### State Hash (SubEvent-level)

Each subEvent MUST include a `stateHash` representing the fully-normalized execution state of that subEvent.

The `stateHash`:
- Is computed during Intent Normalization
- Reflects all execution-relevant timing, behavior, and payload state
- Is source-agnostic (calendar and FPP inputs MUST converge to the same hash for equivalent intent)
- Is immutable within Event Resolution

**Event Resolution MUST use `stateHash` as the sole and exclusive mechanism for detecting updates.**

Resolution MUST NOT perform deep field-by-field or structural comparisons when `stateHash` is present.

Event Resolution consumes ONLY **Canonical Intent Events**.

Intent is the single human-readable representation of scheduling meaning.

Manifest entries represent serialized Canonical Intent, not raw source facts. Resolution assumes manifest inputs are already semantically normalized.

Manifest entries are serialized intent, not source facts.

### All-day intent handling (Locked)

All-day is **intent**, not a fabricated time window.

Normalization MUST represent all-day intent explicitly and MUST NOT invent times such as `23:59:59` or `24:00:00`.

Rules:
- Canonical Intent MUST include `is_all_day: bool`.
- If `is_all_day == true`:
  - `start_time` MUST be `null`
  - `end_time` MUST be `null`
  - Each `subEvent.timing.start_time` MUST be `null`
  - Each `subEvent.timing.end_time` MUST be `null`
- If `is_all_day == false`:
  - `start_time` and `end_time` MUST be non-null (hard or symbolic per rules below)
  - Each `subEvent.timing.start_time` and `subEvent.timing.end_time` MUST be non-null and **hard-resolved**

FPP-specific all-day materialization (e.g. `endTime = 24:00:00`) is an Apply/materialization concern and MUST NOT appear in Canonical Intent.

Identity + hashing:
- `is_all_day` participates in identity and hashing.
- Switching between timed and all-day intent is an identity change (different `identity_hash`).
- **Identity is always determined by type + target + days, never by specific dates or times.**

---

## Inputs

Event Resolution consumes only fully-normalized Canonical Intent Events.
Raw calendar provider records and raw FPP scheduler snapshots are NOT valid inputs for resolution.
If raw inputs are supplied, Resolution MUST reject comparison or operate in diagnostic-only mode.

### Required event shape

Each event MUST include:
- `id` (string, equals `identity_hash`)
- `identity` (object)
- `is_all_day` (bool)
- `subEvents` (array; may be empty but typically contains 1 base subEvent)
- `ownership` (object)
- `correlation` (object)

> **Identity is strictly type + target + days. Identity does not include dates or times.**

Each subEvent MUST include:
- `identity` (object)
- `identity_hash` (string; equals parent identity hash)
- `timing` (object)
- `is_all_day` (bool; MUST equal parent `is_all_day`)
- `behavior` (object)
- `payload` (object|null)

---

## Normalization Responsibility

Resolution enforces that structural normalization required for comparison has already occurred, including:

- Recurrence expansion
- SubEvent construction

Resolution MAY validate ordering, grouping, and subEvent structure, but MUST NOT derive or modify intent.

Resolution MUST NOT perform:
- Semantic interpretation
- Symbolic date resolution
- Timezone math
- DTSTART / RRULE / UNTIL evaluation
- Identity derivation or mutation

All cross-source timing semantics, recurrence interpretation, and identity construction MUST converge upstream during Intent Normalization.

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
- `is_all_day` (bool)
- `ownership` (array)
- `correlation` (array)
- `subEvents` (array)

> **Identity is always defined as type + target + days only.**

Each subEvent MUST include:
- `stateHash` (string)

Factories:
- `CanonicalIntentEvent::fromManifestEvent(array $event)` assumes the manifest entry already represents fully normalized Canonical Intent and performs no semantic interpretation or identity derivation.

Rules:
- MUST represent final intent, never raw or partially-resolved data
- MUST require `identity_hash` (via `id` or `identity_hash`) and `identity`
- MUST NOT compute or modify identity hashes within Resolution.
- MUST treat input as already canonicalized by Intent Normalization
- MUST encode all-day intent via `is_all_day` and null times (see **All-day intent handling (Locked)**)
- MUST NOT represent all-day by inventing platform-specific time sentinels such as `24:00:00`

### ResolutionInputs
ResolutionInputs factories accept only fully-normalized Canonical Intent Events.
They MUST NOT perform identity construction or semantic normalization.

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
   - If any subEvent.stateHash differs → propose UPDATE intent
   - Otherwise → NOOP

> **Identity matching is always based on type + target + days. Only stateHash is used to detect updates.**

Policies may restrict which operations are emitted.

Resolution outputs intent deltas, not scheduler actions.

---

## Divergence Detection

**Divergence detection is performed exclusively via `stateHash` comparison at the subEvent level.**

Two events are considered divergent if:
- Any corresponding subEvent has a differing `stateHash`
- The number of subEvents differs
- The `is_all_day` flag differs

If all subEvents match by `stateHash`, the events are considered equivalent and NOOP is produced.

Resolution does not perform semantic or heuristic comparisons and does not inspect individual timing or payload fields directly.

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

Identity derivation occurs during Intent Normalization. Event Resolution does not derive or modify identity.

Event Resolution treats identities as immutable once constructed.

> **Identity does not include any date or time fields.**

---

## Relationship to Scheduler Semantics

Event Resolution is scheduler-agnostic and does not reason about runtime execution order or overlap. All-day platform encodings (such as FPP's `24:00:00`) are explicitly out of scope for Resolution.

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

## Execution Geometry Compilation (Resolved Schedule)

Resolution also compiles normalized intent into minimal FPP-executable geometry
as ordered bundles and subevents. This geometry is provider-agnostic and is the
authoritative input to planning.

### Core Principles

1. Resolution outputs execution geometry, not provider semantics.
2. No cancelled occurrence may execute.
3. Overrides replace behavior, never coverage.
4. Segmentation occurs only when required.
5. Output must remain minimal, deterministic, and reversible.
6. FPP precedence rules define ordering within bundles.

If minimality and reversibility conflict, reversibility wins.

### Fundamental Concepts

#### Bundle

A bundle is an atomic, ordered scheduling unit evaluated top-down.

- First matching entry executes.
- A bundle represents one contiguous coverage segment.
- Base subevent is always last; overrides are above base.

#### Segment

A segment is a contiguous date coverage range with no gaps.

Segments are created when:
- Recurrence starts
- Recurrence ends
- Cancelled dates carve holes in coverage

Each segment maps to exactly one bundle.

#### Base vs Override Subevents

- Base subevent defines default behavior for the full segment and sits last.
- Override subevents define narrower behavior changes and sit above base.
- Overrides never remove coverage and must stay within segment boundaries.

### Cancellation Rule (Hard)

Cancelled occurrences MUST be represented by segmentation, not disabled overrides.

Reason:
- Disabled entries in FPP would fall through to base behavior.
- A cancelled occurrence could still execute if represented as an override.

Therefore, cancellations always split base coverage into gap-free segments.

### Authoritative Resolution Pipeline

1. Interpret recurrence into coverage model (range/scope based; no instance explosion).
2. Subtract cancelled dates to form contiguous segments.
3. Apply overrides within each segment.
4. Emit minimal bundles:
   - no overrides => base only
   - with overrides => overrides first, base last
5. Coalesce adjacent compatible bundles when signatures and boundaries allow safe merge.

### Ordering and Precedence

Within a bundle:
- Overrides outrank base.
- Narrower override scopes outrank broader override scopes.

Across bundles:
- Bundles are chronologically ordered.
- Bundle atomicity is preserved.

### Round-Trip and Traceability Contract

Resolved subevents MUST retain non-execution metadata required for deterministic
diffing and provider round-trip reconstruction. Current runtime fields include:

- `bundleUid`
- `parentUid`
- `sourceEventUid`
- `role` (`base` or `override`)
- `scope` (inclusive start, exclusive end)

This metadata is operational and must not alter execution semantics.

### Geometry Invariants

1. No cancelled occurrence executes.
2. Overrides never remove base coverage.
3. Segmentation only occurs when required by cancellations/coverage boundaries.
4. Bundles remain minimal and deterministic.
5. Output remains fully representable for FPP execution and calendar round-trip.

---

## Summary

Event Resolution determines **what changed** and compiles normalized intent into
deterministic execution geometry for planning.

It produces a precise, auditable intent delta and minimal, reversible bundle output.

---

**End of Document**
