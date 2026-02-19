#!/bin/bash
set -euo pipefail

# Calendar Scheduler plugin install hook for FPP plugin manager.

PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
RUNTIME_DIR="${PLUGIN_DIR}/runtime"

mkdir -p "${RUNTIME_DIR}"
if [[ ! -f "${RUNTIME_DIR}/.gitkeep" ]]; then
  touch "${RUNTIME_DIR}/.gitkeep"
fi

if command -v chown >/dev/null 2>&1; then
  chown -R fpp:fpp "${PLUGIN_DIR}" >/dev/null 2>&1 || true
fi

if command -v chmod >/dev/null 2>&1; then
  chmod +x "${PLUGIN_DIR}/bin/calendar-scheduler" >/dev/null 2>&1 || true
fi

echo "Calendar Scheduler install complete."
