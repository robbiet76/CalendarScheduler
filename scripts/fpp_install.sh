#!/bin/bash
set -euo pipefail

# Calendar Scheduler plugin install hook for FPP plugin manager.

PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
RUNTIME_DIR="${PLUGIN_DIR}/runtime"
PLUGIN_BASE="$(basename "${PLUGIN_DIR}")"
PLUGIN_PARENT="$(dirname "${PLUGIN_DIR}")"
LEGACY_NAME="GoogleCalendarScheduler"
NEW_NAME="CalendarScheduler"

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

# Maintain dual-name compatibility during rename migration window.
if [[ "${PLUGIN_BASE}" == "${LEGACY_NAME}" ]]; then
  ln -sfn "${PLUGIN_BASE}" "${PLUGIN_PARENT}/${NEW_NAME}" || true
elif [[ "${PLUGIN_BASE}" == "${NEW_NAME}" ]]; then
  ln -sfn "${PLUGIN_BASE}" "${PLUGIN_PARENT}/${LEGACY_NAME}" || true
fi

echo "Calendar Scheduler install complete."
