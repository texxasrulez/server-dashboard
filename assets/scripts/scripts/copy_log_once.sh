#!/usr/bin/env bash
set -euo pipefail

# Load env (single canonical path; no systemctl-escape dependency)
ENV_FILE="/etc/log-watcher/log-watcher.env"
if [[ -f "${ENV_FILE}" ]]; then
  # shellcheck disable=SC1090
  source "${ENV_FILE}"
fi

# Source root and match glob
SRC_DIR="${SRC_DIR:-/var/log}"
MATCH_GLOB="${MATCH_GLOB:-*.log}"

DEST_DIR="${DEST_DIR:-/var/log-export}"
OWNER="${OWNER:-root:root}"
MODE="${MODE:-0640}"

USER_NAME="${OWNER%%:*}"
GROUP_NAME="${OWNER##*:}"

mkdir -p -- "${DEST_DIR}"

copy_one() {
  local file="$1"

  # Only regular files
  [[ -f "$file" ]] || return 0

  # Apply simple glob filter on basename
  local base
  base="$(basename -- "$file")"

  # Skip compressed backups regardless of glob
  case "$base" in
    *.gz|*.xz|*.bz2|*.zip|*.zst|*.lz4|*.Z) return 0 ;;
  esac

  # If MATCH_GLOB is set, enforce it; default "*" means everything
  if [[ -n "${MATCH_GLOB:-}" && "${MATCH_GLOB}" != "*" ]]; then
    if ! [[ "$base" == $MATCH_GLOB ]]; then
      return 0
    fi
  fi

  # Empty files are ignored
  if [[ ! -s "$file" ]]; then
    return 0
  fi

  # Derive flattened output name: parentdir_basename
  local parent out tmp
  parent="$(basename -- "$(dirname -- "$file")")"
  out="${DEST_DIR}/${parent}_${base}"
  tmp="${out}.tmp.$$"

  # If destination missing, older, or different size -> copy
  local src_size dst_size
  src_size="$(stat -c%s -- "$file" 2>/dev/null || echo 0)"
  dst_size="$(stat -c%s -- "$out" 2>/dev/null || echo 0)"

  if [[ ! -e "$out" || "$file" -nt "$out" || "$src_size" != "$dst_size" ]]; then
    cp --preserve=timestamps --dereference -- "$file" "$tmp"
    if [[ "$(id -u)" -eq 0 ]]; then
      chown "${USER_NAME}:${GROUP_NAME}" "$tmp" || echo "WARN: chown failed for $tmp to ${USER_NAME}:${GROUP_NAME}" >&2
    fi
    chmod "${MODE}" "$tmp" || true
    mv -f -- "$tmp" "$out"
  fi
}

export SRC_DIR MATCH_GLOB DEST_DIR OWNER MODE USER_NAME GROUP_NAME

# Walk SRC_DIR once and copy all matching files
find "${SRC_DIR}" -type f -print0 | while IFS= read -r -d '' f; do
  copy_one "$f"
done
