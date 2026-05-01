#!/usr/bin/env bash
set -euo pipefail

# Generate a styled HTML backup report and convert it to PDF via LibreOffice.

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
WEB_ADMIN_ROOT="${WEB_ADMIN_ROOT:-$(cd "$SCRIPT_DIR/.." && pwd)}"
STATE_DIR="${STATE_DIR:-$WEB_ADMIN_ROOT/state}"
STATUS_JSON="${STATUS_JSON:-$STATE_DIR/backup_status.json}"
REPORT_DIR="${REPORT_DIR:-$STATE_DIR/reports}"
REPORT_BASENAME="${REPORT_BASENAME:-backup-report}"

if ! command -v jq >/dev/null 2>&1; then
  echo "ERROR: jq is required but not installed." >&2
  exit 1
fi

if ! command -v libreoffice >/dev/null 2>&1 && ! command -v soffice >/dev/null 2>&1; then
  echo "ERROR: LibreOffice (libreoffice/soffice) is required but not installed." >&2
  exit 1
fi

if [ ! -f "$STATUS_JSON" ]; then
  echo "ERROR: Status JSON not found: $STATUS_JSON" >&2
  exit 1
fi

mkdir -p "$REPORT_DIR"

ts="$(date +%Y%m%d-%H%M%S)"
OUT_HTML="${REPORT_DIR}/${REPORT_BASENAME}-${ts}.html"
OUT_PDF="${REPORT_DIR}/${REPORT_BASENAME}-${ts}.pdf"

status="$(jq -r '.status // "UNKNOWN"' "$STATUS_JSON")"
status_class="$(echo "$status" | tr 'A-Z' 'a-z')"
timestamp="$(jq -r '.timestamp // "" | @html' "$STATUS_JSON")"
backup_mount_ok="$(jq -r '.backup_mount_ok // false' "$STATUS_JSON")"
disk_usage="$(jq -r '.disk.usage_percent // 0' "$STATUS_JSON")"
disk_total="$(jq -r '.disk.total_gb // 0' "$STATUS_JSON")"
snap_age="$(jq -r '.snapshots.daily0_age_days // empty' "$STATUS_JSON")"
hestia_age="$(jq -r '.hestia.latest_age_days // empty' "$STATUS_JSON")"
micro_age="$(jq -r '.micro.latest_age_days // empty' "$STATUS_JSON")"
hestia_name="$(jq -r '.hestia.latest_backup_name // "" | @html' "$STATUS_JSON")"
micro_path="$(jq -r '.micro.latest_path // "" | @html' "$STATUS_JSON")"
micro_count="$(jq -r '.micro.entries_count // 0' "$STATUS_JSON")"

format_age() {
  local val="$1"
  if [ -z "$val" ] || [ "$val" = "null" ]; then
    echo "--"
  else
    echo "${val} d"
  fi
}

snap_age_fmt="$(format_age "$snap_age")"
hestia_age_fmt="$(format_age "$hestia_age")"
micro_age_fmt="$(format_age "$micro_age")"

folder_rows="$(jq -r '
  .disk.folders[]?
  | "<tr><td>" + (.label|@html) + "</td><td>" + (.path|@html) + "</td><td>" + ((.size_gb|tostring) + " GB") + "</td></tr>"
' "$STATUS_JSON")"

warnings_list="$(jq -r '.warnings[]? | "<li>" + (@html) + "</li>"' "$STATUS_JSON")"
errors_list="$(jq -r '.errors[]? | "<li>" + (@html) + "</li>"' "$STATUS_JSON")"

cat > "$OUT_HTML" <<HTML
<!doctype html>
<html>
  <head>
    <meta charset="utf-8">
    <title>Backup Report</title>
    <style>
      :root {
        --bg: #f7f4ef;
        --ink: #1b1b1b;
        --muted: #5b5b5b;
        --card: #ffffff;
        --ok: #1f7a46;
        --warn: #b36a00;
        --crit: #b33a3a;
        --line: #d9d2c6;
        --accent: #2c5f9e;
      }
      body {
        margin: 0;
        padding: 32px;
        background: var(--bg);
        color: var(--ink);
        font-family: "Liberation Sans", "DejaVu Sans", sans-serif;
        line-height: 1.4;
      }
      h1, h2 {
        margin: 0 0 12px 0;
        font-family: "Liberation Serif", "DejaVu Serif", serif;
        letter-spacing: 0.3px;
      }
      h1 { font-size: 26px; }
      h2 { font-size: 18px; color: var(--accent); }
      .header {
        display: flex;
        justify-content: space-between;
        align-items: flex-end;
        margin-bottom: 18px;
        border-bottom: 2px solid var(--line);
        padding-bottom: 12px;
      }
      .meta {
        font-size: 12px;
        color: var(--muted);
      }
      .status {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 999px;
        font-weight: 700;
        text-transform: uppercase;
        font-size: 12px;
        letter-spacing: 0.6px;
        background: var(--card);
        border: 1px solid var(--line);
      }
      .status.ok { color: var(--ok); }
      .status.warn { color: var(--warn); }
      .status.crit { color: var(--crit); }
      .grid {
        display: flex;
        gap: 16px;
        flex-wrap: wrap;
      }
      .card {
        background: var(--card);
        border: 1px solid var(--line);
        border-radius: 10px;
        padding: 14px 16px;
        box-shadow: 0 1px 2px rgba(0,0,0,0.04);
        flex: 1 1 280px;
      }
      table {
        width: 100%;
        border-collapse: collapse;
        font-size: 12px;
      }
      th, td {
        text-align: left;
        padding: 6px 8px;
        border-bottom: 1px solid var(--line);
      }
      th {
        color: var(--muted);
        font-weight: 600;
      }
      .kpi {
        font-size: 22px;
        font-weight: 700;
      }
      .label {
        color: var(--muted);
        font-size: 12px;
        margin-top: 2px;
      }
      .list {
        margin: 8px 0 0 18px;
        padding: 0;
      }
      .footnote {
        margin-top: 18px;
        font-size: 11px;
        color: var(--muted);
      }
    </style>
  </head>
  <body>
    <div class="header">
      <div>
        <h1>Backup Health Report</h1>
        <div class="meta">Generated at ${timestamp}</div>
      </div>
      <div class="status ${status_class}">${status}</div>
    </div>

    <div class="grid">
      <div class="card">
        <h2>Overview</h2>
        <div class="kpi">${disk_usage}%</div>
        <div class="label">Disk usage (${disk_total} GB total)</div>
        <div class="label">Backup mount: ${backup_mount_ok}</div>
      </div>
      <div class="card">
        <h2>Latest Ages</h2>
        <table>
          <tr><th>Snapshot (daily.0)</th><td>${snap_age_fmt}</td></tr>
          <tr><th>Hestia latest</th><td>${hestia_age_fmt}</td></tr>
          <tr><th>Micro latest</th><td>${micro_age_fmt}</td></tr>
        </table>
      </div>
      <div class="card">
        <h2>Micro Backups</h2>
        <div class="kpi">${micro_count}</div>
        <div class="label">Entries found</div>
        <div class="label">Latest path: ${micro_path}</div>
      </div>
    </div>

    <div class="card" style="margin-top:16px;">
      <h2>Folder Sizes</h2>
      <table>
        <tr><th>Folder</th><th>Path</th><th>Size</th></tr>
        ${folder_rows}
      </table>
    </div>

    <div class="grid" style="margin-top:16px;">
      <div class="card">
        <h2>Warnings</h2>
        <ul class="list">
          ${warnings_list:-<li>None</li>}
        </ul>
      </div>
      <div class="card">
        <h2>Errors</h2>
        <ul class="list">
          ${errors_list:-<li>None</li>}
        </ul>
      </div>
    </div>

    <div class="card" style="margin-top:16px;">
      <h2>Hestia Latest</h2>
      <div class="label">${hestia_name}</div>
    </div>

    <div class="footnote">
      Source: ${STATUS_JSON}
    </div>
  </body>
</html>
HTML

office_bin="libreoffice"
if ! command -v libreoffice >/dev/null 2>&1; then
  office_bin="soffice"
fi

"$office_bin" --headless --convert-to pdf --outdir "$REPORT_DIR" "$OUT_HTML" >/dev/null 2>&1

if [ ! -f "$OUT_PDF" ]; then
  echo "ERROR: PDF generation failed (missing $OUT_PDF)." >&2
  exit 1
fi

echo "$OUT_PDF"
