# Calendar Scheduler Runtime vs Development Layout

## Goal
Keep end-user plugin installs lightweight while preserving full developer tooling in this repository.

## Runtime Payload (ships to users)
The runtime payload is controlled by `packaging/runtime-include.txt` and currently includes:
- Plugin entrypoints and UI API (`plugin.php`, `content.php`, `ui-api.php`, `oauth-callback.php`)
- Bootstrap and hooks (`bootstrap.php`, `fpp-env-export.php`, `fpp-schedule-save-hook.php`)
- Menu and metadata (`menu.inc`, `pluginInfo.json`)
- Runtime CLI (`bin/calendar-scheduler`)
- Core engine code (`src/`)
- Runtime directory scaffold (`runtime/.gitkeep`)

## Development-Only Content (do not ship)
Excluded by `packaging/runtime-exclude.txt`:
- Specifications and release planning (`spec/`)
- Development docs (`docs/`)
- Local editor files (`.vscode/`)
- Local FPP source mirror (`fpp/`)
- Development regression runners (`bin/cs-*` except `bin/calendar-scheduler`)
- Packaging internals (`packaging/`)

## Packaging Commands
Build release artifact:
```bash
bin/cs-package --out-dir /tmp/cs-release
```

Validate a staged package manually:
```bash
bin/cs-verify-package --dir /path/to/staged/CalendarScheduler
```

## Notes
- `bin/cs-package` enforces explicit include list + exclusion verification.
- This keeps the repository developer-friendly while producing a lean user install artifact.
