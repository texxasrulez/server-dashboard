(function(){ 'use strict';
  function $(s,ctx){ return (ctx||document).querySelector(s); }
  function $all(s,ctx){ return Array.from((ctx||document).querySelectorAll(s)); }
  function el(tag, cls){ var e=document.createElement(tag); if(cls) e.className=cls; return e; }
  function val(obj, path, fallback){
    try { return path.split('.').reduce((a,k)=> (a && k in a ? a[k] : undefined), obj) ?? fallback; } catch(_){ return fallback; }
  }
  function toastOK(msg){ if (window.toast && toast.success) toast.success(msg); else console.log(msg); }
  function toastERR(msg){ if (window.toast && toast.error) toast.error(msg); else console.error(msg); }
  var CUR_CFG = {config:{}, security:{}};
  function loadConfig(){
    var csrf = (window.__CONFIG_CSRF__ || '');
    return fetch('api/config_export.php?_csrf=' + encodeURIComponent(csrf))
      .then(r=>r.json()).then(j=>{ CUR_CFG = j || {config:{}, security:{}}; return CUR_CFG; });
  }
  function save(partialConfig, partialSecurity){
    if (!window.__CONFIG_SAVE_HELPER__) { toastERR('Save helper missing'); return; }
    window.__CONFIG_SAVE_HELPER__(partialConfig||{}, partialSecurity||{});
  }
  function activeSectionName(){
    var t = document.querySelector('.nav-tabs .active, .tabs .active, .tabset .active');
    if (t) return t.textContent.trim().toLowerCase();
    var h = document.querySelector('#config-root h3, #config-root h2, .tab-pane.active h3, .tab-pane.active h2');
    if (h) return h.textContent.trim().toLowerCase();
    return '';
  }
  function currentPane(){
    return document.querySelector('.tab-pane.active, .config-pane.active, #config-root');
  }
  function ensureSecurityCard(pane){
    if (!pane || pane.querySelector('#api-rate-limit-card')) return;
    var wrap = el('div','card'); wrap.id='api-rate-limit-card'; wrap.style.marginTop='12px'; wrap.style.padding='12px';
    var h = el('div','h5'); h.textContent = 'API rate limit';
    var p = document.createElement('p'); p.textContent = 'Min gap between calls to the same API endpoint per IP (ms). 0 disables for tuning.';
    var row = document.createElement('div'); row.style.display='flex'; row.style.gap='10px'; row.style.alignItems='center';
    var lab = document.createElement('label'); lab.textContent = 'security.api_rate_limit_ms'; lab.style.minWidth='220px';
    var inp = document.createElement('input'); inp.type='number'; inp.min='0'; inp.step='10'; inp.style.width='140px';
    inp.value = val(CUR_CFG,'security.api_rate_limit_ms',100);
    var btn = document.createElement('button'); btn.className='btn primary'; btn.textContent='Apply (security)';
    btn.addEventListener('click', function(){ var v = parseInt(inp.value,10); if (isNaN(v)||v<0) v=0; save({}, { api_rate_limit_ms: v }); });
    row.appendChild(lab); row.appendChild(inp); row.appendChild(btn);
    wrap.appendChild(h); wrap.appendChild(p); wrap.appendChild(row);
    pane.appendChild(wrap);
  }
  function ensureServerTestsCard(pane){
    if (!pane || pane.querySelector('#server-tests-rate-card')) return;
    var wrap = el('div','card'); wrap.id='server-tests-rate-card'; wrap.style.marginTop='12px'; wrap.style.padding='12px';
    var h = el('div','h5'); h.textContent = 'Server Tests rate limit';
    var p = document.createElement('p'); p.textContent = 'Min gap between manual scans (ms). 0 disables.';
    var row = document.createElement('div'); row.style.display='flex'; row.style.gap='10px'; row.style.alignItems='center';
    var lab = document.createElement('label'); lab.textContent = 'server_tests.rate_limit_ms'; lab.style.minWidth='220px';
    var inp = document.createElement('input'); inp.type='number'; inp.min='0'; inp.step='50'; inp.style.width='140px';
    inp.value = val(CUR_CFG,'config.server_tests.rate_limit_ms', val(CUR_CFG,'server_tests.rate_limit_ms',250));
    var btn = document.createElement('button'); btn.className='btn primary'; btn.textContent='Apply (server tests)';
    btn.addEventListener('click', function(){ var v = parseInt(inp.value,10); if (isNaN(v)||v<0) v=0; save({ server_tests: { rate_limit_ms: v } }, {}); });
    row.appendChild(lab); row.appendChild(inp); row.appendChild(btn);
    wrap.appendChild(h); wrap.appendChild(p); wrap.appendChild(row);
    pane.appendChild(wrap);
  }
  function ensureAlertsCard(pane){
    if (!pane || pane.querySelector('#alerts-cooldown-card')) return;
    var wrap = el('div','card'); wrap.id='alerts-cooldown-card'; wrap.style.marginTop='12px'; wrap.style.padding='12px';
    var h = el('div','h5'); h.textContent = 'Alerts default cooldown';
    var p = document.createElement('p'); p.textContent = 'Fallback cooldown (minutes) when rule omits it.';
    var row = document.createElement('div'); row.style.display='flex'; row.style.gap='10px'; row.style.alignItems='center';
    var lab = document.createElement('label'); lab.textContent = 'alerts.cooldown_min'; lab.style.minWidth='220px';
    var inp = document.createElement('input'); inp.type='number'; inp.min='0'; inp.step='1'; inp.style.width='120px';
    inp.value = val(CUR_CFG,'config.alerts.cooldown_min', val(CUR_CFG,'alerts.cooldown_min',5));
    var btn = document.createElement('button'); btn.className='btn primary'; btn.textContent='Apply (alerts)';
    btn.addEventListener('click', function(){ var v = parseInt(inp.value,10); if (isNaN(v)||v<0) v=0; save({ alerts: { cooldown_min: v } }, {}); });
    row.appendChild(lab); row.appendChild(inp); row.appendChild(btn);
    wrap.appendChild(h); wrap.appendChild(p); wrap.appendChild(row);
    pane.appendChild(wrap);
  }
  function activeSectionName(){
    var t = document.querySelector('.nav-tabs .active, .tabs .active, .tabset .active');
    if (t) return t.textContent.trim().toLowerCase();
    return '';
  }
  function currentPane(){
    return document.querySelector('.tab-pane.active, .config-pane.active, #config-root');
  }
  function tick(){
    var sec = activeSectionName(); if (!sec) return;
    var pane = currentPane();
    if (sec === 'security') { ensureSecurityCard(pane); return; }
    if (sec === 'server tests') { ensureServerTestsCard(pane); return; }
    if (sec === 'alerts') { ensureAlertsCard(pane); return; }
  }
  document.addEventListener('DOMContentLoaded', function(){
    loadConfig().then(function(){ tick(); });
    var obs = new MutationObserver(function(){ tick(); });
    var root = document.querySelector('#config-root') || document.body;
    obs.observe(root, { childList:true, subtree:true });
    setInterval(tick, 800);
  });
})();