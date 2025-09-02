
// Admin Debug Panel & API tracing
(function(){
  function qs(s,ctx=document){ return ctx.querySelector(s); }
  const isAdmin = (document.body.dataset.admin === '1');
  if (!isAdmin) return;

  const state = {
    open: false,
    trace: false,
    logs: []
  };
  const rate = new Map(); // urlBase -> array of timestamps (ms)

  // Enable via query (?trace=1) or remember in localStorage
  const params = new URLSearchParams(location.search);
  if (params.get('trace') === '1') state.trace = true;
  if (localStorage.getItem('dbg.trace') === '1') state.trace = true;

  // UI
  const btn = document.createElement('button');
  btn.className = 'debug-toggle';
  btn.title = 'Debug (Shift+D)';
  btn.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 8a5 5 0 0 1 10 0h2a1 1 0 1 1 0 2h-1.09c.06.33.09.66.09 1v1h1a1 1 0 1 1 0 2h-1v1c0 .34-.03.67-.09 1H19a1 1 0 1 1 0 2h-2a5 5 0 0 1-10 0H5a1 1 0 1 1 0-2h1.09A6.9 6.9 0 0 1 6 15v-1H5a1 1 0 1 1 0-2h1v-1c0-.34.03-.67.09-1H5a1 1 0 1 1 0-2h2Zm3-1h4a3 3 0 0 0-4 0Z"/></svg>';
  document.body.appendChild(btn);

  const panel = document.createElement('div');
  panel.className = 'debug-panel';
  panel.innerHTML = `
    <div class="hd">
      <div class="title">Debug</div>
      <div class="badges">
        <span class="badge">build ${document.body.dataset.build || '-'}</span>
        <span class="badge">${document.body.dataset.user || 'guest'}</span>
        <span class="badge" id="dbg-skew">skew: --</span>
      </div>
    </div>
    <div class="body">
      <div class="row">
        <label><input type="checkbox" id="dbg-trace"> Trace APIs</label>
        <button class="btn secondary" id="dbg-ping">Ping</button>
        <span class="muted" id="dbg-latency"></span>
      </div>
      <h4>Recent API calls</h4>
      <pre id="dbg-log" aria-live="polite">(empty)</pre>
      <h4 class="mt-2">API rate (per min)</h4>
      <pre id="dbg-rate" aria-live="polite">(empty)</pre>
    </div>`;
  document.body.appendChild(panel);

  function render(){
    qs('#dbg-trace', panel).checked = state.trace;
    qs('#dbg-log', panel).textContent = state.logs.join('\n') || '(empty)';
    panel.classList.toggle('open', state.open);
  }
  function toggle(){ state.open = !state.open; render(); }

  btn.addEventListener('click', toggle);
  document.addEventListener('keydown', (e)=>{ if (e.shiftKey && e.key.toLowerCase()==='d') toggle(); });

  // Hook fetch to append ?trace=1 only when tracing is ON
  function normUrl(u){
    try{ const x = new URL(u, location.href); return x.pathname.replace(/\?.*$/, ''); }catch(_){ return String(u); }
  }
  const _fetch = window.fetch.bind(window);
  window.fetch = async function(input, init){
    const params = new URLSearchParams(location.search);
    // live read of state.trace; if panel not created yet, read from localStorage or query
    const traceOn = (localStorage.getItem('dbg.trace') === '1') || (params.get('trace') === '1');
    if (!traceOn) {
      return _fetch(input, init); // no-op when tracing is off
    }
    let url = (typeof input === 'string') ? input : (input && input.url) || '';
    const isApi = url.startsWith('api/') || url.includes('/api/');
    if (isApi){
      const u = new URL(url, location.href);
      u.searchParams.set('trace', '1');
      url = u.toString();
      input = url;
    }
    const t0 = performance.now();
    const res = await _fetch(input, init);
    const dt = (performance.now() - t0).toFixed(0);
    if (isApi){
      // rate log
      const key = normUrl(url);
      const arr = rate.get(key) || []; arr.push(Date.now()); rate.set(key, arr); updateRatePanel();
      try{
        const clone = res.clone();
        const ct = clone.headers.get('content-type')||'';
        let info = '';
        if (ct.includes('application/json')){
          const j = await clone.json();
          const tr = j && (j.trace || j.debug || j.debugInfo);
          info = tr ? (' ' + JSON.stringify(tr)) : '';
        }
        state.logs.unshift(`[${new Date().toLocaleTimeString()}] ${url} — ${res.status} ${dt}ms${info}`);
        state.logs = state.logs.slice(0, 12);
        render();
      }catch(_){}
    }
    return res;
  };

  // Trace toggle
  panel.addEventListener('change', (e)=>{
    if (e.target && e.target.id === 'dbg-trace'){
      state.trace = !!e.target.checked;
      localStorage.setItem('dbg.trace', state.trace ? '1':'0');
    }
  });

  // Ping button
  panel.addEventListener('click', async (e)=>{
    if (e.target && e.target.id === 'dbg-ping'){
      const t0 = performance.now();
      const r = await fetch('api/debug_ping.php?trace=1', {cache:'no-store'});
      const dt = (performance.now() - t0).toFixed(0);
      const j = await r.json();
      qs('#dbg-latency', panel).textContent = ` ${dt}ms (server ${Math.round(j?.trace?.elapsed_ms||0)}ms)`;
    }
  });

  function updateRatePanel(){
    const now = Date.now();
    const lines = [];
    rate.forEach((arr, key) => {
      // prune >60s
      while (arr.length && now - arr[0] > 60000) arr.shift();
      lines.push(key + ' — ' + arr.length + '/min');
    });
    lines.sort();
    const el = qs('#dbg-rate', panel); if (el) el.textContent = lines.join('\n') || '(empty)';
  }
  setInterval(updateRatePanel, 2000);
  // Initial render
  render();
})();
