# Google Calendar Adapter

This directory contains the Google Calendar provider implementation
for the Calendar Scheduler system.

Responsibilities:

- OAuth-authenticated Google Calendar API access
- Structural mapping between manifest-v2 reconciliation actions and Google events
- Faithful, lossless projection of execution geometry

This adapter is:
- Structural only
- Semantically passive
- ReconciliationAction-driven

No Diff, authority, or intent logic belongs here.
