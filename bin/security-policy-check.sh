#!/usr/bin/env bash
set -euo pipefail

# Security checks for API/auth hardening.
# Runs:
#   1) static policy checks for API auth/CSRF guardrails
#   2) runtime hardening regressions (trusted proxies, rate-limit, SSRF hooks)
# Usage:
#   bash bin/security-policy-check.sh

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

fail=0

is_exempt_file() {
  local f="$1"
  case "$f" in
    api/_guard.php|api/_state_path.php|api/client_log.php|api/health.php|api/i18n_languages.php|api/debug_ping.php|api/favicon_proxy.php)
      return 0
      ;;
    *)
      return 1
      ;;
  esac
}

has_auth_guard() {
  local f="$1"
  rg -q "require_admin\(|require_login\(|guard_api\(|cron_token_is_valid\(|cron_request_token\(|is_logged_in\(|user_is_admin\(|is_admin\(" "$f" \
    || rg -q "require\s+__DIR__\s*\.\s*'/security_(get|set)\.php'" "$f"
}

has_token_guard() {
  local f="$1"
  rg -q "guard_api\(\[[^]]*require_token'\s*=>\s*true|guard_api\(\[[^]]*require_token\"\s*=>\s*true|cron_token_is_valid\(" "$f"
}

is_mutating_endpoint() {
  local f="$1"
  rg -q "file_put_contents\(|rename\(|unlink\(|copy\(|write_json_atomic\(|move_uploaded_file\(" "$f"
}

csrf_relevant_input_surface() {
  local f="$1"
  rg -q "php://input|\$_POST|\$_REQUEST|REQUEST_METHOD[^\\n]*POST|\$_GET\[['\"](id|action|what|delete|remove|toggle|enabled|interval)" "$f"
}

has_csrf_check() {
  local f="$1"
  rg -q "csrf_check_request\(|csrf_check\(" "$f"
}

echo "Security policy check:"

while IFS= read -r f; do
  if is_exempt_file "$f"; then
    continue
  fi

  if ! has_auth_guard "$f"; then
    echo "  [FAIL] Missing auth/token guard: $f"
    fail=1
    continue
  fi

  if is_mutating_endpoint "$f" && csrf_relevant_input_surface "$f"; then
    # Token-gated endpoints can skip CSRF checks.
    if ! has_token_guard "$f" && ! has_csrf_check "$f"; then
      echo "  [FAIL] Mutating endpoint missing CSRF check: $f"
      fail=1
    fi
  fi
done < <(find api -maxdepth 1 -type f -name '*.php' | sort)

if [ "$fail" -ne 0 ]; then
  echo "Policy check failed."
  exit 1
fi

echo "Policy check passed."

echo "Running hardening regression checks..."
bash "$ROOT/bin/security-hardening-check.sh"
