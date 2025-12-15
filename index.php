<?php
  // Pull site name for brand alt from central config if available
  if (!isset($SITE_NAME)) {
    try {
      $cfgLib = __DIR__ . '/../lib/Config.php';
      if (is_file($cfgLib)) { require_once $cfgLib; \App\Config::init(dirname(__DIR__)); $SITE_NAME = \App\Config::get('site.name', 'Server Dashboard'); }
      else { $SITE_NAME = 'Server Dashboard'; }
    } catch (Throwable $e) { $SITE_NAME = 'Server Dashboard'; }
  }
  $PAGE_CSS   = 'assets/css/pages/index.css';
  include __DIR__ . '/includes/head.php';
?>
<!-- System Metrics -->
<div class="card">
  <div class="row between">
    <div class="section-title">System Metrics <span class="muted">(alerts on)</span></div>
    <div class="row">
      <button class="btn secondary" id="btnRefreshMetrics">Refresh</button>
    </div>
  </div>
  <div id="sysMeta" class="muted small"></div>
  <div class="charts">
    <div class="chart"><div class="overlay"></div><canvas id="chartLoad" height="120"></canvas></div>
    <div class="chart"><div class="overlay"></div><canvas id="chartMem" height="120"></canvas></div>
    <div class="chart"><div class="overlay"></div><canvas id="chartNet" height="120"></canvas></div>
  </div>
</div>

<!-- Services -->
<div class="card">
  <div class="row between">
    <div class="section-title">Services <span class="muted"><span id="svcUpCount">0</span>/<span id="svcTotal">0</span> up</span></div>
    <div class="row">
      <button class="btn secondary" id="btnRefreshServices">Refresh</button>
      <label class="row small"><input type="checkbox" id="autoRefresh" checked> Auto-refresh</label>
      <label class="row small">Every <input id="refreshSec" type="number" min="10" step="10" value="600" style="width:80px"> s</label>
    </div>
  </div>
  <div id="services" class="services-grid"></div>
</div>

<!-- Server Processes -->
<div class="card">
  <div class="section-title">Server Processes</div>
  <div id="procGrid" class="proc-grid"></div>
</div>


<!-- CPU Status -->
<div class="card">
  <div class="section-title">CPU Status</div>
  <div id="cpuGrid" class="proc-grid"></div>
</div>


<!-- Hard Drive Status -->
<div class="card">
  <div class="section-title">Hard Drive Status</div>
  <div id="diskGrid" class="disk-grid">
    <div class="disk-card" data-id="usage"><div class="device"><div class="led"></div><div class="slot"><div class="progress"></div></div></div><div class="text"><div class="title">Disk /</div><div class="sub small" data-role="meta">Usage: --</div></div></div>
    <div class="disk-card" data-id="total"><div class="device"><div class="led"></div><div class="slot"><div class="progress"></div></div></div><div class="text"><div class="title">Disk /</div><div class="sub small" data-role="meta">Total: --</div></div></div>
    <div class="disk-card" data-id="used"><div class="device"><div class="led"></div><div class="slot"><div class="progress"></div></div></div><div class="text"><div class="title">Disk /</div><div class="sub small" data-role="meta">Used: --</div></div></div>
    <div class="disk-card" data-id="free"><div class="device"><div class="led"></div><div class="slot"><div class="progress"></div></div></div><div class="text"><div class="title">Disk /</div><div class="sub small" data-role="meta">Free: --</div></div></div>
    <div class="disk-card" data-id="temp"><div class="device"><div class="led"></div><div class="slot"><div class="progress"></div></div></div><div class="text"><div class="title">Disk /</div><div class="sub small" data-role="meta">Temp: --</div></div></div>
  </div>
</div>

<!-- page-specific JS -->
<script defer src="assets/js/index/bootstrap.js"></script>

<!-- renderers -->
<script defer src="assets/js/index/services.fix.js"></script>
<script defer src="assets/js/index/proc.render.js"></script>
<script defer src="assets/js/index/cpu.render.js"></script>
<script defer src="assets/js/index/disks.render.js"></script>

<!-- styles -->
<link rel="stylesheet" href="assets/css/index_metrics.zoom.css" />
<link rel="stylesheet" href="assets/css/index_proc.gauges.css" />
<link rel="stylesheet" href="assets/css/index_disks.cards.css" />


<!-- metrics -->
<script defer src="assets/js/index/metrics.zoom.js"></script>
<script defer src="assets/js/index/metrics.js"></script>
<script defer src="assets/js/index_metrics.js"></script>

<?php include __DIR__ . '/includes/foot.php'; ?>
