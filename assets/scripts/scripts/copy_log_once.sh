#!/usr/bin/env bash
set -euo pipefail

# Load env (single canonical path; no systemctl-escape dependency)
ENV_FILE="/etc/log-watcher/log-watcher.env"
if [[ -f "${ENV_FILE}" ]]; then
  # shellcheck disable=SC1090
  source "${ENV_FILE}"
fi

DEST_DIR="${DEST_DIR:-/var/log-export}"
OWNER="${OWNER:-root:root}"
MODE="${MODE:-0640}"

USER_NAME="${OWNER%%:*}"
GROUP_NAME="${OWNER##*:}"

mkdir -p "${DEST_DIR}"

# Copy all exact *.log files, skip empty, overwrite only if newer.
while IFS= read -r -d '' file; do
  [[ ! -s "$file" ]] && continue  # skip empty
  parent="$(basename "$(dirname "$file")")"
  base="$(basename "$file")"
  out="${DEST_DIR}/${parent}_${base}"

  src_size="$(stat -c%s "$file" 2>/dev/null || echo 0)"
  dst_size="$(stat -c%s "$out" 2>/dev/null || echo 0)"

  if [[ ! -e "$out" || "$file" -nt "$out" || "$src_size" != "$dst_size" ]]; then
    tmp="${out}.tmp.$$"
    cp --preserve=timestamps --dereference -- "$file" "$tmp"
    if [[ "$(id -u)" -eq 0 ]]; then
      chown "${USER_NAME}:${GROUP_NAME}" "$tmp" || echo "WARN: chown failed for $tmp to ${USER_NAME}:${GROUP_NAME}" >&2
    fi
    chmod "${MODE}" "$tmp"
    mv -f -- "$tmp" "$out"
  fi
done < <(find /var/log -type f -name '*.log' -print0)
