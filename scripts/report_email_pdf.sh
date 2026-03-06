#!/usr/bin/env bash
set -euo pipefail

# Generate a backup PDF report (if needed) and email it via sendmail.

PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"
export PATH
umask 027

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [ -f "/etc/server-dashboard/dashboard_env.sh" ]; then
  # shellcheck disable=SC1091
  source "/etc/server-dashboard/dashboard_env.sh"
elif [ -f "$SCRIPT_DIR/lib/dashboard_env.sh" ]; then
  # shellcheck disable=SC1091
  source "$SCRIPT_DIR/lib/dashboard_env.sh"
fi
if declare -F dashboard_env_bootstrap >/dev/null 2>&1; then
  dashboard_env_bootstrap "$SCRIPT_DIR"
fi

SENDMAIL="${SENDMAIL:-/usr/sbin/sendmail}"
EMAIL_TO="${REPORT_EMAIL_TO:-}"
EMAIL_FROM="${REPORT_EMAIL_FROM:-server-dashboard@$(hostname -f 2>/dev/null || hostname || echo 'localhost')}"
EMAIL_SUBJECT="${REPORT_SUBJECT:-Backup Health Report}"

PDF_PATH="${1:-}"
if [ -n "$PDF_PATH" ] && [ ! -f "$PDF_PATH" ]; then
  echo "ERROR: PDF not found: $PDF_PATH" >&2
  exit 1
fi

if [ -z "$PDF_PATH" ]; then
  PDF_PATH="$(dirname "$0")/report_generate_pdf.sh"
  if [ ! -x "$PDF_PATH" ]; then
    echo "ERROR: report_generate_pdf.sh is not executable: $PDF_PATH" >&2
    exit 1
  fi
  PDF_PATH="$("$PDF_PATH")"
fi

if [ ! -x "$SENDMAIL" ]; then
  echo "ERROR: sendmail binary not found or not executable: $SENDMAIL" >&2
  exit 1
fi
if [ -z "$EMAIL_TO" ]; then
  echo "INFO: REPORT_EMAIL_TO is empty; skipping email send." >&2
  exit 0
fi

if ! command -v base64 >/dev/null 2>&1; then
  echo "ERROR: base64 is required but not installed." >&2
  exit 1
fi

filename="$(basename "$PDF_PATH")"
boundary="====report-$(date +%s%N)===="

{
  echo "To: ${EMAIL_TO}"
  echo "From: ${EMAIL_FROM}"
  echo "Subject: ${EMAIL_SUBJECT}"
  echo "MIME-Version: 1.0"
  echo "Content-Type: multipart/mixed; boundary=\"${boundary}\""
  echo
  echo "--${boundary}"
  echo "Content-Type: text/plain; charset=utf-8"
  echo "Content-Transfer-Encoding: 7bit"
  echo
  echo "Attached is the latest backup health report."
  echo
  echo "--${boundary}"
  echo "Content-Type: application/pdf; name=\"${filename}\""
  echo "Content-Transfer-Encoding: base64"
  echo "Content-Disposition: attachment; filename=\"${filename}\""
  echo
  base64 "$PDF_PATH"
  echo
  echo "--${boundary}--"
} | "$SENDMAIL" -t

echo "Sent ${PDF_PATH} to ${EMAIL_TO}"
