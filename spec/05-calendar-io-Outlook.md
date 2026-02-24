# 05 — Calendar I/O Layer (Outlook)

**Status:** DRAFT  
**Change Policy:** Intentional, versioned revisions only  
**Authority:** Behavioral Specification v2 (Provider Extension)

---

## Purpose

This document defines the **Outlook (Microsoft Graph) provider-specific implementation**
of Calendar I/O.

It extends the generic contract in:

**05 — Calendar I/O Layer**

All generic rules and guarantees apply unless explicitly overridden here.

---

## Provider Scope

This specification governs interaction with:

- Outlook calendars via Microsoft Graph Calendar API
- OAuth 2.0 device authorization flow

Current production runtime is API/OAuth only.

---

## Provider Identity

```json
{
  "provider": "outlook",
  "calendar_id": "<graph-calendar-id>"
}
```

---

## OAuth Setup Contract

Outlook setup in current runtime is intentionally constrained for predictable UX:

- User enters only `client_id` in UI
- `tenant_id` is fixed to `consumers`
- `redirect_uri` is fixed to `http://localhost:8765/oauth2callback`
- `scopes` are fixed to:
  - `offline_access openid profile User.Read Calendars.ReadWrite`
- `client_secret` is not required for the device flow path

Primary auth path:
1. `auth_outlook_save_config` persists OAuth client ID and fixed defaults
2. `auth_device_start` requests `device_code` + `user_code`
3. User authorizes at Microsoft device URL
4. `auth_device_poll` persists token and marks provider connected

`auth_disconnect` removes the local token and returns a deterministic disconnected state.

---

## Inbound I/O (Outlook → System)

Microsoft Graph event payloads are translated into canonical provider-neutral
`CalendarEvent` rows by the Outlook translator adapter.

Rules:
- No recurrence expansion
- No symbolic resolution
- No identity assignment outside canonical normalization boundaries
- Provider metadata is preserved in provider-private fields for round-trip safety

---

## Outbound I/O (System → Outlook)

Reconciliation actions are projected into provider mutations through the Outlook mapper:

- `create` → Graph event create
- `update` → Graph event update by resolved provider event ID
- `delete` → Graph event delete by resolved provider event ID

Rules:
- Mutations are generated from canonical SubEvent execution geometry
- Missing resolvable provider IDs for update/delete are hard failures or explicit skips with diagnostics
- Provider-specific category/color metadata is adapter-owned and must not leak into core semantics

---

## Calendar Selection Contract

Outlook calendar list presented to the UI is filtered to supported, writable calendars:

- Include primary calendar
- Include editable user calendars
- Exclude known system/subscribed calendars (for example birthdays/holidays)

Selection remains an operational configuration concern and does not change core semantics.

---

## Failure Semantics

Outlook Calendar I/O failures are hard failures:

- OAuth configuration/auth failures
- Graph API validation failures
- Unsupported provider constructs that cannot be represented faithfully

No silent fallback or semantic coercion is allowed.

---

## Guarantees

- Outlook adapter remains provider-scoped and deterministic
- Core Manifest/Intent semantics remain provider-agnostic
- OAuth/token state is infrastructure state and never part of Manifest data

