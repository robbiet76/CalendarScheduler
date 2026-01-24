> **Status:** STABLE  
> **Change Policy:** Intentional, versioned revisions only  
> **Authority:** Behavioral Specification v2

# 02 — System Architecture Overview

This section describes the high-level architecture of the scheduler system, its major components, and how responsibilities are cleanly separated. This document is intentionally conceptual and technology-agnostic, focusing on behavior and data flow rather than implementation details.

---

## Design Goals

- **Single source of truth** via the Manifest
- **Strict separation of concerns** between planning, diffing, and applying
- **Provider-agnostic calendar ingestion** (no Google-specific logic outside adapters)
- **FPP-specific logic isolated** to a single semantic layer
- **Deterministic, testable behavior**
- **Strong observability** through structured logging and diagnostics
- **No backward-compatibility constraints** (unreleased plugin)

---

## Intent-First Architecture

**Principle:** The system models *human scheduling intent*, not external system state.

The Manifest is the single, authoritative representation of intent. All external systems
(calendars, FPP scheduler, configuration files) are treated strictly as sources of facts.
No source is compared directly to another source.

### Core Rule

> **Manifest equals intent.**  
> **Resolution compares intent to intent.**  
> **Sources never compare directly to each other.**

Any design that violates this rule is considered architecturally incorrect, even if it
appears to function.

### Architectural Implications

This principle enforces a strict, ordered flow:

1. **Source Acquisition**  
   External systems provide raw facts only (e.g., ICS data, schedule.json entries).

2. **Source Translation**  
   Provider-specific formats are normalized into provider-agnostic fact shapes.
   No intent is inferred at this stage.

3. **Intent Normalization**  
   Facts are interpreted into human scheduling intent:
   recurrence meaning, YAML semantics, symbolic values, identity, and subEvents.

4. **Manifest Canonicalization**  
   Intent is stored in a stable, human-readable, deterministic form.
   If two manifest entries differ, they represent different intent.

5. **Resolution**  
   Resolution compares normalized intent from different sources and produces
   CREATE / UPDATE / DELETE / CONFLICT / NOOP decisions.
   Resolution never compensates for upstream errors.

### Hard Design Rules

- If resolution must guess, the design is wrong.
- If inputs have not passed through the same intent normalization, they must not be compared.
- External systems never define intent — humans do.
- Fix incorrect source interpretation; never mask it in resolution.
- Adding new calendar providers must not require changes to Manifest or Resolution semantics.


### Rationale

This intent-first approach ensures:
- Deterministic behavior
- Auditable decisions
- Clean separation of responsibilities
- Safe evolution toward additional calendar providers or schedulers

### Canonical Intent Schema

The system defines a single, canonical **Intent schema** representing human scheduling intent.
All sources (calendar providers, FPP scheduler state, configuration files) MUST be normalized
into this schema before comparison or resolution.

Intent is immutable, declarative, and scheduler-agnostic.

**Core Rules**

- Manifest equals intent.
- Resolution compares intent to intent only.
- Sources never compare directly to each other.
- If intent differs, resolution reports change.
- If intent matches, resolution MUST NOOP.

**Intent Object Shape**

An Intent Event is composed of:

- `identity` — what the event is (type, target, timing)
- `subEvents` — normalized executable units (usually one)
- `ownership` — mutation safety and locking
- `correlation` — traceability back to source systems

All identity hashes are derived exclusively from the normalized `identity` object.
Ownership and correlation MUST NOT influence identity.

All resolution logic operates exclusively on normalized Intent objects.
If inputs have not passed through the same intent normalization pipeline,
they MUST NOT be compared.

(See **06 — Event Resolution** for the full Intent schema contract.)

## High-Level Data Flow

```
Calendar Provider (ICS)
        ↓
Provider Adapter (ICS → Canonical Events)
        ↓
Planner (Events → Bundles → Desired Entries)
        ↓
Manifest (Identity, Intent, Provenance)
        ↓
Comparator / Diff Engine
        ↓
Apply Engine
        ↓
FPP Scheduler (schedule.json)
```

Each stage consumes the output of the previous stage and produces a strictly defined artifact.

---

## Core Architectural Layers

### 1. Provider Layer (Inbound)

**Responsibility**
- Fetch calendar data
- Parse provider-specific formats (ICS quirks, timezone rules)
- Emit canonical calendar events

**Key Rules**
- No FPP knowledge
- No scheduler logic
- No identity decisions

**Examples**
- Google Calendar ICS adapter
- Future: generic CalDAV, Outlook ICS

---

### 2. Planning Layer

**Responsibility**
- Convert canonical calendar events into scheduler intent
- Resolve unsupported constructs (exceptions, symbolic dates)
- Emit Bundles as the atomic planning unit

**Outputs**
- Ordered list of Bundles
- Each Bundle contains one or more Intent entries

**Key Rules**
- No reading or writing of schedule.json
- No diffing logic
- Deterministic output

---

### 3. Manifest Layer (Center of Truth)

**Responsibility**
- Define identity and ownership of scheduler entries
- Track intent, provenance, and status
- Enable comparison, adoption, and revert

**Key Rules**
- Manifest identity is immutable once created
- Manifest governs ownership, not schedule.json
- All scheduler reconciliation flows through Manifest

(See **03 — Manifest**)

---

### 4. Comparator / Diff Layer

**Responsibility**
- Compare Desired Entries (from Planner) with Existing Entries (from FPP)
- Classify changes into creates / updates / deletes
- Respect ownership and locking rules

**Key Rules**
- Never mutate inputs
- No scheduling semantics
- No calendar awareness

---

### 5. Apply Layer (Outbound)

**Responsibility**
- Translate desired scheduler entries into FPP-compatible format
- Write changes to schedule.json
- Support dry-run and apply modes

**Key Rules**
- schedule.json is write-only from this layer
- No planning or diffing logic
- Uses FPP Semantic Layer exclusively

---

### 6. FPP Semantic Layer

**Responsibility**
- Encapsulate all Falcon Player–specific behavior
- Translate abstract intent into valid FPP scheduler entries
- Handle symbolic time/date resolution if required

**Key Rules**
- No calendar logic
- No manifest logic
- Single choke-point for FPP changes

---

## Bundles as a First-Class Concept

- All planner output is expressed as Bundles
- Even single-entry events use a Bundle
- Bundles are atomic and ordered as a unit
- Bundles enable:
  - Date exceptions
  - Unsupported day masks
  - Future overrides and layering

(See **04 — Bundles & Ordering**)

---

## Observability & Diagnostics

- Global debug flags enabled at bootstrap
- Each layer logs independently
- Logs are structured and component-scoped
- Debug output never mutates runtime behavior

---


## Canonical Component Grouping

The implementation is organized into a small number of **coarse-grained architectural domains**.
This structure is **normative**, not incidental.

Each directory represents a *responsibility boundary* and enforces allowed dependency directions.

```
src/
├── Core/          # Pure, deterministic logic (no I/O, no platform knowledge)
├── Inbound/       # External systems → Manifest (calendar ingestion)
├── Manifest/      # Authoritative state, identity, ownership
├── Planner/       # Intent → ordered desired state
├── Diff/          # Desired vs existing reconciliation
├── Outbound/      # Manifest → external systems (write-only)
├── Platform/      # FPP-specific semantics and schema translation
├── Bootstrap/     # Runtime config, logging, debug flags
└── UI/            # Presentation and controllers
```

### Dependency Rules

The following dependency rules are mandatory:

- **Core**
  - Has no dependencies on any other directory
  - Contains only pure functions and shared domain helpers

- **Inbound**
  - May depend on Core
  - Must not depend on Manifest, Planner, Diff, or Platform

- **Manifest**
  - May depend on Core
  - Must not depend on Planner, Diff, Outbound, or Platform

- **Planner**
  - May depend on Core and Manifest
  - Must not perform I/O or platform-specific logic

- **Diff**
  - May depend on Manifest
  - Must not infer intent or modify data

- **Outbound**
  - May depend on Manifest and Platform
  - Is strictly write-only with respect to external systems

- **Platform**
  - Encapsulates all FPP-specific behavior
  - Must not leak platform concepts into Core, Planner, or Manifest

- **UI**
  - May call Planner, Diff, and Apply endpoints
  - Must not implement scheduling logic

Violations of these boundaries are architectural defects.

---

## Architectural Non-Goals

- Backward compatibility
- In-place mutation of legacy scheduler data
- Provider-specific logic leaking into core layers
- UI-driven scheduling behavior

---

## Summary

This architecture favors clarity, determinism, and long-term maintainability. Each layer has a single responsibility, and the Manifest provides a stable contract between planning and execution. The system is designed to evolve—new providers, new schedulers, new semantics—without destabilizing existing behavior.
