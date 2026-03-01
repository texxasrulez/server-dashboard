#!/usr/bin/env bash
set -euo pipefail

# --------------------------------------------------------------------
# Environment
# --------------------------------------------------------------------
PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"
export PATH
umask 027

# --------------------------------------------------------------------
# Concurrency guard (avoid overlapping du runs)
# --------------------------------------------------------------------
LOCK_FILE=""
if command -v flock >/dev/null 2>&1; then
  for dir in /run/lock/backup-suite /tmp; do
    if mkdir -p "$dir" 2>/dev/null; then
      chmod 1777 "$dir" 2>/dev/null || true
      LOCK_FILE="${dir%/}/backup_health_check.${UID}.lock"
      if exec 9>"$LOCK_FILE"; then
        if ! flock -n 9; then
          echo "backup_health_check: already running; exiting." >&2
          exit 0
        fi
        break
      fi
    fi
  done
  if [ -z "$LOCK_FILE" ]; then
    echo "backup_health_check: could not create lock file (permission denied)." >&2
    exit 1
  fi
else
  LOCK_FILE="/tmp/backup_health_check.${UID}.lock"
  if ! mkdir "$LOCK_FILE" 2>/dev/null; then
    echo "backup_health_check: already running; exiting." >&2
    exit 0
  fi
  trap 'rmdir "$LOCK_FILE"' EXIT
fi

# Throttle expensive size scans (seconds)
SIZE_TTL_SEC="${BACKUP_SIZE_TTL_SEC:-1800}"
if ! [[ "$SIZE_TTL_SEC" =~ ^[0-9]+$ ]]; then
  SIZE_TTL_SEC=1800
fi

# Exclude handling for health check (space/comma/newline-separated)
# Default: do NOT apply backup excludes so monitoring still reflects reality.
EXCLUDES_RAW="${BACKUP_HEALTH_EXCLUDES:-}"
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

# Require jq
if ! command -v jq >/dev/null 2>&1; then
  echo "ERROR: jq is required but not installed or not in PATH." >&2
  exit 1
fi

# --------------------------------------------------------------------
# Paths
# --------------------------------------------------------------------
BACKUP_ROOT="${BACKUP_ROOT:-/mnt/backupz}"

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
OUT_JSON="${STATE_DIR}/backup_status.json"
OUT_HTML="${STATE_DIR}/backup_status.html"   # may be produced by another script

mkdir -p "$STATE_DIR"

CONFIG_FILE="${WEB_ADMIN_ROOT}/config/local.json"
BACKUP_DEBUG="${BACKUP_DEBUG:-}"
if [ -f "$CONFIG_FILE" ]; then
  cfg_root="$(jq -r '.backups.fs_root // empty' "$CONFIG_FILE" 2>/dev/null || true)"
  if [ -n "$cfg_root" ]; then
    BACKUP_ROOT="$cfg_root"
  fi
  if [ -z "${BACKUP_EXCLUDES:-}" ]; then
    cfg_excludes="$(jq -r '.backups.exclude_dirs // empty' "$CONFIG_FILE" 2>/dev/null || true)"
    if [ -n "$cfg_excludes" ]; then
      BACKUP_EXCLUDES="$cfg_excludes"
      export BACKUP_EXCLUDES
    fi
  fi
  if [ -z "$BACKUP_DEBUG" ]; then
    BACKUP_DEBUG="$(jq -r '.backups.debug // empty' "$CONFIG_FILE" 2>/dev/null || true)"
  fi
fi

SNAP_DIR="${BACKUP_ROOT}/snapshots"
HESTIA_DIR="${BACKUP_ROOT}/hestia"
MICRO_DIR="${BACKUP_ROOT}/micro"
if [[ "$BACKUP_DEBUG" == "1" || "$BACKUP_DEBUG" == "true" ]]; then
  echo "[DEBUG] BACKUP_ROOT=${BACKUP_ROOT}"
  echo "[DEBUG] BACKUP_EXCLUDES=${BACKUP_EXCLUDES:-}"
fi
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

ACTION_NAME="health_check"
ACTION_MESSAGE="Backup health check complete"
finish_action() {
  local code=$?
  local ok=true
  local msg="$ACTION_MESSAGE"
  if [ "$code" -ne 0 ]; then
    ok=false
    msg="Health check failed with exit ${code}"
  fi
  log_action_json "$ACTION_NAME" "$ok" "$msg"
}
trap finish_action EXIT

# Keep config/backups under control (history dumps + config/security snapshots).
PRUNE_CFG_SCRIPT="${SCRIPT_DIR}/prune_config_backups.sh"
if [ -f "$PRUNE_CFG_SCRIPT" ]; then
  if ! bash "$PRUNE_CFG_SCRIPT" >/dev/null 2>&1; then
    add_warn "Config backup prune task failed (${PRUNE_CFG_SCRIPT})."
  fi
fi

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

# --------------------------------------------------------------------
# Helpers
# --------------------------------------------------------------------
warnings=()
errors=()

add_warn() { warnings+=("$1"); }
add_err()  { errors+=("$1"); }

now_epoch="$(date +%s)"

lowprio_cmd() {
  local cmd=("$@")
  if command -v ionice >/dev/null 2>&1; then
    cmd=(ionice -c2 -n7 "${cmd[@]}")
  fi
  if command -v nice >/dev/null 2>&1; then
    cmd=(nice -n 10 "${cmd[@]}")
  fi
  "${cmd[@]}"
}

du_size_gb() {
  local path="$1"
  { lowprio_cmd du -BG -s "$path" 2>/dev/null || true; } | awk '{gsub("G","",$1); print $1}'
}

# --------------------------------------------------------------------
# Disk info (overall usage)
# --------------------------------------------------------------------
disk_df_raw="$(df -h "$BACKUP_ROOT" 2>&1 || true)"
disk_usage_pct=0

if df_line="$(echo "$disk_df_raw" | awk 'NR==2')" && [ -n "$df_line" ]; then
  usage_field="$(echo "$df_line" | awk '{print $5}')"
  # usage_field like "23%" → strip %
  disk_usage_pct="${usage_field%%%}"
  # if parsing failed somehow, fall back to 0 and warn
  if ! [[ "$disk_usage_pct" =~ ^[0-9]+$ ]]; then
    disk_usage_pct=0
    add_err "Could not parse numeric disk usage for ${BACKUP_ROOT}."
  fi
else
  add_err "Could not parse disk usage for ${BACKUP_ROOT}."
fi

# --------------------------------------------------------------------
# Disk total size in GB (for folder breakdown)
# --------------------------------------------------------------------
disk_total_gb=0
if df_gb_line="$(df -BG "$BACKUP_ROOT" 2>/dev/null | awk 'NR==2')" && [ -n "$df_gb_line" ]; then
  disk_total_field="$(echo "$df_gb_line" | awk '{print $2}')"
  # field like "931G" → strip "G"
  disk_total_gb="${disk_total_field%G}"
  if ! [[ "$disk_total_gb" =~ ^[0-9]+$ ]]; then
    disk_total_gb=0
  fi
fi

# --------------------------------------------------------------------
# Folder sizes caching (avoid du every run)
# --------------------------------------------------------------------
size_refresh_needed=true
size_updated_ts=0
size_updated_iso=""
cached_snap_size_gb=""
cached_hestia_size_gb=""
cached_micro_size_gb=""

if [ -f "$OUT_JSON" ]; then
  size_updated_ts="$(jq -r '.disk.sizes_updated_ts // 0' "$OUT_JSON" 2>/dev/null || echo 0)"
  cached_snap_size_gb="$(jq -r '[.disk.folders[]? | select(.label=="Snapshots" or .label=="/snapshots") | .size_gb][0] // empty' "$OUT_JSON" 2>/dev/null)"
  cached_hestia_size_gb="$(jq -r '[.disk.folders[]? | select(.label=="Hestia" or .label=="/hestia") | .size_gb][0] // empty' "$OUT_JSON" 2>/dev/null)"
  cached_micro_size_gb="$(jq -r '[.disk.folders[]? | select(.label=="Micro" or .label=="/micro") | .size_gb][0] // empty' "$OUT_JSON" 2>/dev/null)"
fi

if [[ "$size_updated_ts" =~ ^[0-9]+$ ]] && [ "$size_updated_ts" -gt 0 ]; then
  size_age_sec=$(( now_epoch - size_updated_ts ))
  if [ "$size_age_sec" -ge 0 ] && [ "$size_age_sec" -lt "$SIZE_TTL_SEC" ]; then
    size_refresh_needed=false
    size_updated_iso="$(date -Iseconds -d "@$size_updated_ts" 2>/dev/null || true)"
  fi
fi

# --------------------------------------------------------------------
# Auto-mount detection for /backup
# --------------------------------------------------------------------
backup_mount_ok=true

# We consider it "mounted" only if findmnt shows a dedicated mount for BACKUP_ROOT.
if ! findmnt -rn "$BACKUP_ROOT" >/dev/null 2>&1; then
  backup_mount_ok=false
  add_err "Backup root ${BACKUP_ROOT} does not appear as a dedicated mount (findmnt)."
fi

# JSON wants a literal true/false, not "true"/"false" strings.
backup_mount_ok_json=true
if [ "$backup_mount_ok" = false ]; then
  backup_mount_ok_json=false
fi

# --------------------------------------------------------------------
# Snapshots: use completion marker (fallback latest dir, then daily.0)
# --------------------------------------------------------------------
snap_age_days=null
snap_latest_path=""
if [ "$SKIP_SNAP" = false ]; then
  snap_marker="$SNAP_DIR/daily.0/.snapshot_complete"
  snap_latest_path="$({ find "$SNAP_DIR" -mindepth 1 -maxdepth 1 -type d \
    ! -name 'lost+found' -printf '%T@ %p\n' 2>/dev/null || true; } \
    | sort -n | tail -n1 | cut -d' ' -f2-)"

  if [ -f "$snap_marker" ]; then
    mtime="$(stat -c %Y "$snap_marker")"
    delta=$(( now_epoch - mtime ))
    if [ "$delta" -lt 0 ]; then
      snap_age_days=0
    else
      snap_age_days=$(( delta / 86400 ))
    fi
  elif [ -n "$snap_latest_path" ] && [ -e "$snap_latest_path" ]; then
    mtime="$(stat -c %Y "$snap_latest_path")"
    delta=$(( now_epoch - mtime ))
    if [ "$delta" -lt 0 ]; then
      snap_age_days=0
    else
      snap_age_days=$(( delta / 86400 ))
    fi
  elif [ -d "$SNAP_DIR/daily.0" ]; then
    mtime="$(stat -c %Y "$SNAP_DIR/daily.0")"
    delta=$(( now_epoch - mtime ))
    if [ "$delta" -lt 0 ]; then
      snap_age_days=0
    else
      snap_age_days=$(( delta / 86400 ))
    fi
  else
    add_warn "No snapshot daily.0 found in ${SNAP_DIR}."
  fi
fi

# Snapshot size in GB (for donut)
snap_size_gb=0
if [ "$SKIP_SNAP" = false ] && [ -d "$SNAP_DIR" ]; then
  if [ "$size_refresh_needed" = true ]; then
    snap_size_gb="$(du_size_gb "$SNAP_DIR")"
  else
    snap_size_gb="${cached_snap_size_gb:-0}"
  fi
  snap_size_gb="${snap_size_gb:-0}"
  if ! [[ "$snap_size_gb" =~ ^[0-9]+$ ]]; then
    snap_size_gb=0
  fi
fi

# --------------------------------------------------------------------
# Hestia backups: newest backup age (any user .tar* under hestia)
# --------------------------------------------------------------------
hestia_age_days=null
hestia_latest_file=""
hestia_latest_name=""

if [ "$SKIP_HESTIA" = false ]; then
  if [ -d "$HESTIA_DIR" ]; then
    hestia_latest_file="$(
      { find "$HESTIA_DIR" -type f -name '*.tar*' -printf '%T@ %p\n' 2>/dev/null || true; } \
        | sort -n | tail -n1 | cut -d' ' -f2-
    )"

    if [ -n "$hestia_latest_file" ] && [ -e "$hestia_latest_file" ]; then
      mtime="$(stat -c %Y "$hestia_latest_file")"
      delta=$(( now_epoch - mtime ))
      if [ "$delta" -lt 0 ]; then
        hestia_age_days=0
      else
        hestia_age_days=$(( delta / 86400 ))
      fi
      hestia_latest_name="$(basename "$hestia_latest_file")"
    else
      add_warn "No Hestia backups found in ${HESTIA_DIR}."
    fi
  else
    add_warn "Hestia backup directory ${HESTIA_DIR} does not exist."
  fi
fi

# Hestia total size in GB (for donut)
hestia_size_gb=0
if [ "$SKIP_HESTIA" = false ] && [ -d "$HESTIA_DIR" ]; then
  if [ "$size_refresh_needed" = true ]; then
    hestia_size_gb="$(du_size_gb "$HESTIA_DIR")"
  else
    hestia_size_gb="${cached_hestia_size_gb:-0}"
  fi
  hestia_size_gb="${hestia_size_gb:-0}"
  if ! [[ "$hestia_size_gb" =~ ^[0-9]+$ ]]; then
    hestia_size_gb=0
  fi
fi

# --------------------------------------------------------------------
# Micro backups: newest entry (file OR dir) age
# --------------------------------------------------------------------
micro_age_days=null
micro_latest_path=""
micro_entries_count=null

if [ "$SKIP_MICRO" = false ]; then
  micro_entries_count=0
  if [ -d "$MICRO_DIR" ]; then
    micro_entries_count="$(
      { find "$MICRO_DIR" -mindepth 1 -maxdepth 1 \
          ! -name 'lost+found' \
          -printf '.' 2>/dev/null || true; } \
        | wc -c | awk '{print $1}'
    )"
    micro_entries_count="${micro_entries_count:-0}"

    if [ "$micro_entries_count" -gt 0 ]; then
      micro_latest_path="$(
        { find "$MICRO_DIR" -mindepth 1 -maxdepth 1 \
            \( -type f -o -type d \) \
            ! -name 'lost+found' \
            -printf '%T@ %p\n' 2>/dev/null || true; } \
          | sort -n | tail -n1 | cut -d' ' -f2-
      )"

      if [ -n "$micro_latest_path" ] && [ -e "$micro_latest_path" ]; then
        mtime="$(stat -c %Y "$micro_latest_path")"
        delta=$(( now_epoch - mtime ))
        if [ "$delta" -lt 0 ]; then
          micro_age_days=0
        else
          micro_age_days=$(( delta / 86400 ))
        fi
      else
        add_warn "Micro dir ${MICRO_DIR} has entries but stat() failed for latest; check permissions."
      fi
    else
      add_warn "No micro backups found in ${MICRO_DIR}."
    fi
  else
    add_warn "Micro backup directory ${MICRO_DIR} does not exist."
  fi
fi

# Micro total size in GB (for donut)
micro_size_gb=0
if [ "$SKIP_MICRO" = false ] && [ -d "$MICRO_DIR" ]; then
  if [ "$size_refresh_needed" = true ]; then
    micro_size_gb="$(du_size_gb "$MICRO_DIR")"
  else
    micro_size_gb="${cached_micro_size_gb:-0}"
  fi
  micro_size_gb="${micro_size_gb:-0}"
  if ! [[ "$micro_size_gb" =~ ^[0-9]+$ ]]; then
    micro_size_gb=0
  fi
fi

if [ "$size_refresh_needed" = true ]; then
  size_updated_ts="$now_epoch"
  size_updated_iso="$(date -Iseconds)"
fi

if [ -z "$size_updated_iso" ]; then
  size_updated_iso="$(date -Iseconds -d "@$size_updated_ts" 2>/dev/null || date -Iseconds)"
fi

# --------------------------------------------------------------------
# Overall status
# --------------------------------------------------------------------
status="OK"

# disk thresholds
if [ "$disk_usage_pct" -ge 95 ]; then
  status="CRIT"
  add_err "Backup disk usage is ${disk_usage_pct}% (>=95%)."
elif [ "$disk_usage_pct" -ge 90 ]; then
  [ "$status" = "OK" ] && status="WARN"
  add_warn "Backup disk usage is ${disk_usage_pct}% (>=90%)."
elif [ "$disk_usage_pct" -ge 80 ]; then
  [ "$status" = "OK" ] && status="WARN"
  add_warn "Backup disk usage is ${disk_usage_pct}% (>=80%)."
fi

# age thresholds: 0–1 OK, 2–3 WARN, >=4 CRIT
age_check() {
  local label="$1"
  local age="$2"

  if [ "$age" = "null" ]; then
    return 0
  fi

  if [ "$age" -ge 4 ]; then
    status="CRIT"
    add_err "${label} backup age is ${age} days (>=4)."
  elif [ "$age" -ge 2 ]; then
    [ "$status" = "OK" ] && status="WARN"
    add_warn "${label} backup age is ${age} days (>=2)."
  fi
}

age_check "Snapshot daily.0" "$snap_age_days"
age_check "Hestia latest" "$hestia_age_days"
age_check "Micro latest" "$micro_age_days"

# If the backup disk is not a dedicated mount, that's always critical.
if [ "$backup_mount_ok" = false ]; then
  status="CRIT"
fi

# --------------------------------------------------------------------
# Build JSON arrays for warnings/errors
# --------------------------------------------------------------------
warn_json="[]"
err_json="[]"

if [ "${#warnings[@]}" -gt 0 ]; then
  warn_json="$(
    printf '%s\n' "${warnings[@]}" | jq -R . | jq -s .
  )"
fi

if [ "${#errors[@]}" -gt 0 ]; then
  err_json="$(
    printf '%s\n' "${errors[@]}" | jq -R . | jq -s .
  )"
fi

# --------------------------------------------------------------------
# Write JSON atomically
# --------------------------------------------------------------------
tmp_json="$(mktemp "${OUT_JSON}.tmp.XXXXXX")"

jq -n \
  --arg status "$status" \
  --arg ts "$(date -Iseconds)" \
  --arg df "$disk_df_raw" \
  --argjson usage "$disk_usage_pct" \
  --argjson total_gb "$disk_total_gb" \
  --argjson size_updated_ts "$size_updated_ts" \
  --arg size_updated "$size_updated_iso" \
  --argjson snap_age "${snap_age_days}" \
  --argjson hestia_age "${hestia_age_days}" \
  --argjson micro_age "${micro_age_days}" \
  --arg micro_latest "${micro_latest_path:-}" \
  --argjson micro_count "$micro_entries_count" \
  --arg hestia_latest_name "${hestia_latest_name:-}" \
  --argjson mount_ok "$backup_mount_ok_json" \
  --argjson snap_size "$snap_size_gb" \
  --argjson hestia_size "$hestia_size_gb" \
  --argjson micro_size "$micro_size_gb" \
  --argjson warns "$warn_json" \
  --argjson errs "$err_json" '
{
  "timestamp": $ts,
  "status": $status,
  "backup_mount_ok": $mount_ok,
  "disk": {
    "usage_percent": $usage,
    "df": $df,
    "total_gb": $total_gb,
    "sizes_updated_ts": $size_updated_ts,
    "sizes_updated": $size_updated,
    "folders": [
      { "label": "Snapshots", "size_gb": $snap_size },
      { "label": "Hestia",    "size_gb": $hestia_size },
      { "label": "Micro",     "size_gb": $micro_size }
    ]
  },
  "snapshots": {
    "daily0_age_days": $snap_age
  },
  "hestia": {
    "latest_age_days": $hestia_age,
    "latest_backup_name": $hestia_latest_name
  },
  "micro": {
    "latest_age_days": $micro_age,
    "latest_path": $micro_latest,
    "entries_count": $micro_count
  },
  "warnings": $warns,
  "errors": $errs
}
' > "$tmp_json"

mv "$tmp_json" "$OUT_JSON"

# --------------------------------------------------------------------
# Fix ownership/permissions so web can always read it
# --------------------------------------------------------------------
if [ -e "$OUT_JSON" ]; then
  chown "$STATE_OWNER" "$OUT_JSON" 2>/dev/null || true
  chmod 644 "$OUT_JSON" 2>/dev/null || true
fi

# If an HTML status file exists (generated elsewhere), fix it too
if [ -e "$OUT_HTML" ]; then
  chown "$STATE_OWNER" "$OUT_HTML" 2>/dev/null || true
  chmod 644 "$OUT_HTML" 2>/dev/null || true
fi

exit 0
