#!/bin/bash
set -euo pipefail

# Conservative, explicit PATH for cron/systemd/PHP
PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"
export PATH

# Safer default permissions for any created files/dirs
umask 027

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

ACTION_NAME="os_snapshot"
ACTION_MESSAGE="Completed OS snapshot"
finish_action() {
  local code=$?
  local ok=true
  local msg="$ACTION_MESSAGE"
  if [ "$code" -ne 0 ]; then
    ok=false
    msg="Failed with exit ${code}"
  fi
  log_action_json "$ACTION_NAME" "$ok" "$msg" "$LOG_FILE"
  if [ "$ok" = true ]; then
    refresh_backup_status
  fi
}
trap finish_action EXIT

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [ -z "${WEB_ADMIN_ROOT:-}" ]; then
  WEB_ADMIN_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
fi

CONFIG_FILE="${WEB_ADMIN_ROOT}/config/local.json"
if [ -z "${BACKUP_ROOT:-}" ] && command -v jq >/dev/null 2>&1 && [ -f "$CONFIG_FILE" ]; then
  cfg_root="$(jq -r '.backups.fs_root // empty' "$CONFIG_FILE" 2>/dev/null || true)"
  if [ -n "$cfg_root" ]; then
    BACKUP_ROOT="$cfg_root"
  fi
fi
if [ -z "${BACKUP_EXCLUDES:-}" ] && command -v jq >/dev/null 2>&1 && [ -f "$CONFIG_FILE" ]; then
  cfg_excludes="$(jq -r '.backups.exclude_dirs // empty' "$CONFIG_FILE" 2>/dev/null || true)"
  if [ -n "$cfg_excludes" ]; then
    BACKUP_EXCLUDES="$cfg_excludes"
    export BACKUP_EXCLUDES
  fi
fi

EXCLUDES_RAW="${BACKUP_EXCLUDES:-}"
EXCLUDES=()
if [[ -n "$EXCLUDES_RAW" ]]; then
    EXCLUDES_RAW="$(echo "$EXCLUDES_RAW" | tr ',\n' '  ')"
    read -r -a EXCLUDES <<< "$EXCLUDES_RAW"
fi

BACKUP_ROOT="${BACKUP_ROOT:-/mnt/backupz}"
BACKUP="${BACKUP_ROOT%/}/snapshots"
LOG_FILE="/var/log/os-snapshot.log"
HOSTNAME=$(hostname -f 2>/dev/null || hostname)
NOW_HUMAN=$(date "+%Y-%m-%d %H:%M:%S")

# Make sure log dir exists (in case it doesn't yet)
mkdir -p "$(dirname "$LOG_FILE")"

# Rotate daily snapshots
rm -rf "$BACKUP/daily.2"
[ -d "$BACKUP/daily.1" ] && mv "$BACKUP/daily.1" "$BACKUP/daily.2"
[ -d "$BACKUP/daily.0" ] && mv "$BACKUP/daily.0" "$BACKUP/daily.1"

mkdir -p "$BACKUP/daily.0"

# Only use --link-dest if we actually have a previous snapshot
LINKDEST=()
if [ -d "$BACKUP/daily.1" ]; then
    LINKDEST=(--link-dest="$BACKUP/daily.1")
fi

RSYNC_EXCLUDES=()
for ex in "${EXCLUDES[@]}"; do
    ex="${ex%/}"
    [ -z "$ex" ] && continue
    if [[ "$ex" == /* ]]; then
        rel="${ex#/}"
        RSYNC_EXCLUDES+=(--exclude="/${rel}" --exclude="/${rel}/**")
    else
        RSYNC_EXCLUDES+=(--exclude="${ex}" --exclude="${ex}/**")
    fi
done

rsync -aHAX --delete \
    "${LINKDEST[@]}" \
    "${RSYNC_EXCLUDES[@]}" \
    --exclude={"/proc/*","/sys/*","/dev/*","/run/*","/mnt/*","/media/*","/lost+found"} \
    / "$BACKUP/daily.0"

# Mark completion so health checks can use a reliable timestamp
touch "$BACKUP/daily.0/.snapshot_complete" 2>/dev/null || true

if [ -n "${BACKUP_CHOWN:-}" ]; then
  chown -R "$BACKUP_CHOWN" "$BACKUP" 2>/dev/null || true
fi

# Append a simple summary line to the same log PHP tails/rotates
{
    printf '%s HOST=%s ACTION=os_snapshot STATUS=OK SRC=/ DST=%s\n' \
      "$NOW_HUMAN" "$HOSTNAME" "$BACKUP/daily.0"
} >> "$LOG_FILE"

exit 0
