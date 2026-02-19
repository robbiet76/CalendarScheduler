#!/bin/bash
set -euo pipefail

# Calendar Scheduler plugin uninstall hook for FPP plugin manager.
# Keep this intentionally lightweight; FPP removes the plugin directory after this hook.

echo "Calendar Scheduler uninstall hook complete."
