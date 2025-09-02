<?php
require_once __DIR__.'/includes/init.php';
require_once __DIR__.'/includes/auth.php';
require_login();

$PAGE_TITLE = 'Server Tests';
$PAGE_CSS   = null; // keep default styles
include __DIR__ . '/includes/head.php';
?>
<!-- Server Tests -->
<div class="card">
  <div class="row between">
    <p><span class="muted small">Some tests take time to render. Be patient</span></p>
  </div>
  <div class="row between">
    <div class="section-title">
      <strong>Server Tests: </strong>
      <div id="reportCard" class="sys-badge" title="Overall grade will appear after quick scan"><span class="dot"></span><span class="label">…</span></div>
    </div>
    <div class="row">
      <button class="btn secondary" id="btnQuick">Quick Scan</button>
      <button class="btn secondary" id="btnSecurity">Security</button>
      <button class="btn secondary" id="btnFS">Filesystem</button>
      <button class="btn secondary" id="btnSvc">Services</button>
      <button class="btn secondary" id="btnPerf">Performance</button>
      <button class="btn secondary" id="btnHistory">History</button>      <button class="btn secondary" id="btnExportJson" title="Export last results as JSON">Export JSON</button>      <button class="btn secondary" id="btnExportCsv" title="Export last results as CSV">Export CSV</button>
    </div>
  </div>
  <br />
  <div id="testsOutput" class="mt"></div>
</div>

<script defer src="<?= h(project_url('/assets/js/pages/server_tests.js')) ?>?v=<?= h(BUILD) ?>"></script>
<link rel="stylesheet" href="<?= h(project_url('/assets/css/components/chip.css')) ?>?v=<?= h(BUILD) ?>">
<?php include __DIR__ . '/includes/foot.php'; ?>
