#!/usr/bin/env bash
#
# prune-micro-backups.sh
# Retention for micro backup directories under BACKUP_ROOT/micro
#
# - Keeps newest KEEP_COUNT directories by mtime.
# - Extra emergency pruning if BACKUP_ROOT is critically full.
#

set -euo pipefail

# Dashboard state (backup_actions.json / backup_status.json)
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
if [ -z "${WEB_ADMIN_ROOT:-}" ]; then
  WEB_ADMIN_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
fi
STATE_DIR="${STATE_DIR:-$WEB_ADMIN_ROOT/state}"
ACTIONS_JSON="${STATE_DIR}/backup_actions.json"
STATUS_JSON="${STATE_DIR}/backup_status.json"
mkdir -p "$STATE_DIR" 2>/dev/null || true
STATE_OWNER="${STATE_OWNER:-}"
if [ -z "$STATE_OWNER" ] && [ -f "$STATE_DIR/.owner" ]; then
  STATE_OWNER="$(tr -d " \r\n" < "$STATE_DIR/.owner" 2>/dev/null || true)"
fi
if [ -z "$STATE_OWNER" ]; then
  STATE_OWNER="$(stat -c '%U:%G' "$STATE_DIR" 2>/dev/null || true)"
fi

log_action_json() {
  local action="$1"
  local ok="$2"
  local message="${3:-}"
  local log_path="${4:-}"
  if ! command -v python3 >/dev/null 2>&1; then
    return 0
  fi
  ACTION_NAME="$action" ACTION_OK="$ok" ACTION_MESSAGE="$message" ACTION_LOG_PATH="$log_path" ACTION_SCRIPT="$0" ACTION_STATE_FILE="$ACTIONS_JSON" \
  python3 - <<'PY' || true
import os, json, tempfile, datetime
path = os.environ.get("ACTION_STATE_FILE")
if not path:
    raise SystemExit(0)
entry = {
    "ts": datetime.datetime.now(datetime.timezone.utc).isoformat(),
    "action": os.environ.get("ACTION_NAME"),
    "ok": os.environ.get("ACTION_OK","true").lower() not in ("0","false","no"),
    "message": os.environ.get("ACTION_MESSAGE") or None,
    "job_id": None,
    "script": os.environ.get("ACTION_SCRIPT"),
    "log": os.environ.get("ACTION_LOG_PATH") or None,
    "meta": None,
}
entries = []
try:
    with open(path, "r", encoding="utf-8") as f:
        data = json.load(f)
        if isinstance(data, list):
            entries = data
except FileNotFoundError:
    pass
except Exception:
    entries = []
entries.append(entry)
if len(entries) > 50:
    entries = entries[-50:]
os.makedirs(os.path.dirname(path), exist_ok=True)
fd, tmp = tempfile.mkstemp(prefix=os.path.basename(path) + ".tmp.", dir=os.path.dirname(path))
with os.fdopen(fd, "w", encoding="utf-8") as f:
    json.dump(entries, f, indent=2)
os.replace(tmp, path)
PY
  if [ -f "$ACTIONS_JSON" ]; then
    chown "$STATE_OWNER" "$ACTIONS_JSON" 2>/dev/null || true
    chmod 644 "$ACTIONS_JSON" 2>/dev/null || true
  fi
}

refresh_backup_status() {
  local script="${SCRIPT_DIR}/backup_health_check.sh"
  if [ -f "$script" ] && [ "$script" != "$0" ]; then
    local refresh_log="${STATE_DIR}/logs/backup-health-refresh.log"
    mkdir -p "$(dirname "$refresh_log")" 2>/dev/null || true
    /bin/bash "$script" >>"$refresh_log" 2>&1 || true
  fi
}

ACTION_NAME="prune_micro"
ACTION_MESSAGE="Prune complete"
finish_action() {
  local code=$?
  local ok=true
  local msg="$ACTION_MESSAGE"
  if [ "$code" -ne 0 ]; then
    ok=false
    msg="Failed with exit ${code}"
  fi
  log_action_json "$ACTION_NAME" "$ok" "$msg"
  if [ "$ok" = true ]; then
    refresh_backup_status
  fi
}
trap finish_action EXIT

BACKUP_ROOT="${BACKUP_ROOT:-/mnt/backupz}"
MICRO_DIR="${BACKUP_ROOT%/}/micro"
KEEP_COUNT=14
DISK_MOUNT="${BACKUP_ROOT%/}"
CRIT_USAGE=95
TARGET_USAGE=90

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

log() {
    printf '%s [prune_micro_backups] %s\n' "$(date -Iseconds)" "$*" >&2
}

usage_percent() {
    df -P "$DISK_MOUNT" | awk 'NR==2 {gsub(/%/,"",$5); print $5}'
}

if [[ ! -d "$MICRO_DIR" ]]; then
    log "Micro backup directory not found: $MICRO_DIR (nothing to prune)"
    ACTION_MESSAGE="Micro backup directory not found; nothing to prune"
    exit 0
fi
if is_excluded "$MICRO_DIR"; then
    log "Skipping prune: excluded path $MICRO_DIR"
    ACTION_MESSAGE="Skipped prune (excluded path)"
    exit 0
fi

# Newest → oldest directories
mapfile -t micro_dirs < <(
    find "$MICRO_DIR" -mindepth 1 -maxdepth 1 -type d -printf '%T@ %p\n' \
    | sort -nr \
    | awk '{ $1=""; sub(/^ /,""); print }'
)

total=${#micro_dirs[@]}
log "Found $total micro backup dir(s) in $MICRO_DIR"

if (( total <= KEEP_COUNT )); then
    log "Nothing to prune (total <= KEEP_COUNT=$KEEP_COUNT)"
    ACTION_MESSAGE="Nothing to prune (<= ${KEEP_COUNT})"
else
    for ((i=KEEP_COUNT; i<total; i++)); do
        d="${micro_dirs[$i]}"
        if [[ -d "$d" ]]; then
            log "Pruning old micro backup dir: $d"
            rm -rf -- "$d" || log "WARNING: failed to remove $d"
        fi
    done
fi

current_usage=$(usage_percent || echo 0)
if (( current_usage > CRIT_USAGE )); then
    log "Disk usage on $DISK_MOUNT is ${current_usage}% (> ${CRIT_USAGE}%), emergency pruning micro dirs"

    # Oldest first now
    mapfile -t remaining < <(
        find "$MICRO_DIR" -mindepth 1 -maxdepth 1 -type d -printf '%T@ %p\n' \
        | sort -n \
        | awk '{ $1=""; sub(/^ /,""); print }'
    )

    for d in "${remaining[@]}"; do
        current_usage=$(usage_percent || echo 0)
        if (( current_usage <= TARGET_USAGE )); then
            log "Disk usage down to ${current_usage}%, stopping emergency prune"
            break
        fi
        if [[ -d "$d" ]]; then
            log "Emergency prune: removing $d"
            rm -rf -- "$d" || log "WARNING: failed to remove $d"
        fi
    done
fi

log "Prune complete"
