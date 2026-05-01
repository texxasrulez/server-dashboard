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

copy_if_needed() {
  local src="$1"

  # Only regular files
  [[ -f "$src" ]] || return 0

  # Enforce that it's inside SRC_DIR
  case "$src" in
    "${SRC_DIR}"/*) ;;
    *) return 0 ;;
  esac

  # Apply simple glob filter on basename
  local base
  base="$(basename -- "$src")"

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

  # Ignore empty files
  if [[ ! -s "$src" ]]; then
    return 0
  fi

  local parent out tmp
  parent="$(basename -- "$(dirname -- "$src")")"
  out="${DEST_DIR}/${parent}_${base}"
  tmp="${out}.tmp.$$"

  # Only copy if destination missing or older/different-size
  local src_size dst_size
  src_size="$(stat -c%s -- "$src" 2>/dev/null || echo 0)"
  dst_size="$(stat -c%s -- "$out" 2>/dev/null || echo 0)"

  if [[ ! -e "$out" || "$src" -nt "$out" || "$src_size" != "$dst_size" ]]; then
    cp --preserve=timestamps --dereference -- "$src" "$tmp" || return 0
    if [[ "$(id -u)" -eq 0 ]]; then
      chown "${USER_NAME}:${GROUP_NAME}" "$tmp" 2>/dev/null || echo "WARN: chown failed for $tmp to ${USER_NAME}:${GROUP_NAME}" >&2
    fi
    chmod "${MODE}" "$tmp" || true
    mv -f -- "$tmp" "$out"
  fi
}

# Need inotifywait
if ! command -v inotifywait >/dev/null 2>&1; then
  echo "ERROR: inotifywait not found. Install inotify-tools." >&2
  exit 1
fi

# Initial sync so DEST_DIR is populated
"/usr/local/bin/copy_log_once.sh" || true

# Watch for changes under SRC_DIR
inotifywait -m -r "${SRC_DIR}" --event close_write,create,moved_to --format '%w%f' |
while IFS= read -r path; do
  copy_if_needed "$path"
done
