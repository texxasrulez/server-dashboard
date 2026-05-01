<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/i18n.php';
require_admin();

$PAGE_TITLE = __('drive_health.page.title', 'Drive Health');
$PAGE_CSS = 'assets/css/pages/drive-health.css';
$PAGE_JS = [
    'assets/js/utils/sortable-table.js',
    'assets/js/pages/drive-health.js',
];
include __DIR__ . '/includes/head.php';
?>

<div
  id="driveHealthRoot"
  class="drive-health-page"
  data-status-url="<?= h(project_url('/api/drive_health_status.php')) ?>"
  data-history-url="<?= h(project_url('/api/drive_health_history.php')) ?>"
  data-export-url="<?= h(project_url('/api/drive_health_export.php')) ?>"
  data-related-history-url="<?= h(project_url('/history.php')) ?>"
>
  <div class="card">
    <div class="row between wrap gap">
      <div>
        <div class="section-title"><strong data-i18n="drive_health.page.title">Drive Health</strong></div>
        <div class="muted small" data-i18n="drive_health.page.subtitle">NVMe health snapshots and historical storage metrics from the local collector.</div>
      </div>
      <div class="toolbar row gap wrap">
        <label class="row gap-xs middle"><span data-i18n="drive_health.controls.range">Range</span>
          <select id="driveHealthRange">
            <option value="24h">24h</option>
            <option value="7d">7d</option>
            <option value="30d">30d</option>
            <option value="90d">90d</option>
            <option value="all" data-i18n="drive_health.controls.all">All</option>
          </select>
        </label>
        <button class="btn secondary" id="driveHealthRefresh"><span data-i18n="alerts.refresh">Refresh</span></button>
        <button class="btn secondary" id="driveHealthExportJson"><span data-i18n="history.export_json">Export JSON</span></button>
        <button class="btn secondary" id="driveHealthExportCsv"><span data-i18n="drive_health.controls.export_csv">Export CSV</span></button>
      </div>
    </div>
  </div>

  <div class="card">
    <div id="driveHealthLoading" class="muted" data-i18n="drive_health.states.loading">Loading drive health…</div>
    <div id="driveHealthError" class="drive-health-state error" hidden></div>
    <div id="driveHealthEmpty" class="drive-health-state" hidden data-i18n="drive_health.states.empty">No NVMe snapshots are available yet. Run the collector first.</div>
    <div id="driveHealthContent" hidden>
      <div class="drive-health-meta row between wrap gap">
        <div class="muted small" id="driveHealthLastUpdated"><?= h(__('drive_health.meta.last_updated_prefix', 'Last updated:')) ?> —</div>
        <div class="muted small" id="driveHealthHistorySummary"><?= h(__('drive_health.meta.history_prefix', 'History:')) ?> —</div>
      </div>

      <section class="drive-health-section">
        <div class="section-subtitle" data-i18n="drive_health.sections.current_drives">Current drives</div>
        <div id="driveHealthCards" class="drive-health-grid"></div>
      </section>

      <section class="drive-health-section">
        <div class="row between wrap gap">
          <div>
            <div class="section-subtitle" data-i18n="drive_health.sections.trends">Drive trends</div>
            <div class="muted small" data-i18n="drive_health.sections.trends_subtitle">Percentage used, power-on hours, written bytes, and temperature for each NVMe drive.</div>
          </div>
          <div class="muted small"><span data-i18n="drive_health.sections.related_history">Related history:</span> <a id="driveHealthRelatedHistory" href="<?= h(project_url('/history.php')) ?>"><?= h(__('history.title', 'History')) ?></a></div>
        </div>
        <div id="driveHealthDevicePanels" class="drive-device-panels"></div>
      </section>

      <section class="drive-health-section">
        <div class="row between wrap gap">
          <div>
            <div class="section-subtitle" data-i18n="drive_health.sections.history_overview">History overview</div>
            <div class="muted small" data-i18n="drive_health.sections.history_subtitle">Raw sample table for the selected range.</div>
          </div>
          <div class="muted small" id="driveHealthSeriesSummary"><?= h(__('drive_health.meta.series_prefix', 'Series:')) ?> —</div>
        </div>
        <div id="driveHealthHistoryEmpty" class="drive-health-state" hidden data-i18n="drive_health.states.history_empty">No history exists for the selected range yet.</div>
        <div class="table-wrap" id="driveHealthHistoryWrap" hidden>
          <table class="table js-sortable" id="driveHealthHistoryTable">
            <thead>
              <tr>
                <th data-sort="str" data-i18n="drive_health.table.timestamp">Timestamp</th>
                <th data-sort="str" data-i18n="drive_health.table.drive">Drive</th>
                <th data-sort="num" data-i18n="drive_health.table.wear">Wear</th>
                <th data-sort="num" data-i18n="drive_health.table.power_on_hours">Power On Hours</th>
                <th data-sort="num" data-i18n="drive_health.table.temp">Temp</th>
                <th data-sort="num" data-i18n="drive_health.table.written">Written</th>
                <th data-sort="num" data-i18n="drive_health.table.integrity_errors">Integrity Errors</th>
                <th data-sort="num" data-i18n="drive_health.table.error_log_entries">Error Log Entries</th>
                <th data-sort="str" data-i18n="drive_health.table.status">Status</th>
              </tr>
            </thead>
            <tbody id="driveHealthHistoryBody"></tbody>
          </table>
        </div>
      </section>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/foot.php'; ?>
