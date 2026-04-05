<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/i18n.php';
require_admin();

$PAGE_TITLE = __('services.title', 'Services');
$PAGE_CSS   = 'assets/css/pages/services.css';
$REQUIRE_ADMIN = true;
include __DIR__.'/includes/head.php'; ?>
<?php
require_once __DIR__ . '/Adapters/AdapterFactory.php';
$platformAdapter = DashboardAdapterFactory::make();
$platformCaps = $platformAdapter->getCapabilities();
$platformStatus = $platformAdapter->getServiceStatus();
?>
<div class="card">
<div class="row between" style="gap:.5rem; flex-wrap:wrap; margin-bottom:.5rem;">
  <div class="section-title" data-i18n="services_page.environment">Environment</div>
  <div class="row gap" style="flex-wrap:wrap;">
    <span class="chip neutral"><?= h(__('services_page.caps.panel', 'panel')) ?>: <?= h((string)($platformCaps['panel'] ?? __('common.none', 'none'))) ?></span>
    <span class="chip neutral"><?= h(__('services_page.caps.web', 'web')) ?>: <?= h((string)($platformCaps['web'] ?? __('common.none', 'none'))) ?></span>
    <span class="chip neutral"><?= h(__('services_page.caps.mta', 'mta')) ?>: <?= h((string)($platformCaps['mta'] ?? __('common.none', 'none'))) ?></span>
    <span class="chip neutral"><?= h(__('services_page.caps.db', 'db')) ?>: <?= h((string)($platformCaps['db'] ?? __('common.none', 'none'))) ?></span>
    <span class="chip neutral"><?= h(__('services_page.caps.nginx', 'nginx')) ?>: <?= h(($platformStatus['nginx'] === true) ? __('services_page.states.active', 'active') : (($platformStatus['nginx'] === false) ? __('services_page.states.inactive', 'inactive') : __('config.ui.unknown', 'unknown'))) ?></span>
  </div>
</div>
<div class="card">
<div class="row between">
  <div class="section-title" data-i18n="services.title">Services</div>
  <div class="row gap">
    <input id="svcSearch" placeholder="<?= h(__('services_page.filter_placeholder', 'Filter services...')) ?>" data-i18n="services_page.filter_placeholder" data-i18n-attr="placeholder" />
    <nav class="tabs inline">
      <button id="btnAddService" class="btn small" data-i18n="services_page.add_service">Add Service</button>
      <button id="btnImport" class="btn small" data-i18n="services_page.import">Import</button>
      <button id="btnExportJson" class="btn small"><span data-i18n="history.export_json">Export JSON</span></button>
      <button id="btnExportCsv" class="btn small" data-i18n="services_page.export_csv">Export CSV</button>
    </nav>
    <div class="row gap" id="autoprobeWrap" style="margin-left:.5rem">
      <label class="row" style="gap:.35rem">
        <input type="checkbox" id="autoProbe" />
        <span class="muted" data-i18n="services_page.auto_probe">Auto-probe</span>
      </label>
      <label class="row" style="gap:.35rem">
        <span class="muted" data-i18n="services_page.every">Every</span>
        <input id="autoProbeSec" type="number" min="5" max="3600" value="60" style="width:72px;padding:.35rem .5rem;border:1px solid var(--border);border-radius:10px;background:color-mix(in srgb,var(--card) 92%, transparent)"/>
        <span class="muted" data-i18n="services_page.seconds_short">s</span>
      </label>
    </div>
    <input id="importFile" type="file" accept=".json,.csv" hidden />
  </div>
</div>
</div>
 <div class="card">
    <div class="table-scroll"><table class="js-sortable table" id="servicesTable">
      <thead>
        <tr>
          <th style="width:24%" data-sort="name" class="sortable" data-i18n="services_page.columns.name">Name</th>
          <th style="width:10%" data-sort="type" class="sortable" data-i18n="services_page.columns.category">Category</th>
          <th style="width:12%" data-sort="host" class="sortable" data-i18n="services_page.columns.host">Host</th>
          <th style="width:7%" data-sort="port" class="sortable" data-i18n="services_page.columns.port">Port</th>
          <th style="width:10%" data-sort="check" class="sortable" data-i18n="services_page.columns.probe">Probe</th>
          <th style="width:9%" data-sort="timeout_ms" class="sortable" data-i18n="services_page.columns.timeout">Timeout</th>
          <th style="width:12%" data-sort="path" class="sortable" data-i18n="services_page.columns.path">Path</th>
          <th style="width:8%" data-sort="enabled" class="sortable" data-i18n="services_page.columns.enabled">Enabled</th>
          <th class="actions-col"></th>
        </tr>
      </thead>
      <tbody id="svcBody"></tbody>
    </table>
  </div>
</div>

<!-- Modal -->
<div id="svcModal" class="modal" hidden>
  <div class="modal-card">
    <div class="modal-head">
      <div class="title" id="modalTitle" data-i18n="services_page.add_service">Add Service</div>
      <nav class="tabs inline"><a href="#" id="modalClose" data-i18n="common.close">Close</a></nav>
    </div>
    <form id="svcForm" class="form" onsubmit="return false;">
      <input type="hidden" name="id" id="f_id" />
      <div id="formAlert" class="muted" hidden></div>
      <div class="grid">
        <label><span data-i18n="services_page.form.name">Name</span><input required name="name" id="f_name" /></label>
        <label><span data-i18n="services_page.form.category">Category</span>
          <select name="type" id="f_type">
            <option value="app">app</option>
            <option value="db">db</option>
            <option value="mail">mail</option>
            <option value="cache">cache</option>
            <option value="other">other</option>
          </select>
        </label>
        <label><span data-i18n="services_page.form.host">Host</span><input required name="host" id="f_host" placeholder="127.0.0.1"/></label>
        <label><span data-i18n="services_page.form.port">Port</span><input required type="number" min="1" max="65535" name="port" id="f_port"/></label>
        <label><span data-i18n="services_page.form.probe">Probe</span>
          <select name="check" id="f_check">
            <option value="tcp">tcp</option>
            <option value="http">http</option>
            <option value="ping">ping</option>
          </select>
        </label>
        <label><span data-i18n="services_page.form.timeout">Timeout (ms)</span><input required type="number" min="100" max="60000" step="100" name="timeout_ms" id="f_timeout" value="800"/></label>
        <label><span data-i18n="services_page.form.path">Path (HTTP)</span><input name="path" id="f_path" placeholder="/health"/></label>
        <label class="row"><span data-i18n="services_page.form.enabled">Enabled</span>
          <input type="checkbox" name="enabled" id="f_enabled" checked style="margin-left:.5rem"/>
        </label>
      </div>
      <div class="row between" style="margin-top:.75rem">
        <label class="row" style="gap:.35rem"><input type="checkbox" id="f_testOnSave"/><span class="muted" data-i18n="services_page.test_on_save">Test on save</span></label>
        <div class="row gap"><button class="btn" id="saveService"><span data-i18n="common.save">Save</span></button></div>
      </div>
    </form>
  </div>
</div>
</div>

<script defer src="assets/js/pages/services.js"></script>

<?php include __DIR__ . '/includes/foot.php'; ?>

<script src="assets/js/utils/sortable-table.js" defer></script>
