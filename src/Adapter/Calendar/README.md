# Calendar Adapter

This directory contains provider adapter implementations for Calendar Scheduler.

Shared adapter responsibilities:

- OAuth-authenticated provider API access
- Structural mapping between reconciliation actions and provider events
- Translation from provider event resources into the common snapshot/event shape

Adapter boundaries:

- Structural only
- Semantically passive
- ReconciliationAction-driven

No authority, diff, or intent ownership logic belongs in adapter code.

## Google Provider

Current coverage:

- OAuth bootstrap + token persistence
- Calendar/event API CRUD + list
- Mapper/executor create/update/delete flow
- Translator ingestion from Google event resources to common shape

## Outlook Provider

Current coverage:

- OAuth bootstrap + token persistence
- Calendar/event API CRUD + list (Graph)
- Mapper/executor create/update/delete flow with provider ID correlation persistence
- Translator ingestion from Outlook event resources to common shape

Parity goal:

- Keep Google/Outlook architecture and UX behavior as close as possible.
