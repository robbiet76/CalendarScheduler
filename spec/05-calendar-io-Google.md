# 05 — Calendar I/O Layer (Google)

**Status:** DRAFT  
**Change Policy:** Intentional, versioned revisions only  
**Authority:** Behavioral Specification v2 (Provider Extension)

---

## Purpose

This document defines the **Google Calendar–specific implementation** of the
Calendar I/O Layer.

It extends (but does not modify) the core specification defined in:

**05 — Calendar I/O Layer**

All generic rules, constraints, and guarantees from the base spec apply unless
explicitly overridden here.

This document focuses exclusively on **transport, provider behavior, and API‑specific
capabilities** for Google Calendar.

---

## Provider Scope

This specification governs interaction with:

- Google Calendar via **OAuth‑authenticated REST API**
- (Legacy) Google Calendar via **ICS import/export**

Both transports are considered **equivalent providers** that must emit and
consume the same canonical `CalendarEvent` representation.

---

## Provider Identity

```json
{
  "provider": "google",
  "calendar_id": "primary"
}
```

- `calendar_id` MAY reference non‑primary calendars
- Calendar selection is a configuration concern, not a semantic one

---

## Transport Model

Google Calendar is implemented using **pluggable transports**:

```
GoogleCalendarProvider
├── IcsTransport
└── ApiTransport (OAuth)
```

Transport choice MUST NOT affect:
- Canonical event shape
- Intent normalization behavior
- Hashing
- Diff logic
- Authority decisions

Transport differences are isolated entirely within Calendar I/O.

---

## OAuth and Authentication

### OAuth Scope

Minimum required scope:

```
https://www.googleapis.com/auth/calendar
```

This scope is required to:
- Read calendar events
- Create, update, and delete events

No additional Google scopes are permitted.

---

### Token Storage

- OAuth tokens are stored **outside** the Manifest
- Tokens are treated as infrastructure state
- Tokens MUST NOT be:
  - Embedded in calendar descriptions
  - Stored in spec files
  - Included in debug logs

Token refresh and revocation are transport concerns only.

---

## Inbound I/O (Google → System)

### API Source Fields

Google Calendar API events provide:

- `id`
- `summary`
- `description`
- `start`
- `end`
- `recurrence`
- `updated`
- `status`
- `extendedProperties`

---

### Canonical Mapping

| Google API Field | CalendarEvent Field |
|-----------------|---------------------|
| `id` | `provenance.uid` |
| `summary` | `summary` |
| `description` | `description` |
| `start.dateTime` | `dtstart` |
| `end.dateTime` | `dtend` |
| `recurrence[]` | `rrule` |
| `updated` | `provenance.imported_at` |

Rules:
- Cancelled events are ignored
- No recurrence expansion is performed
- No symbolic resolution is performed

---

### Incremental Sync

- Google sync tokens MAY be used
- Sync tokens are managed outside Calendar I/O
- Calendar I/O remains stateless

---

## Managed vs Unmanaged Events

### Definition

An event is **managed** if it participates in reconciliation and Apply.

An event is **unmanaged** if it is ignored by Diff and Apply.

---

### Managed Markers

Google Calendar supports **two complementary mechanisms**:

#### 1. Description Marker (User‑Visible)

Example (INI):

```ini
[gcs]
managed = true
```

- User‑editable
- Survives ICS and UI edits
- Removing this marker opts the event out

---

#### 2. Extended Properties (Machine‑Authoritative)

```json
"extendedProperties": {
  "private": {
    "gcsManaged": "true",
    "gcsSource": "fpp"
  }
}
```

Rules:
- Written on all outbound exports
- Read on inbound ingestion
- Not user‑editable via UI

---

### Detection Rules

- If **either** marker is present → event is managed
- If **neither** marker is present → unmanaged
- Managed status is determined during ingestion only

---

## Outbound I/O (System → Google)

### Export Granularity

- One Manifest base SubEvent → one Google Calendar event
- No grouping
- No expansion
- No inference

---

### Event Creation Rules

Outbound export MUST:

- Preserve summary
- Preserve description metadata exactly
- Encode start/end times faithfully
- Encode recurrence rules if present
- Attach managed markers

Outbound export MUST NOT:

- Resolve symbolic dates or times
- Materialize year‑specific dates
- Modify intent semantics
- Merge or split entries

---

### Symbolic Preservation

- Symbolic dates and holidays MUST remain symbolic
- Symbolic names MUST preserve original case and spelling
- No normalization (e.g. lowercasing) is permitted
- Hard dates MUST be null when the value is symbolic

Unsupported symbolic constructs cause **hard export failure**.

---

## Failure Semantics

Google Calendar I/O failures are **hard failures**:

- OAuth errors
- Unsupported recurrence rules
- Unsupported symbolic constructs
- Provider constraints preventing faithful export

Partial Apply is forbidden.

---

## Non‑Goals (Google‑Specific)

- Calendar‑side semantic validation
- Automatic repair of user edits
- Partial event mutation
- Exception‑level patching
- UI‑driven intent authoring

---

## Guarantees

- API and ICS transports are behaviorally equivalent
- Managed state is explicit and reversible
- Manifest intent is never silently altered
- Calendar edits either round‑trip or fail loudly

---

## Relationship to Apply

- Calendar I/O produces artifacts only
- Apply decides authority and direction
- Google Calendar is never authoritative by default
- Authority is resolved exclusively by Diff logic

---

> Google Calendar I/O is a transport and representation concern — never a semantic one.
