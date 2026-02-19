#!/bin/bash
set -euo pipefail

# Calendar Scheduler plugin uninstall hook for FPP plugin manager.
# Keep this intentionally lightweight; FPP removes the plugin directory after this hook.

PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PLUGIN_BASE="$(basename "${PLUGIN_DIR}")"
PLUGIN_PARENT="$(dirname "${PLUGIN_DIR}")"
LEGACY_NAME="GoogleCalendarScheduler"
NEW_NAME="CalendarScheduler"

remove_alias_if_points_to_current() {
  local alias_path="$1"
  if [[ -L "${alias_path}" ]]; then
    local target
    target="$(readlink "${alias_path}" || true)"
    if [[ "${target}" == "${PLUGIN_BASE}" ]]; then
      rm -f "${alias_path}" || true
    fi
  fi
}

if [[ "${PLUGIN_BASE}" == "${LEGACY_NAME}" ]]; then
  remove_alias_if_points_to_current "${PLUGIN_PARENT}/${NEW_NAME}"
elif [[ "${PLUGIN_BASE}" == "${NEW_NAME}" ]]; then
  remove_alias_if_points_to_current "${PLUGIN_PARENT}/${LEGACY_NAME}"
fi

echo "Calendar Scheduler uninstall hook complete."
