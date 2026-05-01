#!/bin/bash
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [ -f "/etc/server-dashboard/dashboard_env.sh" ]; then
  # shellcheck disable=SC1091
  source "/etc/server-dashboard/dashboard_env.sh"
elif [ -f "$SCRIPT_DIR/lib/dashboard_env.sh" ]; then
  # shellcheck disable=SC1091
  source "$SCRIPT_DIR/lib/dashboard_env.sh"
fi
if declare -F dashboard_env_bootstrap >/dev/null 2>&1; then
  dashboard_env_bootstrap "$SCRIPT_DIR"
fi
WEB_ADMIN_ROOT="${WEB_ADMIN_ROOT:-$(cd "$SCRIPT_DIR/.." && pwd)}"
src="$WEB_ADMIN_ROOT/data/services_status_history.jsonl"
dst_dir="$WEB_ADMIN_ROOT/config/backups"
ts="$(date +%F)"

EXCLUDES_RAW="${BACKUP_EXCLUDES:-}"
EXCLUDES=()
if [[ -n "$EXCLUDES_RAW" ]]; then
  EXCLUDES_RAW="$(echo "$EXCLUDES_RAW" | tr ',\n' '  ')"
  read -r -a EXCLUDES <<< "$EXCLUDES_RAW"
fi

is_excluded() {
  local path="$1"
  local p="${path%/}"
  local ex
  for ex in "${EXCLUDES[@]}"; do
    ex="${ex%/}"
    [ -z "$ex" ] && continue
    if [[ "$p" == $ex || "$p" == $ex/* ]]; then
      return 0
    fi
  done
  return 1
}

if [ ! -f "$src" ]; then
  exit 0
fi
mkdir -p "$dst_dir"

if is_excluded "$dst_dir"; then
  exit 0
fi

cp "$src" "$dst_dir/services_status_history_$ts.jsonl"
