**Status:** STABLE  
> **Change Policy:** Intentional, versioned revisions only  
> **Authority:** Behavioral Specification v2

# 05 — Calendar I/O Layer

## Purpose

The **Calendar I/O Layer** defines the system boundary between the scheduler domain and external calendar systems (e.g. Google Calendar via ICS).

This layer is responsible for **all communication with calendar providers**, regardless of direction or transport mechanism.

It is explicitly *non-semantic* and *non-planning*.

> The Calendar I/O Layer moves data **into** and **out of** the system. It does not interpret intent, enforce identity, or apply scheduling rules.

---

## Directional Model: Calendar I/O

Rather than treating ingestion and export as separate concerns, the system models calendar interaction as **bidirectional I/O**.

```
Calendar Provider
        ↑   ↓
   Calendar I/O Layer
        ↓   ↑
      Manifest
```

This model is intentionally asymmetric.  
Manifest → Calendar is a projection of execution geometry. Calendar → System produces provider‑neutral records; intent reconstruction and expansion occur downstream during Intent Normalization and Resolution.

This abstraction allows flexibility across:

- Pull vs push models
- File-based ICS vs API-based providers
- Batch vs streaming updates

---

## Responsibilities

The Calendar I/O Layer **must**:

- Communicate with calendar providers
- Parse provider data into provider-neutral records
- Serialize provider-neutral records back into provider formats
- Preserve symbolic and structural intent
- Remain provider-aware but domain-agnostic

The Calendar I/O Layer **must not**:

- Derive semantic identity
- Apply scheduling guard rules
- Resolve symbolic times or dates
- Decide ordering, bundling, or dominance
- Mutate Manifest state

---


## Inbound I/O (Calendar → System)

## Canonical Calendar Input Location (FPP)

All calendar-derived raw input MUST be written to a single, canonical location on FPP.

This file represents the authoritative, source-shaped calendar input used for:
- intent normalization
- replay and debugging
- hash comparison
- reconciliation

### Path

/home/fpp/media/config/google-calendar/calendar-raw.json

### Rules

- This file contains raw calendar events only (no normalization).
- IntentNormalizer MUST read calendar input exclusively from this file.
- No production logic may depend on calendar fixtures stored under the plugin directory.
- The file MUST be preserved across plugin upgrades.
- Calendar I/O implementations MUST treat this file as the boundary artifact between ingestion and intent normalization.

### Purpose

Inbound Calendar I/O is responsible for *receiving* calendar data and translating it into a **provider-neutral event representation** suitable for resolution.

### Canonical CalendarEvent Shape

The inbound Calendar I/O layer expects CalendarTranslator implementations to emit a canonical, provider-neutral `CalendarEvent` object with the following structure:

```json
{
  "source": {
    "provider": "google",
    "calendar_id": "primary"
  },
  "summary": "Meeting with Team",
  "description": "Raw description text including embedded INI-style metadata",
  "dtstart": "2024-06-01T09:00:00-07:00",
  "dtend": "2024-06-01T10:00:00-07:00",
  "rrule": {
    "freq": "WEEKLY",
    "byday": ["MO", "WE", "FR"]
  },
  "provenance": {
    "uid": "event-uid-1234",
    "imported_at": "2024-06-01T12:00:00Z"
  }
}
```

Where:

- `source`: identifies the calendar provider and optionally the calendar ID within that provider.
- `summary`: the event title or summary.
- `description`: raw event description text, which may include embedded INI-style metadata (opaque at this stage).
- `dtstart` and `dtend`: ISO 8601 timestamps with timezone information, representing the event start and end.
- `rrule`: an unexpanded recurrence rule object representing recurrence patterns.
- `provenance`: metadata including the original event UID and the import timestamp.

### Inbound Contract

```ts
ingestCalendar(source: CalendarSource): CalendarEvent[]
```

Where:

- `CalendarEvent` conforms to the canonical shape defined above.  
  This representation is intentionally unstructured and does not correspond directly to Manifest Events or SubEvents.  
  A single inbound CalendarEvent may later expand into multiple Manifest SubEvents during resolution.
- All recurrence, symbols, and exceptions are preserved

### CalendarTranslator Rules

- CalendarTranslator **MUST** emit the canonical `CalendarEvent` shape as specified.
- CalendarTranslator **MUST NOT** emit Manifest-like timing, identity, type, or SubEvent structures.
- No hard or symbolic resolution (e.g., of symbolic times, holidays) occurs in Calendar I/O; these remain unresolved.

### Description Metadata Note

INI-style metadata extracted from the `description` field is treated as opaque metadata at this stage and **MUST NOT** influence timing, type, or any scheduling semantics during Calendar I/O processing.

---

## Calendar Description Metadata (INI)

Calendar event descriptions may contain an embedded INI-style metadata block that expresses
**user-authored execution intent** for downstream scheduling systems.

This metadata represents an explicit, editable control surface for users managing
their schedules directly from the calendar.

### Scope and Authority

- The metadata is **authoritative user input**
- It is owned and edited by the user
- The system MUST preserve it losslessly
- The Calendar I/O layer MUST NOT interpret its semantics

The Calendar I/O layer treats description metadata as **opaque content**.
All semantic interpretation occurs downstream during Intent normalization.

### Purpose

Calendar Description Metadata exists to express **execution behavior** that cannot
be reliably represented in calendar UI constructs alone.

Examples include:
- Execution type (playlist, sequence, command)
- Stop behavior
- Repeat behavior
- Enabled / disabled state
- Symbolic execution time (e.g. Dawn, Dusk)

### Allowed Content (Non-Exhaustive)

The following categories of fields MAY appear in description metadata:

- Execution identity (e.g. `type`)
- Execution control (e.g. `enabled`, `stopType`, `repeat`)
- Symbolic timing overrides (e.g. `symbolicTime`)
- Command execution payloads (for command-type events)

### Forbidden Content

Description metadata MUST NOT contain:

- Hard dates
- Hard times
- Timezones
- Day-of-week masks
- Scheduler identifiers
- Manifest identity hashes
- Provider-specific runtime fields

Calendar timing is defined exclusively by the calendar event itself.
Embedding timing data in the metadata creates ambiguity and drift and is forbidden.

### Processing Rules

- Calendar I/O MUST preserve description metadata exactly as received
- Calendar I/O MUST NOT validate or normalize metadata content
- Missing or malformed metadata MUST NOT cause Calendar I/O failure
- Metadata interpretation is deferred entirely to Intent normalization

---

### Inbound Rules

- No semantic interpretation is allowed
- No identity is computed
- No intent normalization is performed
- Provider quirks are isolated here
- No Manifest Events or SubEvents are created in this layer
- No identity derivation or normalization is permitted
- Calendar I/O MUST NOT derive or infer an intent end date; RRULE interpretation and end-date materialization occur only during intent normalization.
- Provider-neutral records must remain structurally ungrouped
- No identity inference, matching, or reconstruction is permitted

---

### VEVENT Recurrence Boundary Semantics (RRULE UNTIL)

The Calendar I/O layer preserves recurrence rules exactly as received and does not interpret them.
However, downstream intent normalization relies on the following **authoritative recurrence semantics**:

- `DTSTART` is the anchor for all recurrence interpretation.
- For **timed events** (non–all-day):
  - A timed `UNTIL` value represents an **exclusive upper bound**.
  - The final intent `end_date.hard` is the **local calendar date immediately preceding** the `UNTIL` boundary when converted into the `DTSTART` timezone.
- For **all-day events** (`VALUE=DATE`), `UNTIL` semantics are inclusive.
- `BYDAY` masks restrict occurrence generation but **MUST NOT** influence derivation of the intent end date.

These rules ensure provider-independent, timezone-correct end-date materialization.

---

### CalendarTranslator Role

The CalendarTranslator is a provider-specific adapter responsible for:

- Normalizing raw provider data into the canonical, provider-neutral `CalendarEvent` shape
- Maintaining a lossless representation of the original calendar data
- Operating strictly pre-resolution and without semantic interpretation
- Being stateless and side-effect free

---

## Outbound I/O (System → Calendar)

### Purpose

Outbound Calendar I/O is responsible for *emitting* calendar-compatible representations from **Manifest intent**.

It is the inverse operation of inbound ingestion.

### Outbound Contract

```ts
exportCalendar(
  events: ManifestEvent[],
  target: CalendarTarget
): CalendarArtifact
```

Export is driven by Manifest base SubEvent execution geometry.  
ManifestEvent containers are treated as envelopes and do not influence export semantics.

Where: Export is driven exclusively by Manifest `SubEvent` execution and timing fields; identity and ownership metadata are ignored.

### Outbound Rules

Outbound Calendar I/O **must**:

- Preserve symbolic intent where supported
- Encode recurrence faithfully
- Produce provider-valid artifacts

Outbound Calendar I/O **must not**:

- Resolve symbolic times or holidays
- Apply guard dates
- Modify identity or ownership
- Merge or split entries

### All-Day and Boundary Times

The Calendar I/O layer must preserve scheduler-domain boundary times exactly as expressed in the Manifest.

In particular, `24:00:00` is a valid end-time representation used to model all-day execution semantics and must not be normalized, rounded, or coerced within this layer.

Any provider-specific constraints or transformations (e.g. converting `24:00:00` to a provider-safe representation) must occur strictly within provider adapters and never alter Manifest or Resolution semantics.

---

### Export Granularity and Adoption Semantics

During **initial adoption and export**, the system operates in a strictly
**one-to-one mode**:

- Each FPP scheduler entry produces **exactly one calendar event**
- No grouping, consolidation, or inference is performed
- No Manifest SubEvent expansion occurs at export time

Only a single base SubEvent exists during adoption and export.

During this phase, each ManifestEvent contains exactly one base SubEvent.

In this phase, the calendar is treated as a **mirror of scheduler entries**,
not as a source of higher-level intent.

### SubEvent Emergence

The concept of **SubEvents does not exist during adoption or export**.

SubEvents are introduced **only after calendar import**, during resolution of:

- Recurrence rules
- Overrides and exceptions
- Provider-specific timing constraints

Only once calendar data is imported back into the system can a single
calendar event expand into multiple Manifest SubEvents.

This asymmetry is intentional:

- Scheduler → Calendar is execution-preserving
- Calendar → Manifest is intent-reconstructing

Calendar I/O must never infer, expand, or synthesize additional SubEvents during export.

---

## Symbolic Preservation

- Symbolic times (e.g. Dawn, Dusk) must remain symbolic if supported
- Symbolic dates (e.g. holidays) must remain symbolic if supported
- `hard` MAY be non-null only when the value is explicitly concrete and year-specific at the source (e.g. ISO date from provider data or an explicit date in Manifest)
- `hard` MUST be null when the source value is symbolic, recurring, or year-agnostic (e.g. holidays like Thanksgiving, Christmas; seasonal markers)
- Symbolic values MUST be represented exclusively in the `symbolic` field and preserved without forward resolution
- Year selection and concrete date materialization are deferred to Resolution, never Calendar I/O or Intent normalization
- Unsupported symbolic constructs cause explicit export failure
- Symbolic dates must not be encoded into description YAML or other editable free‑text metadata as a substitute for provider‑supported structures, as calendar edits may invalidate symbolic meaning.

Silent degradation is forbidden.

---

## Provider Abstraction

Calendar providers are implemented as adapters behind a common interface.

```ts
interface CalendarProvider {
  ingest(source): CalendarEvent[]
  export(entries): CalendarArtifact
}
```

Examples:

- Google Calendar (ICS)
- Generic ICS feed
- Future API-based providers

---

## Failure Semantics

Calendar I/O failures are **hard failures**.

- Invalid provider data
- Unsupported recurrence patterns
- Unsupported symbolic constructs

Malformed or missing description YAML is explicitly non‑fatal; failures apply only to unsupported provider constructs or symbolic semantics.

Failures surface immediately and do not partially apply.

---

## Non-Goals

- Semantic interpretation
- Scheduler-specific logic
- Inferring concrete end dates from RRULE `UNTIL` values
- Identity reconciliation
- Backwards compatibility with malformed calendars

---

## Guarantees

- Calendar I/O is reversible at the provider-neutral record level where supported
- Manifest intent is never silently altered
- Derived intent end dates are timezone-correct, recurrence-boundary faithful, and independent of provider UI rendering quirks.
- Provider-specific behavior is fully isolated

---

## Relationship to Other Layers

- **Manifest**: Calendar I/O produces provider‑neutral records for storage and export. Persistence is handled by Manifest storage components; Calendar I/O does not modify existing Manifest state or apply semantic transformations.
- **Resolution Layer**: Receives provider-neutral events. Recurrence expansion, BYDAY filtering, and exception handling occur only after intent end-date normalization.
- **Planner / Diff / Apply**: Never interact directly with calendar providers

---

> Calendar I/O is the only layer that knows calendars exist.

---

## Appendix A — Calendar Description Metadata (INI) Templates

This appendix provides **recommended, user-editable INI-style templates** that may be embedded
inside a calendar event’s description field.

These templates are **documentation only**.  
They define *what users are allowed and encouraged to write*, not what the Calendar I/O
layer interprets.

The Calendar I/O layer MUST treat all metadata content as opaque text.

---

### General Rules

- The metadata expresses **execution behavior**, not calendar timing
- Users are encouraged to edit the metadata directly in the calendar UI
- All fields are optional unless otherwise stated
- Omitted fields imply downstream defaults
- The metadata MUST remain valid after calendar edits

---

### Common Execution Fields

[settings]
type = playlist    ; or sequence, or command
enabled = true     ; or false
stopType = graceful    ; or hard, graceful_loop
repeat = none      ; or immediate, or an integer

Notes:
- These values describe **how FPP executes**, not *when*
- Actual defaults are defined by FPP semantics, not by Calendar I/O
- Human-readable values are preferred over numeric enums

---

### Symbolic Time Override

Symbolic time may be used when calendar UI cannot express the desired behavior.

[symbolic_time]
start = dawn       ; or dusk, sunrise, sunset
start_offset = -60

Rules:
- Symbolic time applies only to execution start
- Hard times and symbolic times MUST NOT be mixed
- If [symbolic_time] is present, calendar start time is treated as a placeholder

---

### Playlist / Sequence Example

[settings]
type = sequence
enabled = true
stopType = hard
repeat = immediate

---

### Command Example (with Payload)

[settings]
type = command
enabled = true
repeat = none

[command]
args[] = 80
multisync = true
hosts = 192.168.10.100

---

### Symbolic Time Example

[settings]
stopType = graceful
repeat = immediate

[symbolic_time]
start = dusk
start_offset = -60

---

### Forbidden Metadata Content

Description metadata MUST NOT include:

- Dates or date ranges
- Times or timezones
- Day-of-week masks
- Scheduler IDs or hashes
- Provider-specific metadata
- Manifest identity fields

Calendar timing is defined exclusively by the calendar event itself.
Embedding timing data in the metadata creates ambiguity and drift and is forbidden.

---

### Design Intent

Calendar Description Metadata (INI) is a **first-class user interface**, not an internal escape hatch.

Users are expected to:
- Read it
- Edit it
- Rely on it

If a value cannot be safely edited by a user, it does not belong in the metadata.
