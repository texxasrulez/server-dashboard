<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/auth.php';
require_admin();

$PAGE_TITLE = 'History';
$PAGE_CSS   = 'assets/css/pages/history.css';
$PAGE_JS    = 'assets/js/pages/history.js';
include __DIR__ . '/includes/head.php'; ?>

<div class="card">
<div class="card" id="history-root"
  data-export-probes="<?= h(project_url('/api/history_export.php?type=probes')) ?>"
  data-export-alerts="<?= h(project_url('/api/history_export.php?type=alerts')) ?>"
  data-probe-eval="<?= h(project_url('/api/alerts_eval.php?probe=1')) ?>">
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
  <h3 class="section-subtitle" style="margin-top:1rem">Recent alert events</h3>
  <div class="table-wrap">
    <table class="table" id="alertsTable">
      <thead>
        <tr>
          <th>Time</th><th>Service</th><th>Name</th><th>Condition</th><th>Value</th><th>Severity</th>
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
<?php include __DIR__.'/includes/foot.php'; ?>
