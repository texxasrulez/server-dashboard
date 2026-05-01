#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT_DIR="${1:-${ROOT}/screenshots}"
PORT="${SCREENSHOT_PORT:-8834}"
HOST="127.0.0.1"
BASE_URL="http://${HOST}:${PORT}"
PHP_LOG="${TMPDIR:-/tmp}/server-dashboard-screenshots-php.log"
CHROME_PROFILE_DIR="${TMPDIR:-/tmp}/server-dashboard-screenshots-chrome"
WINDOW_SIZE="${SCREENSHOT_WINDOW_SIZE:-1600,2200}"

find_browser() {
  if command -v google-chrome >/dev/null 2>&1; then
    echo "google-chrome"
    return 0
  fi
  if command -v chromium-browser >/dev/null 2>&1; then
    echo "chromium-browser"
    return 0
  fi
  if command -v chromium >/dev/null 2>&1; then
    echo "chromium"
    return 0
  fi
  return 1
}

BROWSER="$(find_browser || true)"
if [[ -z "${BROWSER}" ]]; then
  echo "No Chrome/Chromium binary found" >&2
  exit 1
fi

if ! php -r ' $s = @stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr); if (!$s) { exit(1); } fclose($s); ' >/dev/null 2>&1; then
  echo "Local TCP listener unavailable in this environment" >&2
  exit 1
fi

cleanup() {
  if [[ -n "${SERVER_PID:-}" ]] && kill -0 "${SERVER_PID}" >/dev/null 2>&1; then
    kill "${SERVER_PID}" >/dev/null 2>&1 || true
    wait "${SERVER_PID}" 2>/dev/null || true
  fi
  rm -rf "${CHROME_PROFILE_DIR}" >/dev/null 2>&1 || true
}
trap cleanup EXIT

mkdir -p "${OUT_DIR}"

php -S "${HOST}:${PORT}" "${ROOT}/tests/support/browser_smoke_router.php" >"${PHP_LOG}" 2>&1 &
SERVER_PID=$!

for _ in $(seq 1 40); do
  if php -r '$u=$argv[1]; $c=@file_get_contents($u); exit($c===false ? 1 : 0);' "${BASE_URL}/diag.php?__smoke_role=admin" >/dev/null 2>&1; then
    break
  fi
  sleep 0.25
done

capture() {
  local name="$1"
  local path="$2"
  mkdir -p "${CHROME_PROFILE_DIR}"
  "${BROWSER}" \
    --headless \
    --disable-gpu \
    --no-sandbox \
    --disable-dev-shm-usage \
    --hide-scrollbars \
    --run-all-compositor-stages-before-draw \
    --virtual-time-budget=7000 \
    --window-size="${WINDOW_SIZE}" \
    --user-data-dir="${CHROME_PROFILE_DIR}" \
    --screenshot="${OUT_DIR}/${name}.png" \
    "${BASE_URL}/${path}" >/dev/null 2>&1
  echo "captured ${name}.png"
}

PAGES=(
  "login|auth/login.php"
  "index|index.php?__smoke_role=admin"
  "services|services.php?__smoke_role=admin"
  "services_admin|services_admin.php?__smoke_role=admin"
  "history|history.php?__smoke_role=admin"
  "alerts_admin|alerts_admin.php?__smoke_role=admin"
  "processes|processes.php?__smoke_role=admin"
  "logs|logs.php?__smoke_role=admin"
  "log_viewer|log_viewer.php?__smoke_role=admin"
  "config|config.php?__smoke_role=admin"
  "cron|cron.php?__smoke_role=admin"
  "bookmarks|bookmarks.php?__smoke_role=admin"
  "users|users.php?__smoke_role=admin"
  "database|database.php?__smoke_role=admin"
  "diag|diag.php?__smoke_role=admin"
  "server_tests|server_tests.php?__smoke_role=admin"
  "speedtest|speedtest.php?__smoke_role=admin&__smoke_fixture=speedtest"
  "backups|backups.php?__smoke_role=admin"
  "tools_assets_audit|tools/assets_audit.php?__smoke_role=admin"
  "tools_admin_audit|tools/admin_audit.php?__smoke_role=admin"
)

for entry in "${PAGES[@]}"; do
  name="${entry%%|*}"
  path="${entry#*|}"
  capture "${name}" "${path}"
done
