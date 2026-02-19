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

Both transports MUST emit and consume the same canonical `CalendarEvent` representation.

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
[cs]
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
    "cs.managed": "true",
    "cs.source": "fpp"
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

## Apply → Google Event Projection

This section defines the **authoritative mutation contract** used when the Apply phase
projects Manifest execution geometry into Google Calendar via the API.

Apply operates strictly at the **Manifest Event** level.

- One Manifest Event maps to exactly one Google Calendar event
- SubEvents are aggregated inputs and are never written independently

---

### ApplyOp Specification

The Apply phase communicates with Google Calendar exclusively through
**explicit, fully‑resolved mutation operations** called **ApplyOps**.

ApplyOps are the sole input to Google Calendar I/O during mutation.
They are produced by Diff and are authority‑final.

Calendar I/O MUST treat ApplyOps as instructions, not proposals.

---

## OAuth Setup and User Configuration (Google)

This section defines the **required, supported, and proven** OAuth configuration
for connecting Calendar Scheduler to Google Calendar via the Google Calendar API.

This is a **one-time, user-performed bootstrap process**.
It is infrastructure setup, not part of Manifest, Diff, or Apply semantics.

---

### Supported OAuth Client Type

**Exactly one OAuth client type is supported:**

- ✅ **Web Application**
- ❌ Desktop App (NOT supported)

Desktop App clients are explicitly unsupported because they do not reliably support
redirect-based consent flows required by Calendar Scheduler.

---

### Google Cloud Console Configuration

Create a new OAuth 2.0 Client ID with:

- **Application type:** Web application

#### Authorized Redirect URIs

Exactly one redirect URI MUST be configured:

```
http://127.0.0.1:8765/oauth2callback
```

Rules:
- `127.0.0.1` MUST be used (not `localhost`)
- Port `8765` is required
- Path `/oauth2callback` is required
- Trailing slashes MUST NOT be added

#### Authorized JavaScript Origins

This field:
- MAY be left empty
- Is not used by Calendar Scheduler

---

### Client Secret File

After creating the OAuth client:

1. Download the client secret JSON from Google Cloud Console
2. Place it on the FPP system at:

```
/home/fpp/media/config/calendar-scheduler/calendar/google/client_secret.json
```

Rules:
- File MUST be valid JSON
- File MUST contain a `"web"` OAuth client
- File permissions MUST allow read access by the plugin

---

### Calendar Scheduler Configuration

The Google calendar configuration file MUST exist at:

```
/home/fpp/media/config/calendar-scheduler/calendar/google/config.json
```

Minimum required structure:

```json
{
  "provider": "google",
  "calendar_id": "primary",
  "oauth": {
    "client_file": "client_secret.json",
    "token_file": "token.json",
    "redirect_uri": "http://127.0.0.1:8765/oauth2callback",
    "scopes": [
      "https://www.googleapis.com/auth/calendar"
    ]
  }
}
```

---

### OAuth Bootstrap (CLI Flow)

OAuth authorization is completed in **two distinct phases**.

The CLI **does not act as a browser-based OAuth client** and must not be relied upon
to generate a usable consent URL on constrained systems (e.g. FPP).

Instead, the CLI performs **code exchange only**.

#### Phase 1 — Authorization Code Generation (User Browser)

The user MUST generate the authorization code manually using the Web OAuth client.

A valid authorization URL has the form:

https://accounts.google.com/o/oauth2/v2/auth?client_id=<CLIENT_ID>&redirect_uri=http://127.0.0.1:8765/oauth2callback&response_type=code&scope=https://www.googleapis.com/auth/calendar&access_type=offline&prompt=consent

Rules:
- `<CLIENT_ID>` MUST match the Web Application OAuth client
- The redirect URI MUST exactly match the configured redirect URI
- The browser may run on **any machine**
- The redirect target does NOT need to be reachable

After consent, Google will redirect to:

http://127.0.0.1:8765/oauth2callback?code=...

The user MUST copy the value of the `code` parameter.

#### Phase 2 — Token Exchange (CLI)

The authorization code is exchanged on FPP via:

```bash
php bin/calendar-scheduler google:auth
```

The CLI will prompt for the authorization code.
On success, `token.json` is written.

---

### Token Storage

OAuth tokens are written to:

```
/home/fpp/media/config/calendar-scheduler/calendar/google/token.json
```

Rules:
- Tokens are infrastructure state
- Tokens MUST NOT be stored in the Manifest
- Tokens MUST NOT be embedded in calendar data

---

### Failure Semantics

OAuth setup failures are **hard failures**.

Calendar Scheduler MUST:
- Fail loudly
- Emit actionable error messages
- Never silently fall back to alternative auth flows

OAuth must succeed before any API-based Calendar I/O is permitted.

---

## OAuth Error 400 — Root Cause and Resolution

Google OAuth Error 400 during consent is **always a configuration error**.
It is never transient and never resolved by retries.

Calendar Scheduler has proven exactly **one valid configuration**.

---

### Proven Working Configuration

OAuth consent succeeds **only** when all of the following are true:

1. OAuth client type is **Web Application**
2. Redirect URI matches **exactly**:

```
http://127.0.0.1:8765/oauth2callback
```

3. The Google Calendar API is enabled for the project
4. Consent is completed in a browser
5. The `code` query parameter is pasted back into the CLI

Any deviation results in a hard OAuth failure.

---

### Common Causes of Error 400

| Cause | Result |
|-----|------|
| Desktop App OAuth client | Google rejects redirect-based flow |
| Using `localhost` instead of `127.0.0.1` | Redirect URI mismatch |
| Missing `/oauth2callback` path | Redirect URI mismatch |
| Trailing slash differences | Redirect URI mismatch |
| Google Calendar API not enabled | OAuth fails before consent |
| Attempting OOB (`urn:ietf:wg:oauth:2.0:oob`) | Deprecated by Google |
| Attempting to use the CLI-generated URL instead of a manually constructed authorization URL | Authorization fails, Error 400 |

---

### Architectural Clarification

Although authorization is initiated from the CLI:

- The **browser** is the OAuth user agent
- The **OAuth client is a Web Application**
- The CLI is **not** an OAuth client

The CLI:
- Prints the consent URL
- Waits for the authorization code
- Exchanges the code for tokens

This architecture requires Web Application OAuth semantics.

Desktop App OAuth is incompatible with this model.

### Proven Operational Model

Calendar Scheduler operates using a **split OAuth model**:

- Authorization UI → User browser (any machine)
- Token exchange → FPP CLI
- API access → FPP runtime

This model is intentional and required to support headless systems.

Any implementation that assumes the FPP device itself can complete
a browser-based OAuth flow is invalid.

---

## Google API Enablement

OAuth consent **will not succeed** unless the Google Calendar API
is explicitly enabled for the project.

### Required API

In Google Cloud Console:

```
APIs & Services → Library → Google Calendar API
```

The API MUST be:

- Enabled
- Enabled on the same project as the OAuth client

Disabling the API after token issuance will break Apply and Refresh.

---

## OAuth Scopes

Calendar Scheduler requires exactly one OAuth scope:

```
https://www.googleapis.com/auth/calendar
```

Rules:

- Scope MUST be declared in:
  - OAuth consent screen
  - CLI-generated authorization URL
  - `config.json`

- No additional scopes are permitted
- Reduced scopes (read-only) are not supported

Scope mismatch results in:
- Token issuance failure
- Partial API failures during Apply

---

### Scope Declaration Locations

Scope MUST be present in **all** of the following:

1. Google Cloud Console → OAuth Consent Screen
2. Calendar Scheduler `config.json`
3. Authorization URL generated by `google:auth`

Calendar Scheduler MUST treat scope mismatch as a hard failure.

---

---

## ApplyOp → Google API Mutation Mapping

This section defines the **mechanical, provider-specific projection rules**
used to translate an ApplyOp into a concrete Google Calendar API mutation.

These rules are **purely translational**.
They introduce no new semantics, authority, or reconciliation logic.

---

### Mapping Scope

- Apply operates at the **Manifest Event** level
- Exactly **one Google Calendar event** is addressed per ApplyOp
- Manifest SubEvents are **inputs**, not write targets

Calendar I/O MUST NOT:
- Inspect Manifest state
- Perform Diff logic
- Infer authority
- Expand recurrence
- Synthesize SubEvents

---

### Operation Mapping

#### CREATE

- Google API: `events.insert`
- `providerEventId` MUST be absent
- `etag` MUST be absent

The created Google event MUST:
- Encode execution geometry from the base SubEvent
- Include recurrence (RRULE) if present
- Attach CS managed markers
- Persist reverse-mapping metadata

---

#### UPDATE

- Google API: `events.update`
- `providerEventId` MUST be present
- `etag` SHOULD be supplied

Rules:
- Full event replacement (no patch semantics)
- If `etag` is present, it MUST be enforced
- `412 Precondition Failed` is a hard failure

---

#### DELETE

- Google API: `events.delete`
- `providerEventId` MUST be present
- Content-based matching is forbidden

Deleting a non-existent event MUST surface as a hard failure.

---

### Execution Geometry Projection

The **base SubEvent** defines:

- `DTSTART`
- `DTEND`
- `RRULE`

Rules:
- Timezone is always FPP local timezone
- All-day semantics are preserved exactly
- `24:00:00` end-time semantics MUST NOT be normalized

Exception SubEvents MUST NOT alter base timing.

---

### Recurrence Rules (RRULE)

- Derived exclusively from the base SubEvent
- Written verbatim as provided by Resolution
- No provider-side interpretation or expansion

`UNTIL` values are written exactly as supplied.

---

### Exceptions (EXDATE)

Each exception SubEvent maps to exactly one `EXDATE`.

Rules:
- Timestamp-exact
- Same timezone as `DTSTART`
- No deduplication or inference

---

### Metadata and Reverse Mapping

Outbound Apply MUST write machine-authoritative metadata using
Google `extendedProperties.private`:

```json
{
  "cs.manifestEventId": "<manifestEventId>",
  "cs.provider": "google",
  "cs.schemaVersion": "1"
}
```

These fields:
- Are not user-editable
- Are never interpreted semantically
- Exist solely for addressing and safety

---

### Explicit Non-Support

Calendar I/O MUST NOT:

- Retry failed mutations with relaxed constraints
- Perform partial updates
- Expand recurrence
- Merge or split events
- Coerce unsupported constructs

Failure is correct behavior.

---

#### ApplyOp Shape

```ts
ApplyOp {
  op: "create" | "update" | "delete"
  manifestEventId: string
  provider: "google"
  providerEventId?: string
  etag?: string
  baseSubEvent: SubEvent
  exceptionSubEvents: SubEvent[]
}
```

---

#### Field Semantics

- `op`  
  The mutation type to apply.

- `manifestEventId`  
  Stable identifier of the Manifest Event being projected.
  Used for reverse mapping and metadata attachment only.

- `provider`  
  Target provider identifier. For this spec, always `"google"`.

- `providerEventId`  
  Required for `update` and `delete`.  
  Forbidden for `create`.

- `etag`  
  Optional concurrency guard retrieved during ingestion.
  When present, MUST be enforced.

- `baseSubEvent`  
  The authoritative execution geometry source:
  - Defines DTSTART
  - Defines DTEND
  - Defines RRULE

- `exceptionSubEvents`  
  Zero or more SubEvents representing explicit exclusions.
  Each exception SubEvent maps to exactly one EXDATE.

---

#### Structural Rules

- Exactly one `baseSubEvent` MUST be present
- `exceptionSubEvents` MAY be empty
- Apply MUST NOT infer or synthesize SubEvents
- Apply MUST NOT expand recurrence
- Apply MUST NOT collapse or merge exceptions

ApplyOps are **complete, self‑contained**, and **non‑derivable**.

Calendar I/O MUST NOT:
- Inspect Manifest state
- Perform Diff logic
- Resolve symbolic constructs
- Apply authority rules

---

#### Directional Authority

ApplyOps are directional by construction.

Calendar I/O MUST assume:
- Authority has already been resolved
- Conflicts have already been decided
- Mutation is safe to execute or fail

---

#### Failure Rules

- Invalid ApplyOp shapes cause immediate failure
- Missing `providerEventId` on update/delete is fatal
- ETag mismatch MUST surface as a hard failure
- Partial execution is forbidden

Apply is atomic per run.

- Google `start` and `end` are derived **exclusively** from the base SubEvent
- Exception SubEvents MUST NOT alter `DTSTART` or `DTEND`
- All‑day semantics are preserved exactly
- Timezone is the FPP local timezone

---

### Recurrence (RRULE)

- RRULE is derived from the base SubEvent only
- `FREQ`, `INTERVAL`, and `BYDAY` are projected verbatim
- `UNTIL` is written exactly as provided by Resolution
- No recurrence interpretation or expansion occurs during Apply

---

### Exceptions (EXDATE)

Each exception SubEvent is projected as a single `EXDATE`.

Rules:

- One exception SubEvent → one `EXDATE`
- EXDATE values are:
  - In the DTSTART timezone
  - Timestamp‑exact (date‑only for all‑day events)
- No deduplication, collapsing, or inference is permitted

---

### Summary and Description

Apply MUST:

- Write `summary` derived from the Manifest Event target
- Write `description` containing:
  - Calendar Description Metadata (INI)
  - CS managed marker ([cs] INI block)
  - Provider provenance block

Apply MUST NOT interpret or normalize description metadata.

---

### Extended Properties (Reverse Mapping)

Outbound Apply MUST write machine‑authoritative identifiers:

```json
"extendedProperties": {
  "private": {
    "cs.manifestEventId": "<id>",
    "cs.provider": "google",
    "cs.schemaVersion": "1"
  }
}
```

These fields are:
- Not user‑editable
- Used exclusively for addressing and reconciliation safety
- Never used for semantic interpretation

---

### Deletes

- Deletes are addressed strictly by `googleEventId`
- Content‑based matching is forbidden
- Missing target events cause explicit failure

---

### Idempotency and Concurrency

- Updates and deletes SHOULD include `etag`
- `412 Precondition Failed` MUST surface as a hard failure
- Apply MUST NOT retry with relaxed constraints

Conflict resolution is outside the scope of Apply.

---

### Explicit Non‑Support

The following are intentionally unsupported during Apply:

- Partial SubEvent writes
- Provider‑side recurrence expansion
- Silent coercion of unsupported constructs
- Multi‑calendar mutation in a single Apply run

Failures are correct behavior.

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
