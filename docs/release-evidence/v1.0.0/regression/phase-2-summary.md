# v1.0.0 Phase 2 Evidence: Automated Regression Gates

Date (UTC): 2026-02-24  
Operator: Codex  
Branch: `feature/outlook-calendar-integration`  
Commit under test: `495603c` (followed by UI-only updates to `726a85c`, `8b1828e`)

## Gate Commands and Results

### 1) Resolution Regression
Command:
```bash
bin/cs-resolution-regression --json
```
Artifact:
- `resolution.json`

Result:
- `ok: true`

### 2) Provider Parity Regression
Command:
```bash
bin/cs-provider-parity-regression --json
```
Artifact:
- `provider-parity.json`

Result:
- `ok: true`
- Google checks: pass
- Outlook checks: pass

### 3) API Smoke
Authoritative environment: FPP host plugin runtime.

Command (FPP):
```bash
bin/cs-api-smoke --json
```
Artifact:
- `api-smoke.fpp.json`

Result:
- `ok: true`

Note:
- Local API smoke run from developer host failed due no local FPP web endpoint (`127.0.0.1:80` unavailable).
- Captured as non-authoritative context:
  - `api-smoke.local-no-fpp-endpoint.json`

### 4) Full Regression
Authoritative environment: FPP host plugin runtime.

Command (FPP):
```bash
bin/cs-full-regression --json --api-include-apply-noop
```
Artifact:
- `full-regression.fpp.json`

Result:
- `ok: true`
- Resolution: pass
- Live apply/check: pass
- API smoke section: pass
- Provider parity section: pass

Note:
- Local full regression run from developer host failed for the same endpoint reason.
- Captured as non-authoritative context:
  - `full-regression.local-no-fpp-endpoint.json`

## Phase 2 Gate Decision
- [x] All automated suites pass with no unexpected failures (authoritative FPP runtime).

Result: **PASS**
