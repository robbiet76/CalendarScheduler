# Calendar Scheduler Quick Start

This guide is for first-time setup on an FPP instance with the Calendar Scheduler plugin installed.

## Prerequisites
- FPP `9.0+`
- Plugin installed and visible in FPP UI
- Google account with access to the target calendar
- Google Cloud OAuth client JSON of type `TV and Limited Input`

## 1) Open The Plugin
1. In FPP, open `Content Setup -> Plugins -> Calendar Scheduler`.
2. Confirm the page loads and shows `Connection Setup`, `Pending Actions`, and `Apply Changes`.

## 2) Upload OAuth Client JSON
1. In `Connection Setup`, click `Upload Client JSON`.
2. Select your downloaded OAuth JSON file.
3. Wait for setup checks to show:
   - `Device client file present: OK`
   - `Config present: OK`
   - `Config valid: OK`
   - `Token directory writable: OK`

## 3) Connect Provider
1. Click `Connect Provider`.
2. Open `google.com/device`.
3. Enter the shown code.
4. Return to FPP and wait for connected state.

Expected connected indicators:
- `Connected Account` is populated.
- Calendar selector loads available calendars.
- Sync mode selector is enabled.

## 4) Select Calendar And Sync Mode
1. Choose the target calendar in `Sync Calendar`.
2. Choose sync mode:
   - `Two-way Merge (Both)` for normal bidirectional sync.
   - `Calendar -> FPP` for calendar-authoritative writes to FPP only.
   - `FPP -> Calendar` for FPP-authoritative writes to calendar only.

## 5) Run First Preview
1. Wait for `Pending Actions` to load.
2. Review rows (create/update/delete).
3. Confirm `Status` at top shows either:
   - `Needs Review` (changes pending), or
   - `In Sync` (no changes).

## 6) Apply Changes
1. Click `Apply Changes`.
2. Wait for refresh and post-apply preview.
3. Confirm no unexpected pending actions remain.

## 7) Verify Diagnostics
Open `Diagnostics` and confirm these keys are present:
- `syncMode`
- `selectedCalendarId`
- `counts`
- `pendingSummary`
- `lastError`

## Safe First-Run Recommendation
- First run in `Calendar -> FPP` if calendar is your source of truth.
- Apply once, confirm expected FPP schedule output, then switch to `Both` if desired.
