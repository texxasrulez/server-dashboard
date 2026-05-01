<div class="card">
  <div class="row between">
    <div class="section-title">Memory</div>
    <div class="row"><button class="btn secondary" id="btnRefreshMem" onclick="window.Dashboard?.Bus?.dispatchEvent(new Event('dashboard:tick'))">Refresh</button></div>
  </div>
  <div class="chart"><div class="overlay"></div><canvas id="chartMem" height="120"></canvas></div>
</div>
