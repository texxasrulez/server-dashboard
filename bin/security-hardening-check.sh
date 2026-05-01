#!/usr/bin/env bash
set -euo pipefail

# Hardening regression checks for auth/IP/SSRF guardrails.
# Usage:
#   bash bin/security-hardening-check.sh

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

fail=0

if command -v rg >/dev/null 2>&1; then
  SEARCH_BIN="rg"
else
  SEARCH_BIN="grep"
fi

qsearch() {
  local pattern="$1"
  local file="$2"
  if [ "$SEARCH_BIN" = "rg" ]; then
    rg -q "$pattern" "$file"
  else
    grep -Eq "$pattern" "$file"
  fi
}

pass() { echo "  [PASS] $1"; }
fail_case() { echo "  [FAIL] $1"; fail=1; }

echo "Hardening check:"

# 1) request_client_ip should only trust XFF from trusted proxies
if php -r '
require "includes/init.php";
$GLOBALS["__cfg_local"]["security"]["trusted_proxies"] = ["203.0.113.0/24"];
$_SERVER["REMOTE_ADDR"] = "198.51.100.10";
$_SERVER["HTTP_X_FORWARDED_FOR"] = "1.2.3.4, 5.6.7.8";
if (request_client_ip() !== "198.51.100.10") exit(1);
$_SERVER["REMOTE_ADDR"] = "203.0.113.10";
$_SERVER["HTTP_X_FORWARDED_FOR"] = "1.2.3.4, 5.6.7.8";
if (request_client_ip() !== "1.2.3.4") exit(2);
'; then
  pass "trusted proxy parsing"
else
  fail_case "trusted proxy parsing"
fi

# 2) auth login rate limiter should block after threshold
if php -r '
require "includes/init.php";
require "includes/auth.php";
$GLOBALS["__cfg_local"]["security"]["login_rate_limit"] = [
  "enabled" => true,
  "max_attempts" => 2,
  "window_sec" => 300,
  "base_delay_sec" => 60,
  "max_delay_sec" => 120,
];
$u = "__test_rate_" . bin2hex(random_bytes(6));
$ip = "198.51.100.77";
if (auth_login_rate_block_seconds($u, $ip) !== 0) exit(10);
auth_login_rate_register_failure($u, $ip);
if (auth_login_rate_block_seconds($u, $ip) !== 0) exit(11);
auth_login_rate_register_failure($u, $ip);
if (auth_login_rate_block_seconds($u, $ip) <= 0) exit(12);
auth_login_rate_clear($u, $ip);
if (auth_login_rate_block_seconds($u, $ip) !== 0) exit(13);
'; then
  pass "login rate limiting"
else
  fail_case "login rate limiting"
fi

# 3) API guard CIDR matcher should accept/deny correctly
if php -r '
require "api/_guard.php";
if (!function_exists("guard_ip_in_any")) exit(20);
if (!guard_ip_in_any("203.0.113.5", ["203.0.113.0/24"])) exit(21);
if (guard_ip_in_any("198.51.100.5", ["203.0.113.0/24"])) exit(22);
'; then
  pass "guard CIDR allowlist matching"
else
  fail_case "guard CIDR allowlist matching"
fi

# 4) favicon proxy must keep auth + SSRF guard hooks present
if qsearch "require_login\(\)" api/favicon_proxy.php \
  && qsearch "favicon_allowed_hosts" api/favicon_proxy.php \
  && qsearch "is_private_or_reserved_ip" api/favicon_proxy.php; then
  pass "favicon proxy guard hooks"
else
  fail_case "favicon proxy guard hooks"
fi

if [ "$fail" -ne 0 ]; then
  echo "Hardening check failed."
  exit 1
fi

echo "Hardening check passed."
