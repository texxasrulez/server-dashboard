<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/auth.php';
require_admin();

$PAGE_TITLE = 'Speedtest';
$PAGE_CSS = 'assets/css/pages/speedtest.css';
$PAGE_JS = [
    'assets/js/utils/sortable-table.js',
    'assets/js/pages/speedtest.js',
];
include __DIR__ . '/includes/head.php';
?>

<div
  id="speedtest-root"
  class="speedtest-page"
  data-history-url="<?= h(project_url('/api/speedtest_history.php')) ?>"
  data-export-url="<?= h(project_url('/api/speedtest_export.php')) ?>"
  data-status-url="<?= h(project_url('/api/speedtest_status.php')) ?>"
  data-run-url="<?= h(project_url('/api/speedtest_run.php')) ?>"
  data-csrf="<?= h(csrf_token()) ?>"
>
  <div class="card">
    <div class="row between wrap gap">
      <div>
        <div class="section-title"><strong>Speedtest</strong></div>
        <div class="muted small">Historical bandwidth tests collected on the dashboard host.</div>
      </div>
      <div class="toolbar row gap wrap">
        <label class="row gap-xs middle">Range
          <select id="speedtestRange">
            <option value="24h">24h</option>
            <option value="7d">7d</option>
            <option value="30d">30d</option>
            <option value="90d">90d</option>
          </select>
        </label>
        <label class="row gap-xs middle">Server
          <select id="speedtestServer">
            <option value="">All servers</option>
          </select>
        </label>
        <label class="row gap-xs middle">
          <input type="checkbox" id="speedtestIncludeFailed">
          <span>Include failed</span>
        </label>
        <button class="btn secondary" id="speedtestRefresh">Refresh</button>
        <button class="btn" id="speedtestRunNow">Run Speedtest</button>
        <button class="btn secondary" id="speedtestExport">Export CSV</button>
      </div>
    </div>
  </div>

  <div class="card">
    <div id="speedtestLoading" class="muted">Loading speedtest history…</div>
    <div id="speedtestError" class="speedtest-state error" hidden></div>
    <div id="speedtestEmpty" class="speedtest-state" hidden>No speedtest history is available yet.</div>
    <div id="speedtestWarning" class="speedtest-state warning" hidden></div>
    <div id="speedtestContent" hidden>
      <div id="speedtestSummary" class="speedtest-summary-grid"></div>

      <div class="speedtest-chart-grid">
        <section class="chart-card">
          <div class="chart-title">Download Mbps</div>
          <canvas id="chartDownload" height="220"></canvas>
        </section>
        <section class="chart-card">
          <div class="chart-title">Upload Mbps</div>
          <canvas id="chartUpload" height="220"></canvas>
        </section>
        <section class="chart-card">
          <div class="chart-title">Ping ms</div>
          <canvas id="chartPing" height="220"></canvas>
        </section>
        <section class="chart-card">
          <div class="chart-title">Jitter ms</div>
          <canvas id="chartJitter" height="220"></canvas>
        </section>
        <section class="chart-card" id="packetLossCard">
          <div class="chart-title">Packet loss %</div>
          <canvas id="chartPacketLoss" height="220"></canvas>
        </section>
      </div>

      <div class="table-wrap">
        <table class="table js-sortable" id="speedtestTable">
          <thead>
            <tr>
              <th data-sort="str">Timestamp</th>
              <th data-sort="str">Status</th>
              <th data-sort="str">Backend</th>
              <th data-sort="str">Server</th>
              <th data-sort="num">Ping</th>
              <th data-sort="num">Jitter</th>
              <th data-sort="num">Download</th>
              <th data-sort="num">Upload</th>
              <th data-sort="num">Packet loss</th>
              <th data-sort="num">Duration</th>
              <th data-sort="str">Tool version</th>
              <th data-sort="str">Error</th>
            </tr>
          </thead>
          <tbody id="speedtestTableBody"></tbody>
        </table>
      </div>
      <div class="speedtest-pagination row between wrap gap" id="speedtestPagination" hidden>
        <div class="row gap-xs middle">
          <span class="muted small">Rows per page</span>
          <select id="speedtestPageSize">
            <option value="25">25</option>
            <option value="50">50</option>
            <option value="100">100</option>
          </select>
        </div>
        <div class="row gap-xs middle">
          <button type="button" class="btn secondary" id="speedtestPrevPage">Previous</button>
          <div class="muted small" id="speedtestPageInfo">Page 1 of 1</div>
          <button type="button" class="btn secondary" id="speedtestNextPage">Next</button>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/foot.php'; ?>
