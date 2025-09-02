<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/auth.php';
require_admin();

  $PAGE_TITLE = 'Services';
  $PAGE_CSS   = 'assets/css/pages/services.css';
  $REQUIRE_ADMIN = true; include __DIR__.'/includes/head.php'; ?>
<div class="card">
  
<div class="row between">
  <div class="section-title">Services</div>
  <div class="row gap">
    <input id="svcSearch" placeholder="Filter services..." />
    <nav class="tabs inline">
      <button id="btnAddService" class="btn small">Add Service</button>
      <button id="btnImport" class="btn small">Import</button>
      <button id="btnExportJson" class="btn small">Export JSON</button>
      <button id="btnExportCsv" class="btn small">Export CSV</button>
    </nav>
    <div class="row gap" id="autoprobeWrap" style="margin-left:.5rem">
      <label class="row" style="gap:.35rem">
        <input type="checkbox" id="autoProbe" />
        <span class="muted">Auto‑probe</span>
      </label>
      <label class="row" style="gap:.35rem">
        <span class="muted">Every</span>
        <input id="autoProbeSec" type="number" min="5" max="3600" value="60" style="width:72px;padding:.35rem .5rem;border:1px solid var(--border);border-radius:10px;background:color-mix(in srgb,var(--card) 92%, transparent)"/>
        <span class="muted">s</span>
      </label>
    </div>
    <input id="importFile" type="file" accept=".json,.csv" hidden />
  </div>
</div>

  
    <div class="table-scroll"><table class="js-sortable table" id="servicesTable">
      <thead>
        <tr>
          <th style="width:24%" data-sort="name" class="sortable">Name</th>
          <th style="width:10%" data-sort="type" class="sortable">Category</th>
          <th style="width:12%" data-sort="host" class="sortable">Host</th>
          <th style="width:7%" data-sort="port" class="sortable">Port</th>
          <th style="width:10%" data-sort="check" class="sortable">Probe</th>
          <th style="width:9%" data-sort="timeout_ms" class="sortable">Timeout</th>
          <th style="width:12%" data-sort="path" class="sortable">Path</th>
          <th style="width:8%" data-sort="enabled" class="sortable">Enabled</th>
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
      <div class="title" id="modalTitle">Add Service</div>
      <nav class="tabs inline"><a href="#" id="modalClose">Close</a></nav>
    </div>
    <form id="svcForm" class="form" onsubmit="return false;">
      <input type="hidden" name="id" id="f_id" />
      <div class="grid">
        <label>Name<input required name="name" id="f_name" /></label>
        <label>Category
          <select name="type" id="f_type">
            <option value="app">app</option>
            <option value="db">db</option>
            <option value="mail">mail</option>
            <option value="cache">cache</option>
            <option value="other">other</option>
          </select>
        </label>
        <label>Host<input required name="host" id="f_host" placeholder="127.0.0.1"/></label>
        <label>Port<input required type="number" min="1" max="65535" name="port" id="f_port"/></label>
        <label>Probe
          <select name="check" id="f_check">
            <option value="tcp">tcp</option>
            <option value="http">http</option>
            <option value="ping">ping</option>
          </select>
        </label>
        <label>Timeout (ms)<input required type="number" min="100" max="60000" step="100" name="timeout_ms" id="f_timeout" value="800"/></label>
        <label>Path (HTTP)<input name="path" id="f_path" placeholder="/health"/></label>
        <label class="row">Enabled
          <input type="checkbox" name="enabled" id="f_enabled" checked style="margin-left:.5rem"/>
        </label>
      </div>
      <div class="row between" style="margin-top:.75rem">
        <label class="row" style="gap:.35rem"><input type="checkbox" id="f_testOnSave"/><span class="muted">Test on save</span></label>
        <div class="row gap"><button class="btn" id="saveService">Save</button></div>
      </div>
    </form>
  </div>
</div>

<script defer src="assets/js/pages/services.js"></script>

<?php include __DIR__ . '/includes/foot.php'; ?>

<script src="assets/js/utils/sortable-table.js" defer></script>
