# Outlook Calendar Adapter

This directory is the initial Outlook provider scaffold intended to mirror the Google adapter architecture.

Current status:
- Class and file layout now matches Google's adapter boundaries.
- OAuth bootstrap supports authorization-code flow and token persistence.
- API client supports token refresh plus Graph calendar/event CRUD + list operations.
- Mapper/executor support create/update/delete mutation flow with provider ID correlation persistence.

Next implementation milestones:
1. Expand `OutlookEventMapper` recurrence/override parity to match `GoogleEventMapper`.
2. Add Outlook-specific diagnostics/setup endpoints in `ui-api.php`.
3. Add optional Outlook callback endpoint (web flow parity with Google callback helper).
