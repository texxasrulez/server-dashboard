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
  if [[ ! -e "$out" || "$file" -nt "$out" ]]; then
    tmp="${out}.tmp.$$"
    cp --preserve=timestamps --dereference "$file" "$tmp"
    if ! if [[ "$(id -u)" -eq 0 ]]; then chown "${USER_NAME}:${GROUP_NAME}" "$tmp"; fi 2>/dev/null; then echo "WARN: chown failed for $tmp to ${USER_NAME}:${GROUP_NAME}" >&2; fi
    chmod "${MODE}" "$tmp"
    mv -f "$tmp" "$out"
  fi
done < <(find /var/log -type f -name '*.log' -print0)
