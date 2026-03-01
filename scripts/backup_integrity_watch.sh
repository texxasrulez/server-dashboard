#!/usr/bin/env bash
set -euo pipefail

# ------------ ENVIRONMENT -----------------------------------------------
PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"
export PATH
umask 027

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

ACTION_NAME="integrity_check"
ACTION_MESSAGE="Integrity watch completed"
finish_action() {
  local code=$?
  local ok=true
  local msg="$ACTION_MESSAGE"
  if [ "$code" -ne 0 ]; then
    ok=false
    msg="Integrity watch failed with exit ${code}"
  fi
  log_action_json "$ACTION_NAME" "$ok" "$msg" "$LOG_FILE"
  if [ "$ok" = true ]; then
    refresh_backup_status
  fi
}
trap finish_action EXIT

# Exclude handling (space/comma/newline-separated)
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

# ------------ LOCKING (safe for root + web UI users) --------------------
# Use per-user locks to avoid cross-user permission fights.
if command -v flock >/dev/null 2>&1; then
  LOCK_DIR="/run/lock/backup-suite"
  mkdir -p "$LOCK_DIR"
  chmod 1777 "$LOCK_DIR"
  LOCK_FILE="$LOCK_DIR/$(basename "$0").${UID}.lock"
  exec 9>"$LOCK_FILE"
  flock -n 9 || exit 0
fi

# ------------ CONFIG ----------------------------------------------------
BACKUP_ROOT="/mnt/backupz"

# Snapshot target
SNAP_DIR="$BACKUP_ROOT/snapshots"
SNAP_PATH="$SNAP_DIR/daily.0"

# Backup sources (adjust if your layout differs)
HESTIA_DIR="$BACKUP_ROOT/hestia"
MICRO_DIR="$BACKUP_ROOT/micro"

EMAIL_TO="gene@genesworld.net"
HOSTNAME="$(hostname -f 2>/dev/null || hostname || echo 'localhost')"

STATE_DIR="/var/lib/backup-health"
STATE_FILE="$STATE_DIR/integrity_state.sh"
LOG_FILE="/var/log/backup-integrity.log"

# shrink thresholds (when shrink-check is enabled)
MIN_SHRINK_PCT=10                       # alert if drop >= 10%
MIN_SHRINK_BYTES=$((100*1024*1024))     # 100MB absolute drop

# Snapshot shrink is *not* a reliable signal when you rotate/prune snapshots.
# Default OFF to avoid false alarms.
CHECK_SNAPSHOT_SHRINK=0

# If you enable snapshot shrink checks, ignore shrink during this grace window
# after the snapshot directory changed (rotation/cleanup time).
ROTATION_GRACE_HOURS=12

SENDMAIL="/usr/sbin/sendmail"

# ------------ PREP ------------------------------------------------------
mkdir -p "$STATE_DIR"
chmod 700 "$STATE_DIR" 2>/dev/null || true
mkdir -p "$(dirname "$LOG_FILE")" 2>/dev/null || true

SKIP_SNAP=false
SKIP_HESTIA=false
SKIP_MICRO=false
if is_excluded "$SNAP_DIR"; then
  SKIP_SNAP=true
fi
if is_excluded "$HESTIA_DIR"; then
  SKIP_HESTIA=true
fi
if is_excluded "$MICRO_DIR"; then
  SKIP_MICRO=true
fi

PREV_SNAP_SIZE=0
PREV_HESTIA_SIZE=0
PREV_MICRO_SIZE=0
PREV_HESTIA_NAME=""
PREV_MICRO_NAME=""
PREV_SNAP_MTIME=0

if [ -f "$STATE_FILE" ]; then
  # shellcheck disable=SC1090
  . "$STATE_FILE"
fi

STATUS="OK"
ISSUES=()

log() {
  local ts
  ts="$(date '+%Y-%m-%d %H:%M:%S')"
  printf '[%s] %s\n' "$ts" "$*" >> "$LOG_FILE"
}

# ------------ HELPER: SIZE ----------------------------------------------
get_size_bytes() {
  local path="$1"
  if [ -e "$path" ]; then
    du -sb "$path" 2>/dev/null | awk '{print $1}'
  else
    echo 0
  fi
}

# Return mtime (epoch seconds) or 0
get_mtime() {
  local path="$1"
  stat -c %Y "$path" 2>/dev/null || echo 0
}

check_shrink() {
  local label="$1"
  local old_size="$2"
  local new_size="$3"
  local extra="$4"

  # need a valid previous and current size
  if [ "$old_size" -le 0 ] || [ "$new_size" -le 0 ]; then
    return 0
  fi

  # no shrink or growth
  if [ "$new_size" -ge "$old_size" ]; then
    return 0
  fi

  local diff=$(( old_size - new_size ))

  # below absolute threshold? ignore
  if [ "$diff" -lt "$MIN_SHRINK_BYTES" ]; then
    return 0
  fi

  local pct=$(( diff * 100 / old_size ))

  if [ "$pct" -lt "$MIN_SHRINK_PCT" ]; then
    return 0
  fi

  STATUS="CRIT"
  ISSUES+=("$label shrank from ${old_size}B to ${new_size}B (drop ${pct}%) ${extra}")
}

# ------------ CHECK 1: MOUNT HEALTH -------------------------------------
if ! mountpoint -q "$BACKUP_ROOT"; then
  STATUS="CRIT"
  ISSUES+=("Backup root $BACKUP_ROOT is NOT a mounted filesystem. Snapshots/backup writes may be going to the root disk.")
fi

# ------------ CHECK 2: SNAPSHOT PRESENCE + (OPTIONAL) SHRINK -------------
SNAP_SIZE=0
SNAP_MTIME=0

if [ "$SKIP_SNAP" = false ]; then
  if [ -d "$SNAP_PATH" ]; then
    SNAP_SIZE="$(get_size_bytes "$SNAP_PATH")"
    SNAP_MTIME="$(get_mtime "$SNAP_PATH")"
  else
    STATUS="CRIT"
    ISSUES+=("Snapshot path $SNAP_PATH does not exist.")
  fi
fi

# Only shrink-check snapshots if explicitly enabled, and not during rotation window.
if [ "${CHECK_SNAPSHOT_SHRINK:-0}" -eq 1 ] && [ "$SNAP_SIZE" -gt 0 ] && [ "$PREV_SNAP_SIZE" -gt 0 ]; then
  now_epoch="$(date +%s)"
  grace_sec=$((ROTATION_GRACE_HOURS * 3600))

  # If snapshot mtime moved recently (rotation/cleanup), ignore shrink.
  if [ "$SNAP_MTIME" -gt 0 ] && [ $((now_epoch - SNAP_MTIME)) -le "$grace_sec" ]; then
    :
  # If mtime changed since last run, treat as new baseline (avoids false positives on rotation)
  elif [ "$PREV_SNAP_MTIME" -gt 0 ] && [ "$SNAP_MTIME" -ne "$PREV_SNAP_MTIME" ]; then
    :
  else
    check_shrink "Snapshot daily.0 ($SNAP_PATH)" "$PREV_SNAP_SIZE" "$SNAP_SIZE" "(possible deletion/rotation/tamper)"
  fi
fi

# ------------ CHECK 3: LATEST HESTIA BACKUP SIZE -------------------------
LATEST_HESTIA=""
if [ "$SKIP_HESTIA" = false ]; then
  if [ -d "$HESTIA_DIR" ]; then
    LATEST_HESTIA="$(ls -1t "$HESTIA_DIR" 2>/dev/null | head -n1 || true)"
  fi
fi

HESTIA_SIZE=0
if [ "$SKIP_HESTIA" = false ]; then
  if [ -n "$LATEST_HESTIA" ]; then
    HESTIA_SIZE="$(get_size_bytes "$HESTIA_DIR/$LATEST_HESTIA")"
    # if file changed name, treat as new baseline (avoids false positives on rotation)
    if [ "$LATEST_HESTIA" = "$PREV_HESTIA_NAME" ]; then
      check_shrink "Hestia backup ($LATEST_HESTIA)" "$PREV_HESTIA_SIZE" "$HESTIA_SIZE" "(file: $HESTIA_DIR/$LATEST_HESTIA)"
    fi
  else
    STATUS="WARN"
    ISSUES+=("No Hestia backups found in $HESTIA_DIR.")
  fi
fi

# ------------ CHECK 4: LATEST MICRO BACKUP SIZE --------------------------
LATEST_MICRO=""
if [ "$SKIP_MICRO" = false ]; then
  if [ -d "$MICRO_DIR" ]; then
    # Prefer newest by mtime, not lexicographic sort
    LATEST_MICRO="$(find "$MICRO_DIR" -maxdepth 1 -mindepth 1 -type d -printf '%T@ %p\n' 2>/dev/null | sort -nr | head -n1 | awk '{print $2}' || true)"
  fi
fi

MICRO_SIZE=0
LATEST_MICRO_BASENAME=""
if [ "$SKIP_MICRO" = false ]; then
  if [ -n "$LATEST_MICRO" ]; then
    LATEST_MICRO_BASENAME="$(basename "$LATEST_MICRO")"
    MICRO_SIZE="$(get_size_bytes "$LATEST_MICRO")"
    if [ "$LATEST_MICRO_BASENAME" = "$PREV_MICRO_NAME" ]; then
      check_shrink "Micro backup ($LATEST_MICRO_BASENAME)" "$PREV_MICRO_SIZE" "$MICRO_SIZE" "(dir: $LATEST_MICRO)"
    fi
  else
    STATUS="WARN"
    ISSUES+=("No micro backups found in $MICRO_DIR.")
  fi
fi

# ------------ UPDATE STATE FILE -----------------------------------------
cat > "$STATE_FILE" <<EOF
PREV_SNAP_SIZE=$SNAP_SIZE
PREV_SNAP_MTIME=$SNAP_MTIME
PREV_HESTIA_SIZE=$HESTIA_SIZE
PREV_MICRO_SIZE=$MICRO_SIZE
PREV_HESTIA_NAME="$LATEST_HESTIA"
PREV_MICRO_NAME="$LATEST_MICRO_BASENAME"
EOF

chmod 600 "$STATE_FILE" 2>/dev/null || true

NOW_HUMAN="$(date '+%Y-%m-%d %H:%M:%S')"

# ------------ DECIDE WHETHER TO ALERT -----------------------------------
if [ "${#ISSUES[@]}" -eq 0 ]; then
  log "STATUS=OK snap=${SNAP_SIZE}B hestia=${HESTIA_SIZE}B micro=${MICRO_SIZE}B"
  exit 0
fi

log "STATUS=$STATUS $(printf '%s; ' "${ISSUES[@]}")"

# If sendmail is missing, don't blow up the script; just log and exit
if [ ! -x "$SENDMAIL" ]; then
  log "WARN: sendmail binary $SENDMAIL not found or not executable; skipping email alert."
  exit 0
fi

# ------------ BUILD HTML EMAIL ------------------------------------------
HTML=$(cat <<EOF
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Backup Integrity Alert - $HOSTNAME</title>
<style>
  body { font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background:#050712; color:#e5e7eb; padding:20px; }
  .title { font-size:20px; font-weight:600; margin-bottom:4px; }
  .subtitle { font-size:12px; color:#9ca3af; margin-bottom:16px; }
  .status-pill { display:inline-flex; align-items:center; gap:6px; padding:4px 10px; border-radius:999px;
                 font-size:11px; font-weight:500; background:#111827; border:1px solid #1f2937; margin-bottom:16px; }
  .ok { color:#22c55e; }
  .warn { color:#facc15; }
  .crit { color:#f97373; }
  .section-title { font-size:13px; font-weight:500; margin:12px 0 4px; }
  .block { font-size:12px; background:#020617; border-radius:8px; border:1px solid #111827; padding:8px 10px; white-space:pre-wrap; }
  code { font-size:12px; }
  small { color:#6b7280; }
</style>
</head>
<body>
<div class="title">Backup Integrity Alert</div>
<div class="subtitle">Host <b>$HOSTNAME</b> · $NOW_HUMAN</div>
<div class="status-pill">
  <span>Status:</span>
EOF
)

STATUS_CLASS="ok"
if [ "$STATUS" = "WARN" ]; then
  STATUS_CLASS="warn"
elif [ "$STATUS" = "CRIT" ]; then
  STATUS_CLASS="crit"
fi

HTML+=$'\n'"  <span class=\"$STATUS_CLASS\">$STATUS</span>"
HTML+=$'\n'"</div>"

HTML+=$'\n'"<div class=\"section-title\">Issues detected</div>"
HTML+=$'\n'"<div class=\"block\">"
for msg in "${ISSUES[@]}"; do
  HTML+="$msg"$'\n'
done
HTML+="</div>"

HTML+=$'\n'"<div class=\"section-title\">Current sizes</div>"
HTML+=$'\n'"<div class=\"block\">Snapshot daily.0: ${SNAP_SIZE} bytes
Hestia latest:   ${HESTIA_SIZE} bytes (${LATEST_HESTIA:-none})
Micro latest:    ${MICRO_SIZE} bytes (${LATEST_MICRO_BASENAME:-none})</div>"

HTML+=$'\n'"<div style=\"margin-top:12px;\"><small>This alert was generated by <code>backup_integrity_watch.sh</code> on $HOSTNAME.</small></div>"
HTML+=$'\n'"</body></html>"

SUBJECT="[$HOSTNAME] Backup integrity alert: $STATUS"

if ! {
  echo "Subject: $SUBJECT"
  echo "To: $EMAIL_TO"
  echo "From: backup@$HOSTNAME"
  echo "MIME-Version: 1.0"
  echo "Content-Type: text/html; charset=UTF-8"
  echo
  printf "%s" "$HTML"
} | "$SENDMAIL" -t; then
  log "ERROR: failed to send integrity alert via $SENDMAIL"
  exit 1
fi

exit 0
