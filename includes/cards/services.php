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
