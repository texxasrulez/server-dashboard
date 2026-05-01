#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT="${BROWSER_SMOKE_PORT:-8823}"
HOST="127.0.0.1"
BASE_URL="http://${HOST}:${PORT}"
PHP_LOG="${TMPDIR:-/tmp}/server-dashboard-browser-smoke-php.log"
CHROME_PROFILE_DIR="${TMPDIR:-/tmp}/server-dashboard-browser-smoke-chrome"

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
  echo "browser smoke skipped: no Chrome/Chromium binary found"
  exit 0
fi

if ! php -r ' $s = @stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr); if (!$s) { exit(1); } fclose($s); ' >/dev/null 2>&1; then
  echo "browser smoke skipped: local TCP listener unavailable in this environment"
  exit 0
fi

cleanup() {
  if [[ -n "${SERVER_PID:-}" ]] && kill -0 "${SERVER_PID}" >/dev/null 2>&1; then
    kill "${SERVER_PID}" >/dev/null 2>&1 || true
    wait "${SERVER_PID}" 2>/dev/null || true
  fi
  rm -rf "${CHROME_PROFILE_DIR}" >/dev/null 2>&1 || true
}
trap cleanup EXIT

print_php_log() {
  if [[ -f "${PHP_LOG}" ]]; then
    echo "php built-in server log:" >&2
    cat "${PHP_LOG}" >&2
  fi
}

php -S "${HOST}:${PORT}" "${ROOT}/tests/support/browser_smoke_router.php" >"${PHP_LOG}" 2>&1 &
SERVER_PID=$!

ready=0
for _ in $(seq 1 40); do
  if php -r '$u=$argv[1]; $c=@file_get_contents($u); exit($c===false ? 1 : 0);' "${BASE_URL}/tests/support/browser_smoke_client.php?check=diag" >/dev/null 2>&1; then
    ready=1
    break
  fi
  sleep 0.25
done

if [[ "${ready}" != "1" ]]; then
  echo "browser smoke failed: php built-in server did not become ready at ${BASE_URL}" >&2
  print_php_log
  exit 1
fi

run_check() {
  local check="$1"
  local output
  mkdir -p "${CHROME_PROFILE_DIR}"
  output="$("${BROWSER}" \
    --headless \
    --disable-gpu \
    --no-sandbox \
    --disable-crash-reporter \
    --disable-crashpad \
    --disable-breakpad \
    --disable-dev-shm-usage \
    --no-first-run \
    --user-data-dir="${CHROME_PROFILE_DIR}" \
    --virtual-time-budget=8000 \
    --dump-dom \
    "${BASE_URL}/tests/support/browser_smoke_client.php?check=${check}")"

  if [[ "${output}" != *'data-status="pass"'* ]]; then
    echo "browser smoke failed: ${check}" >&2
    echo "${output}" >&2
    print_php_log
    return 1
  fi
  echo "browser smoke passed: ${check}"
}

run_check dashboard
run_check diag
run_check config
run_check history
run_check logs
run_check incidents
run_check service-detail
run_check backups
run_check speedtest
