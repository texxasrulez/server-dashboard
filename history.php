<?php
require_once __DIR__ . "/includes/init.php";
require_once __DIR__ . "/includes/auth.php";
require_admin();

$PAGE_TITLE = "History";
$PAGE_CSS = "assets/css/pages/history.css";
$PAGE_JS = [
    "assets/js/utils/sortable-table.js",
    "assets/js/pages/history.js",
    "assets/js/pages/history.reports.js",
];
include __DIR__ . "/includes/head.php";
?>

<div class="card">
<div class="card" id="history-root"
  data-export-probes="<?= h(
      project_url("/api/history_export.php?type=probes"),
  ) ?>"
  data-export-alerts="<?= h(
      project_url("/api/history_export.php?type=alerts"),
  ) ?>"
  data-incidents="<?= h(project_url("/api/incidents.php")) ?>"
  data-probe-eval="<?= h(project_url("/api/alerts_eval.php?probe=1")) ?>">
  <div class="toolbar row gap wrap">
    <div class="row gap-sm align-center wrap">
      <div class="muted">Trends</div>
      <label class="row gap-xs">Range
        <select id="rangeSelect">
          <option value="1h">1h</option>
          <option value="24h" selected>24h</option>
          <option value="7d">7d</option>
          <option value="30d">30d</option>
        </select>
      </label>
      <label class="row gap-xs">Service
        <select id="serviceSelect">
          <option value="__all">All</option>
        </select>
      </label>
      <label class="row gap-xs"><input type="checkbox" id="baselineToggle" checked /> Baseline</label>
      <div class="row gap-xs legend">
        <span class="chip small up">Up</span>
        <span class="chip small warn">Warn</span>
        <span class="chip small down">Down</span>
      </div>
    </div>
    <div class="row gap-sm wrap" id="autoprobeControls">
      <label class="row gap-xs middle">
        <input type="checkbox" id="autoprobeEnabled">
        <span>Auto probe</span>
      </label>
      <label class="row gap-xs middle">
        Interval
        <input type="number" id="autoprobeInterval" min="5" step="5" style="width:80px">
        <span class="muted small">sec</span>
      </label>
      <button class="btn micro" id="autoprobeApply">Apply</button>
      <span class="muted small" id="autoprobeStatus"></span>
    </div>
    <div class="row gap-sm">
      <button class="btn" id="probeBtn"><span data-i18n="alerts.probe_now">Probe now</span></button>
      <button class="btn secondary" id="refreshBtn"><span data-i18n="alerts.refresh">Refresh</span></button>
      <button class="btn secondary" id="exportProbesBtn"><span data-i18n="history.export_json">Export JSON</span></button>
      <button class="btn secondary" id="exportAlertsBtn"><span data-i18n="alerts.export_json">Export Alerts JSON</span></button>
      <button class="btn danger" id="resetHistoryBtn">Reset history</button>
    </div>
    <div class="muted small" id="diagBox"></div>
  </div>
 </div>
 <div class="card">
  <div id="cardsGrid" class="grid cards"></div>
 </div>
 <div class="card">
  <h3 class="section-subtitle" style="margin-top:0">Recent Incidents</h3>
  <div class="table-wrap">
    <table class="table js-sortable" id="incidentsTable">
      <thead>
        <tr>
          <th data-sort="num">Last Seen</th><th data-sort="str">Incident</th><th data-sort="str">Root Cause</th><th data-sort="str">Status</th><th data-sort="num">Events</th><th data-sort="str">Severity</th>
        </tr>
      </thead>
      <tbody>
        <tr id="incidentsEmptyRow"><td colspan="6" class="muted">No correlated incidents yet.</td></tr>
      </tbody>
    </table>
  </div>
 </div>
 <div class="card">
  <div class="card" id="reportsRoot"
    data-report-json="<?= h(project_url("/api/report_uptime.php")) ?>"
    data-report-html="<?= h(
        project_url("/api/report_uptime.php?format=html"),
    ) ?>"
    data-report-csv="<?= h(
        project_url("/api/report_uptime.php?format=csv"),
    ) ?>">
    <div class="toolbar row between gap wrap">
      <div>
        <div class="section-subtitle">Monthly Uptime Summary</div>
        <div class="muted small">First-pass reporting/export built from stored probe history.</div>
      </div>
      <div class="row gap-sm wrap">
        <label class="row gap-xs middle">Month
          <input type="month" id="reportMonth">
        </label>
        <button class="btn secondary" id="reportPreviewBtn">Preview</button>
        <button class="btn secondary" id="reportHtmlBtn">Open HTML</button>
        <button class="btn secondary" id="reportCsvBtn">Download CSV</button>
      </div>
    </div>
    <div id="reportSummary" class="muted small" style="margin-top:.75rem"></div>
    <div class="table-wrap" style="margin-top:.75rem">
      <table class="table js-sortable" id="reportTable">
        <thead>
          <tr>
            <th data-sort="str">Service</th><th data-sort="num">Uptime</th><th data-sort="num">Coverage</th><th data-sort="num">Samples</th><th data-sort="num">Down</th><th data-sort="num">Avg latency</th><th data-sort="num">Max latency</th><th data-sort="str">Last status</th>
          </tr>
        </thead>
        <tbody>
          <tr><td colspan="8" class="muted">Choose a month to preview the uptime summary.</td></tr>
        </tbody>
      </table>
    </div>
  </div>
  <div class="card">
  <h3 class="section-subtitle" style="margin-top:1rem">Recent alert events</h3>
  <div class="table-wrap">
    <table class="table js-sortable" id="alertsTable">
      <thead>
        <tr>
          <th data-sort="num">Time</th><th data-sort="str">Service</th><th data-sort="str">Name</th><th data-sort="str">Condition</th><th data-sort="num">Value</th><th data-sort="str">Severity</th>
        </tr>
      </thead>
      <tbody>
        <tr id="alertsEmptyRow"><td colspan="6" class="muted">No alert events yet.</td></tr>
      </tbody>
    </table>
  </div>
</div>

<!-- Export modal -->
<div id="exportModal" hidden>
  <div class="box">
    


      <div>Export preview</div>
      <button class="btn secondary" id="exportClose">Close</button>
    </header>
    <pre id="exportPre"></pre>
    <footer class="row gap-sm">
      <button class="btn secondary" id="exportCopy">Copy</button>
      <button class="btn" id="exportDownload">Download</button>
    </footer>
  </div>
</div>
</div>
<?php include __DIR__ . "/includes/foot.php"; ?>
