#!/usr/bin/env bash
set -euo pipefail

# Prunes backup artifacts under config/backups:
#  1) services_status_history_YYYY-MM-DD.jsonl -> keep N days by filename date
#  2) config-*.json / security-*.json -> keep latest K files each
#  3) local.pre-rotate-YYYYMMDD-HHMMSS.json -> keep N days by filename date

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
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
if [ -n "${WEB_ADMIN_ROOT:-}" ]; then
  WEB_ADMIN_ROOT="$WEB_ADMIN_ROOT"
elif [ -f "$REPO_ROOT/config/local.json" ]; then
  WEB_ADMIN_ROOT="$REPO_ROOT"
else
  WEB_ADMIN_ROOT="$REPO_ROOT"
fi

BACKUP_DIR="${WEB_ADMIN_ROOT}/config/backups"
CONFIG_FILE="${WEB_ADMIN_ROOT}/config/local.json"

if [ ! -d "$BACKUP_DIR" ]; then
  echo "config-backups: no backup dir at ${BACKUP_DIR}; nothing to prune"
  exit 0
fi

HISTORY_KEEP_DAYS="${BACKUP_HISTORY_KEEP_DAYS:-30}"
ROTATE_KEEP_DAYS="${BACKUP_ROTATE_KEEP_DAYS:-30}"
KEEP_COUNT="${BACKUP_CONFIG_KEEP_COUNT:-}"

if ! [[ "$HISTORY_KEEP_DAYS" =~ ^[0-9]+$ ]]; then HISTORY_KEEP_DAYS=30; fi
if ! [[ "$ROTATE_KEEP_DAYS" =~ ^[0-9]+$ ]]; then ROTATE_KEEP_DAYS=30; fi

if [ -z "$KEEP_COUNT" ] && command -v jq >/dev/null 2>&1 && [ -f "$CONFIG_FILE" ]; then
  KEEP_COUNT="$(jq -r '.site.backup_keep // empty' "$CONFIG_FILE" 2>/dev/null || true)"
fi
if ! [[ "${KEEP_COUNT:-}" =~ ^[0-9]+$ ]]; then KEEP_COUNT=20; fi
if [ "$KEEP_COUNT" -lt 5 ]; then KEEP_COUNT=5; fi
if [ "$KEEP_COUNT" -gt 200 ]; then KEEP_COUNT=200; fi

cutoff_history_epoch="$(date -d "today -${HISTORY_KEEP_DAYS} days" +%s)"
cutoff_rotate_epoch="$(date -d "today -${ROTATE_KEEP_DAYS} days" +%s)"

pruned_history=0
pruned_rotate=0
pruned_config=0
pruned_security=0

shopt -s nullglob

for f in "$BACKUP_DIR"/services_status_history_*.jsonl; do
  base="$(basename "$f")"
  if [[ "$base" =~ ^services_status_history_([0-9]{4}-[0-9]{2}-[0-9]{2})\.jsonl$ ]]; then
    d="${BASH_REMATCH[1]}"
    ts="$(date -d "${d} 00:00:00" +%s 2>/dev/null || echo 0)"
    if [ "$ts" -gt 0 ] && [ "$ts" -lt "$cutoff_history_epoch" ]; then
      rm -f -- "$f"
      pruned_history=$((pruned_history + 1))
    fi
  fi
done

for f in "$BACKUP_DIR"/local.pre-rotate-*.json; do
  base="$(basename "$f")"
  if [[ "$base" =~ ^local\.pre-rotate-([0-9]{8})-([0-9]{6})\.json$ ]]; then
    d="${BASH_REMATCH[1]}"
    t="${BASH_REMATCH[2]}"
    iso="${d:0:4}-${d:4:2}-${d:6:2} ${t:0:2}:${t:2:2}:${t:4:2}"
    ts="$(date -d "$iso" +%s 2>/dev/null || echo 0)"
    if [ "$ts" -gt 0 ] && [ "$ts" -lt "$cutoff_rotate_epoch" ]; then
      rm -f -- "$f"
      pruned_rotate=$((pruned_rotate + 1))
    fi
  fi
done

prune_keep_count() {
  local pattern="$1"
  local keep="$2"
  local pruned=0
  mapfile -t files < <(find "$BACKUP_DIR" -maxdepth 1 -type f -name "$pattern" -printf '%T@ %p\n' | sort -nr | awk '{print $2}')
  local total="${#files[@]}"
  if [ "$total" -le "$keep" ]; then
    echo 0
    return 0
  fi
  for ((i=keep; i<total; i++)); do
    rm -f -- "${files[$i]}"
    pruned=$((pruned + 1))
  done
  echo "$pruned"
}

pruned_config="$(prune_keep_count 'config-*.json' "$KEEP_COUNT")"
pruned_security="$(prune_keep_count 'security-*.json' "$KEEP_COUNT")"

echo "config-backups: pruned history=${pruned_history} (> ${HISTORY_KEEP_DAYS}d), pre-rotate=${pruned_rotate} (> ${ROTATE_KEEP_DAYS}d), config=${pruned_config} (keep ${KEEP_COUNT}), security=${pruned_security} (keep ${KEEP_COUNT})"
