#!/usr/bin/env bash
set -euo pipefail

WEB_USER="${1:-www-data}"
[[ "${WEB_USER}" =~ ^[a-z_][a-z0-9_-]*$ ]] || {
  printf '%s\n' "Usage: render_log_bridge_sudoers.sh [web_user]" >&2
  exit 64
}

SCRIPT_DIR="$(CDPATH='' cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
BRIDGE_PATH="${SCRIPT_DIR}/log_bridge.sh"
SUDOERS_PATH="/etc/sudoers.d/server-dashboard-log-bridge"
ESCAPED_BRIDGE="${BRIDGE_PATH// /\\ }"

cat <<EOF
# ${SUDOERS_PATH}
# Allow the web user to run the exact privileged log bridge and nothing broader.
${WEB_USER} ALL=(root) NOPASSWD: ${ESCAPED_BRIDGE}
EOF
