#!/usr/bin/env bash
#
# prune_snapshots.sh
# Retention for snapshot directories under /backup/snapshots
#
# - Keeps newest KEEP_COUNT directories by mtime.
# - Extra emergency pruning if /backup is critically full.
#
# Adjust if your snapshot layout is more complex (e.g. daily.0, weekly.0, etc.).
#

set -euo pipefail

# Dashboard state (backup_actions.json / backup_status.json)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEFAULT_WEB_ROOT="/home/gene/web/genesworld.net/public_html/web-admin"
if [ -n "${WEB_ADMIN_ROOT:-}" ]; then
  WEB_ADMIN_ROOT="$WEB_ADMIN_ROOT"
elif [ -d "$DEFAULT_WEB_ROOT" ]; then
  WEB_ADMIN_ROOT="$DEFAULT_WEB_ROOT"
else
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
  STATE_OWNER="webuser:webuser"
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
  if [ -x "$script" ] && [ "$script" != "$0" ]; then
    "$script" >/dev/null 2>&1 || true
  fi
}

ACTION_NAME="prune_snapshots"
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

SNAP_DIR="/backup/snapshots"
KEEP_COUNT=14
DISK_MOUNT="/backup"
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
    printf '%s [prune_snapshots] %s\n' "$(date -Iseconds)" "$*" >&2
}

usage_percent() {
    df -P "$DISK_MOUNT" | awk 'NR==2 {gsub(/%/,"",$5); print $5}'
}

if [[ ! -d "$SNAP_DIR" ]]; then
    log "Snapshot directory not found: $SNAP_DIR (nothing to prune)"
    ACTION_MESSAGE="Snapshot directory not found; nothing to prune"
    exit 0
fi
if is_excluded "$SNAP_DIR"; then
    log "Skipping prune: excluded path $SNAP_DIR"
    ACTION_MESSAGE="Skipped prune (excluded path)"
    exit 0
fi

# Newest → oldest directories
mapfile -t snap_dirs < <(
    find "$SNAP_DIR" -mindepth 1 -maxdepth 1 -type d -printf '%T@ %p\n' \
    | sort -nr \
    | awk '{ $1=""; sub(/^ /,""); print }'
)

total=${#snap_dirs[@]}
log "Found $total snapshot dir(s) in $SNAP_DIR"

if (( total <= KEEP_COUNT )); then
    log "Nothing to prune (total <= KEEP_COUNT=$KEEP_COUNT)"
    ACTION_MESSAGE="Nothing to prune (<= ${KEEP_COUNT})"
else
    for ((i=KEEP_COUNT; i<total; i++)); do
        d="${snap_dirs[$i]}"
        if [[ -d "$d" ]]; then
            log "Pruning old snapshot dir: $d"
            rm -rf -- "$d" || log "WARNING: failed to remove $d"
        fi
    done
fi

current_usage=$(usage_percent || echo 0)
if (( current_usage > CRIT_USAGE )); then
    log "Disk usage on $DISK_MOUNT is ${current_usage}% (> ${CRIT_USAGE}%), emergency pruning snapshots"

    # Oldest first now
    mapfile -t remaining < <(
        find "$SNAP_DIR" -mindepth 1 -maxdepth 1 -type d -printf '%T@ %p\n' \
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
