#!/usr/bin/env bash
set -euo pipefail

SERVICE_NAME="log-watcher"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --service-name)
      SERVICE_NAME="${2:-}"; shift 2;;
    -*)
      echo "Unknown option: $1" >&2; exit 2;;
    *)
      echo "Unexpected argument: $1" >&2; exit 2;;
  esac
done

if [[ $EUID -ne 0 ]]; then
  echo "This uninstaller must run as root." >&2
  exit 1
fi

UNIT_FILE="/etc/systemd/system/${SERVICE_NAME}.service"
ENV_FILE="/etc/log-watcher/${SERVICE_NAME}.env"
FALLBACK_ENV="/etc/log-watcher/log-watcher.env"
BIN_COPY="/usr/local/bin/copy_log_once.sh"
BIN_WATCH="/usr/local/bin/log_watcher.sh"

echo "[*] Stopping service (if running)"
systemctl disable --now "${SERVICE_NAME}.service" || true

echo "[*] Removing unit file"
rm -f "${UNIT_FILE}"
systemctl daemon-reload || true

echo "[*] Removing env files (keeping /etc/log-watcher dir)"
rm -f "${ENV_FILE}" "${FALLBACK_ENV}" || true

echo "[*] Removing installed binaries"
rm -f "${BIN_COPY}" "${BIN_WATCH}" || true

echo "[*] Uninstall complete. Destination directory and any copied logs were not removed."
