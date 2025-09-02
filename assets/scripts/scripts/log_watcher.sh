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

if ! command -v inotifywait >/dev/null 2>&1; then
  echo "inotifywait not found; install inotify-tools" >&2
  exit 1
fi

copy_if_needed() {
  local src="$1"
  [[ "${src}" != *.log ]] && return 0
  [[ ! -s "${src}" ]] && return 0
  local parent base out tmp
  parent="$(basename "$(dirname "${src}")")"
  base="$(basename "${src}")"
  out="${DEST_DIR}/${parent}_${base}"
  tmp="${out}.tmp.$$"
  if [[ ! -e "$out" || "$src" -nt "$out" ]]; then
    cp --preserve=timestamps --dereference "${src}" "${tmp}" || return 0
    if ! chown "${USER_NAME}:${GROUP_NAME}" "${tmp}" 2>/dev/null; then echo "WARN: chown failed for ${tmp} to ${USER_NAME}:${GROUP_NAME}" >&2; fi
    chmod "${MODE}" "${tmp}" || true
    mv -f "${tmp}" "${out}"
  fi
}

inotifywait -m -r /var/log --event close_write,create,moved_to --format '%w%f' |
while IFS= read -r path; do
  copy_if_needed "${path}"
done
