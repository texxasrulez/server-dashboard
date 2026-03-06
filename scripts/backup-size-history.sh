#!/usr/bin/env bash
set -euo pipefail

LOG="/var/log/backup-size-history.log"
TS="$(date '+%Y-%m-%d')"

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

{
  echo "===== BACKUP SIZE SNAPSHOT · $TS ====="
  for entry in /backup/*; do
    [ -e "$entry" ] || continue
    if is_excluded "$entry"; then
      continue
    fi
    du -sh "$entry" 2>/dev/null
  done | sort -h
  echo
} >> "$LOG"
