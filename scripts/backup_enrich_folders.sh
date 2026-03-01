#!/usr/bin/env bash
set -euo pipefail

# Adjust if your paths differ
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
STATUS_FILE="$STATE_DIR/backup_status.json"
BACKUP_ROOT="/mnt/backupz"

mkdir -p "$STATE_DIR" 2>/dev/null || true
STATE_OWNER="${STATE_OWNER:-}"
if [ -z "$STATE_OWNER" ] && [ -f "$STATE_DIR/.owner" ]; then
  STATE_OWNER="$(tr -d " \r\n" < "$STATE_DIR/.owner" 2>/dev/null || true)"
fi
if [ -z "$STATE_OWNER" ]; then
  STATE_OWNER="webuser:webuser"
fi

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

if ! command -v jq >/dev/null 2>&1; then
  echo "ERROR: jq is required for backup_enrich_folders.sh" >&2
  exit 1
fi

log_action_json() {
  local action="$1"
  local ok="$2"
  local message="${3:-}"
  local log_path="${4:-}"
  if ! command -v python3 >/dev/null 2>&1; then
    return 0
  fi
  ACTION_NAME="$action" ACTION_OK="$ok" ACTION_MESSAGE="$message" ACTION_LOG_PATH="$log_path" ACTION_SCRIPT="$0" ACTION_STATE_FILE="${STATE_DIR}/backup_actions.json" \
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
  local actions_json="${STATE_DIR}/backup_actions.json"
  if [ -f "$actions_json" ]; then
    chown "$STATE_OWNER" "$actions_json" 2>/dev/null || true
    chmod 644 "$actions_json" 2>/dev/null || true
  fi
}

ACTION_NAME="enrich_folders"
ACTION_MESSAGE="Enriched backup folder sizes"
finish_action() {
  local code=$?
  local ok=true
  local msg="$ACTION_MESSAGE"
  if [ "$code" -ne 0 ]; then
    ok=false
    msg="Enrich folder sizes failed with exit ${code}"
  fi
  log_action_json "$ACTION_NAME" "$ok" "$msg"
}
trap finish_action EXIT

if [ ! -f "$STATUS_FILE" ]; then
  echo "ERROR: Status file not found: $STATUS_FILE" >&2
  exit 1
fi

# Total disk size in GB
total_gb=$(df -BG "$BACKUP_ROOT" 2>/dev/null | awk 'NR==2 {gsub("G","",$2); print $2+0}')

# Folder sizes in GB (0 if folder missing or excluded)
size_hestia=0
size_micro=0
size_snaps=0
if ! is_excluded "$BACKUP_ROOT/hestia"; then
  size_hestia=$(du -sBG "$BACKUP_ROOT/hestia" 2>/dev/null | awk '{gsub("G","",$1); print $1+0}')
fi
if ! is_excluded "$BACKUP_ROOT/micro"; then
  size_micro=$(du -sBG "$BACKUP_ROOT/micro" 2>/dev/null | awk '{gsub("G","",$1); print $1+0}')
fi
if ! is_excluded "$BACKUP_ROOT/snapshots"; then
  size_snaps=$(du -sBG "$BACKUP_ROOT/snapshots" 2>/dev/null | awk '{gsub("G","",$1); print $1+0}')
fi

: "${size_hestia:=0}"
: "${size_micro:=0}"
: "${size_snaps:=0}"
: "${total_gb:=0}"

tmp="${STATUS_FILE}.tmp"

jq \
  --argjson total_gb "$total_gb" \
  --arg hestia_path "$BACKUP_ROOT/hestia" \
  --arg micro_path  "$BACKUP_ROOT/micro" \
  --arg snaps_path  "$BACKUP_ROOT/snapshots" \
  --argjson size_hestia "$size_hestia" \
  --argjson size_micro  "$size_micro" \
  --argjson size_snaps  "$size_snaps" \
  '
  .disk.total_gb = $total_gb
  | .disk.folders = [
      {label:"/hestia",    path:$hestia_path,    size_gb:$size_hestia},
      {label:"/micro",     path:$micro_path,     size_gb:$size_micro},
      {label:"/snapshots", path:$snaps_path,     size_gb:$size_snaps}
    ]
  ' "$STATUS_FILE" > "$tmp"

mv "$tmp" "$STATUS_FILE"
