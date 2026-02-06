Resolution Layer Design

## Purpose

The **Resolution** layer converts normalized calendar intents into the **minimal, fully FPP‑executable schedule geometry**.

It is a bidirectional compiler phase that:
- Resolves recurrence rules, cancellations, and overrides
- Produces bundles that FPP can evaluate using its top‑down scheduler
- Preserves enough structure and metadata to allow **lossless round‑trip sync** back to calendar providers

Resolution is **provider‑agnostic**. All provider‑specific semantics must be handled before this layer.

---

## Core Principles

1. **Resolution outputs execution geometry, not calendar semantics**
2. **No cancelled occurrence may execute**
3. **Overrides replace behavior, never coverage**
4. **Segmentation only occurs when required**
5. **Output must be minimal, readable, deterministic, and reversible**
6. **FPP precedence rules define ordering and structure**

If minimality and reversibility ever conflict, **reversibility wins**.

---

## Fundamental Concepts

### Bundle

A **Bundle** is the atomic scheduling unit sent to FPP.

- Bundles are evaluated **top‑down**
- The **first matching entry executes**
- Bundles are ordered relative to each other
- A Bundle represents **one contiguous execution segment**

A single calendar event may resolve into **multiple Bundles** if cancellations introduce gaps.

---

### Segment

A **Segment** is a continuous range of dates where execution coverage exists and is uniform.

Segments are created when:
- Recurrence begins
- Recurrence ends
- One or more occurrences are cancelled

Segments **cannot contain gaps**.

Each Segment maps to **exactly one Bundle**.

---

### Base Subevent

The **Base** subevent:
- Represents the default behavior for the entire Segment
- Covers the full segment range
- Is always placed **at the bottom** of its Bundle

---

### Override Subevent

An **Override** subevent:
- Represents a behavior change (time, payload, enabled, stopType, etc.)
- Applies to a subset of dates/times within a Segment
- Always sits **above** the Base subevent
- Never removes execution coverage

Overrides may be:
- Single‑date
- Date‑range
- Time‑range

Overrides **must not cross segment boundaries**.

---

### Cancellation (Critical Rule)

Cancelled occurrences **cannot** be represented as overrides.

Why:
- Disabled FPP entries are ignored
- Execution would fall through to the base
- The cancelled occurrence would still execute

Therefore:

> **Cancellations MUST cause segmentation of the base event**

This rule is non‑negotiable.

---

## Resolution Pipeline (Authoritative)

### Step 1 — Conceptual Recurrence Expansion

Interpret recurrence rules into a **coverage model**, not individual instances.

Work in terms of:
- execution start
- execution end
- repetition pattern

No instances are emitted.

---

### Step 2 — Subtract Cancelled Occurrences

Cancelled instances carve holes in coverage.

These holes split coverage into **contiguous segments**.

Example:

Daily Feb 1–28  
Cancelled: Feb 10, Feb 15  

Produces segments:
- Feb 1–9
- Feb 11–14
- Feb 16–28

---

### Step 3 — Apply Overrides Within Segments

Overrides are applied **inside segments only**.

Rules:
- Overrides never cross segment boundaries
- Overrides never remove coverage
- Overrides replace behavior only

If overrides cannot be represented without removing coverage, segmentation is required.

---

### Step 4 — Emit Minimal Bundles

For each Segment:
- No overrides → single Base subevent
- With overrides → ordered Bundle:

```
Override(s)
Base
```

Each Segment produces **exactly one Bundle**.

---

## Ordering and Precedence

### Within a Bundle
- Overrides appear above Base
- More specific overrides appear above broader ones

### Across Bundles
- Bundles are ordered chronologically
- Bundles are atomic relative to each other

---

## What Resolution Explicitly Does NOT Do

Resolution does **not**:
- Emit `status = cancelled`
- Emit individual instances unless forced
- Perform holiday symbolic resolution
- Apply heuristics or policy decisions
- Depend on calendar provider semantics

---

## Test Case Mapping

### Playlist_Overrides_1
Daily Feb 1–28, deleted Feb 10 & 15

Produces three Bundles:
- Feb 1–9
- Feb 11–14
- Feb 16–28

---

### Playlist_Overrides_2
Weekly Mon–Fri Mar–Apr with deletions

Produces weekday‑aligned segments with gaps removed.

---

### Playlist_Overrides_3
Daily May with time & payload overrides

Produces:
- Large base segments
- Localized override subevents
- Segmentation only where cancellations exist

---

## Invariants

1. No cancelled occurrence can execute
2. Overrides never remove coverage
3. Segmentation occurs only when required
4. Bundles are minimal and deterministic
5. Output is fully FPP‑representable
6. Output is fully reversible

---

## Bidirectional Ownership & Round‑Trip Safety

Resolution is a **bidirectional compiler phase**, not a lossy transform.

It must guarantee:

```
Calendar Event
   ⇄ Resolution Bundles (Base + Overrides)
   ⇄ FPP Entries
```

### Ownership Invariants
- Every resolved subevent belongs to **exactly one** calendar event
- All subevents remain traceable to the originating event
- Sync‑back must never:
  - create multiple calendar events
  - lose association with the original event
  - explode into per‑instance calendar entries

---

### Required Non‑Executable Metadata

Each resolved subevent MUST carry metadata **ignored by FPP execution**:

- `source_event_uid` — canonical calendar UID
- `parent_uid` — stable identifier for the resolved bundle
- `resolution_role` — `base` or `override`
- `resolution_scope` — date or date‑range covered

This metadata is required for:
- diffing
- sync‑back
- safe reconstitution of calendar exceptions

---

## FPP → Calendar Sync Contract

When FPP‑side changes are detected:

1. Planner groups subevents by `parent_uid`
2. Resolution is reconstituted into:
   - base recurrence
   - explicit exception dates
   - explicit override instances
3. Calendar updates are applied only as:
   - modified recurrence rules
   - added/removed exception dates
   - modified single‑instance overrides

Under no circumstances may sync‑back emit multiple calendar events for what originated as one.

---

## Design Consequence

Resolution correctness depends on **segmentation first**.

Overrides, compression, and round‑trip safety all rely on correct segment boundaries.

Get segmentation right, and everything else composes cleanly.

---