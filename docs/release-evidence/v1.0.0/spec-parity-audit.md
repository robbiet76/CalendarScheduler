# v1.0.0 Phase 1 Evidence: Code/Spec/Docs Parity Audit

Date (UTC): 2026-02-24T14:17:32Z  
Operator: Codex  
Candidate Branch: `feature/outlook-calendar-integration`  
Candidate Commit (start): `28450f8`

## Scope
Validated checklist Phase 1 from `spec/21-production-readiness-checklist.md`:
1. Behavioral specs in `spec/` match runtime behavior in `content.php`, `ui-api.php`, and `src/*`.
2. Provider coverage is explicit and consistent for Google + Outlook.
3. No stale references to removed flows/files remain in specs/docs.
4. Release runbook commands/flow align with current tooling.

## Audit Method
- Enumerated all `spec/` and `docs/` markdown files.
- Cross-checked UI/API action contract against `ui-api.php` action dispatcher.
- Cross-checked provider OAuth/setup behavior against `ui-api.php` constants and auth functions.
- Cross-checked regression/runbook command references against current `bin/cs-*` scripts.
- Searched for known stale references (`resolution_layer_design`, outdated redirect URI, legacy provider wording).

## Findings
### Fixed During Phase 1
1. `spec/05-calendar-io-Google.md`
   - Drift: Google redirect URI example showed `http://localhost:8765/oauth2callback`.
   - Runtime truth: `CS_GOOGLE_DEFAULT_REDIRECT_URI = http://127.0.0.1:8765/oauth2callback` in `ui-api.php`.
   - Fix: Updated Google spec example to `http://127.0.0.1:8765/oauth2callback`.

2. `spec/22-release-runbook.md`
   - Drift: Preflight and Phase 2 gate command sets did not explicitly include provider parity runner.
   - Runtime/test truth: `bin/cs-provider-parity-regression` is part of current regression toolchain.
   - Fix: Added explicit provider parity step to preflight and Phase 2 command sequence.

### Verified Aligned
- UI/API contract includes provider-aware actions and diagnostics keys (`provider`, `set_provider`, auth actions, UI prefs).
- Provider coverage is explicit in spec TOC and provider-specific I/O specs (`Google`, `Outlook`).
- No remaining references to deleted `spec/resolution_layer_design.md` in `spec/` or `docs/`.
- Release runbook commands reference existing runner binaries and valid flags.

## Phase 1 Gate Decision
- [x] No unresolved parity drift items.
- [x] Drift discovered in this phase was fixed in candidate branch.

Result: **PASS**

## Files Updated In This Phase
- `spec/05-calendar-io-Google.md`
- `spec/22-release-runbook.md`
- `docs/release-evidence/v1.0.0/spec-parity-audit.md`
