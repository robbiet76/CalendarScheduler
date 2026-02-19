> **Status:** STABLE  
> **Change Policy:** Intentional, versioned revisions only  
> **Authority:** Behavioral Specification v2

# 📘 Google Calendar Scheduler — Behavioral Specification
## Master Table of Contents

> **Status:** Authoritative  
> **Change Policy:** Rare, intentional, versioned  
> **Purpose:** Defines *what* the system must do, not *how*

---

## 1. System Purpose & Design Principles  
📄 **File:** `spec/01-system-purpose.md`

Defines:
- Overall mission of the scheduler
- Non-goals (explicit exclusions)
- Design constraints
- Guiding architectural principles

---

## 2. High-Level Architecture Overview  
📄 **File:** `spec/02-architecture-overview.md`

Defines:
- Major components and their responsibilities
- Directional data flow (calendar → manifest → FPP)
- Separation of inbound, outbound, and core logic
- Prohibited coupling patterns

---

## 3. Manifest (Authoritative State Model)  
📄 **File:** `spec/03-manifest.md`

Defines:
- The Manifest as the single source of truth
- What the Manifest represents
- What it explicitly does *not* represent
- How all other components orbit the Manifest

---

## 4. Manifest Identity Model  
📄 **File:** `spec/04-manifest-identity.md`

Defines:
- Semantic identity vs provider identity
- UID usage and abstraction
- Hashing rules
- Multi-event → single-scheduler identity mapping
- Identity stability guarantees

---

## 5. Calendar Ingestion Layer  
📄 **File:** `spec/05-calendar-io.md`
📄 **File:** `spec/05-calendar-io-Google.md`

Defines:
- Calendar-agnostic ingestion contract
- Provider-specific adapters (e.g., Google ICS)

---

## 6. Event Resolution & Normalization  
📄 **File:** `spec/06-event-resolution.md`

Defines:
- Translation from calendar events to scheduler intent
- Time semantics (symbolic vs concrete)
- Holiday resolution
- Unsupported patterns and failure modes

---

## 7. Events and Sub-Events (Atomic Scheduling Units)  
📄 **File:** `spec/07-events-and-subevents.md`

Defines:
- Why subevents exist
- When subevents are required
- Base vs exception entries
- Atomicity guarantees
- Internal ordering rules
- Prohibited subevent mutations

---

## 8. Scheduler Ordering Model  
📄 **File:** `spec/08-scheduler-ordering.md`

Defines:
- Global ordering rules
- Bundle-level ordering
- Dominance and overlap logic
- Why reordering is required
- Explicitly forbidden heuristics

---

## 9. Planner Responsibilities  
📄 **File:** `spec/09-planner-responsibilities.md`

Defines:
- What the Planner does
- What it must never do
- Inputs and outputs
- Determinism guarantees
- Preview vs apply behavior

---

## 10. Diff & Reconciliation Model  
📄 **File:** `spec/10-diff-and-reconciliation.md`

Defines:
- Desired vs existing comparison rules
- Why schedule.json is *not* authoritative
- Identity-based reconciliation
- Create / update / delete semantics

---

## 11. Apply Phase Rules  
📄 **File:** `spec/11-apply-phase-rules.md`

Defines:
- Apply invariants
- Write-only interaction with FPP
- Dry-run behavior
- Error handling guarantees
- Idempotency expectations

---

## 12. FPP Semantic Layer  
📄 **File:** `spec/12-fpp-semantics.md`

Defines:
- All FPP-specific behavior
- Translation to/from FPP scheduler schema
- Guard rules
- Future-proofing for FPP changes

---

## 13. Logging, Debugging & Diagnostics  
📄 **File:** `spec/13-logging-debugging.md`

Defines:
- Logging strategy
- Debug flag behavior
- Diagnostic output rules
- Required debug visibility points

---

## 14. UI & Controller Contract  
📄 **File:** `spec/14-ui-controller-contract.md`

Defines:
- UI responsibilities
- Controller → planner contract
- Preview/apply lifecycle
- Explicit non-responsibilities of UI

---

## 15. Error Handling & Invariants  
📄 **File:** `spec/15-error-handling-and-invariants.md`

Defines:
- Hard invariants
- Soft failures
- Fatal vs recoverable errors
- How invariant violations must surface

---

## 16. Non-Goals & Explicit Exclusions  
📄 **File:** `spec/16-non-goals-and-exclusions.md`

Defines:
- What this system intentionally does not support
- Anti-features
- Design decisions we will *not* revisit

---

## 17. Evolution & Extension Model  
📄 **File:** `spec/17-evolution-and-extension-model.md`

Defines:
- How new calendar providers are added
- How scheduler capabilities expand
- Rules for modifying existing behavior
- Versioning strategy

---

## 18. Round-Trip Contract (FPP ↔ Calendar)  
📄 **File:** `spec/18-round-trip-contract.md`

Defines:
- Bidirectional round-trip invariants
- Identity vs state hashing expectations for reversibility
- Execution ordering persistence requirements
- Overlap/shadow rules for execution-accurate calendar rendering
- Acceptance criteria for true two-way convergence

---

## 19. Regression Test Matrix  
📄 **File:** `spec/19-regression-test-matrix.md`

Defines:
- Repeatable scenario list for regression validation
- Fast daily regression subset
- Pre/apply/post assertion workflow via CLI runner
- Artifact capture expectations for debugging

---

## 20. Resolution Regression Suite  
📄 **File:** `spec/20-resolution-regression-suite.md`

Defines:
- Complete resolution-layer scenario matrix (segments, overrides, cancellations)
- Ordering and bundle invariants that must always hold
- Minimum per-patch and full pre-release regression gates
- Failure classification for fast triage

---

## 21. Production Readiness Checklist  
📄 **File:** `spec/21-production-readiness-checklist.md`

Defines:
- Locked, sequential production-readiness execution plan
- Phase gates and stop/go criteria
- Detour-and-resume workflow when fixes are discovered mid-phase
- Final production exit criteria

---

## 22. Release Runbook  
📄 **File:** `spec/22-release-runbook.md`

Defines:
- Operator-ready release procedure with preflight, deploy, post-verify, and rollback
- Required API/regression gates before go-live
- Artifact capture expectations for traceable releases
