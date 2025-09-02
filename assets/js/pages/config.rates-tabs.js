
// Only run on the Config page
(function(){
  'use strict';
  if (!/\/config\.php(\?|$)/.test(location.pathname)) return;
  if (window.__RATES_TABS_INIT__) return; window.__RATES_TABS_INIT__ = 1;

  function $(s,ctx){ return (ctx||document).querySelector(s); }
  function el(tag,cls){ var e=document.createElement(tag); if(cls) e.className=cls; return e; }
  function val(o,p,f){ try{ return p.split('.').reduce((a,k)=>a&&a[k],o) ?? f; }catch(_){ return f; } }
  function ok(m){ (window.toast&&toast.success)?toast.success(m):console.log(m); }
  function err(m){ (window.toast&&toast.error)?toast.error(m):console.error(m); }

  var CUR = {config:{}, security:{}};

  async function loadCfg(){
    try{
      const r = await fetch('api/config_export.php?_csrf=' + encodeURIComponent(window.__CONFIG_CSRF__||''));
      CUR = await r.json() || {config:{}, security:{}};
    }catch(e){ CUR = {config:{},security:{}}; err('config load failed: '+e.message); }
    return CUR;
  }

  async function save(partCfg, partSec){
    try{
      const csrf = (window.__CONFIG_CSRF__||'');
      const latest = await fetch('api/config_export.php?_csrf=' + encodeURIComponent(csrf)).then(r=>r.json());
      const nextCfg = Object.assign({}, latest.config||{}, partCfg||{});
      const nextSec = Object.assign({}, latest.security||{}, partSec||{});
      const res = await fetch('api/config_import.php', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({_csrf: csrf, config: nextCfg, security: nextSec})
      }).then(r=>r.json());
      if (res && res.ok){ ok('Saved. Reloading…'); setTimeout(function(){ location.reload(); }, 600); }
      else { err((res && res.error) || 'Save failed'); }
    }catch(e){ err('save failed: '+e.message); }
  }

  function activeSection(){
    var root = $('#config-root') || document;
    var elActive = root.querySelector('.nav-tabs .active, .tabset .active, [role="tab"].active, [aria-selected="true"].nav-link');
    if (elActive) return elActive.textContent.trim().toLowerCase();
    var btn = root.querySelector('.btn.active, .tab.active');
    if (btn) return btn.textContent.trim().toLowerCase();
    var h = root.querySelector('.tab-pane.active h2, .tab-pane.active h3, .config-pane.active h2, .config-pane.active h3');
    if (h) return h.textContent.trim().toLowerCase();
    return '';
  }

  function currentPane(){
    return document.querySelector('#config-root .tab-pane.active') || document.querySelector('#config-root .config-pane.active') || document.querySelector('#config-root');
  }

  function makeRow(label, init, step, suffix, onApply){
    var row = el('div'); row.style.display='flex'; row.style.gap='10px'; row.style.alignItems='center'; row.style.margin='6px 0';
    var lab = el('label'); lab.style.minWidth='240px'; lab.textContent = label;
    var inp = el('input'); inp.type='number'; inp.min='0'; inp.step=String(step||1); inp.value=String(init); inp.style.width='140px';
    var sfx = el('span'); sfx.textContent = suffix||'';
    var btn = el('button','btn primary'); btn.textContent='Apply';
    btn.addEventListener('click', function(){ var v = parseInt(inp.value,10); if (isNaN(v)||v<0) v=0; onApply(v); });
    row.appendChild(lab); row.appendChild(inp); row.appendChild(sfx); row.appendChild(btn);
    return row;
  }

  function ensureServerTests(pane){
    if (!pane || document.getElementById('server-tests-rate-card')) return;
    var wrap = el('div','card'); wrap.id='server-tests-rate-card'; wrap.style.marginTop='12px'; wrap.style.padding='12px';
    var h = el('div','h5'); h.textContent = 'Server Tests rate limit';
    var p = el('p'); p.textContent = 'Minimum gap between manual scans (ms). 0 disables.';
    var init = val(CUR,'config.server_tests.rate_limit_ms', val(CUR,'server_tests.rate_limit_ms',250));
    wrap.appendChild(h); wrap.appendChild(p);
    wrap.appendChild(makeRow('server_tests.rate_limit_ms', init, 50, 'ms', function(v){ save({ server_tests: { rate_limit_ms: v } }, {}); }));
    pane.appendChild(wrap);
  }

  function ensureAlerts(pane){
    if (!pane || document.getElementById('alerts-cooldown-card')) return;
    var wrap = el('div','card'); wrap.id='alerts-cooldown-card'; wrap.style.marginTop='12px'; wrap.style.padding='12px';
    var h = el('div','h5'); h.textContent = 'Alerts default cooldown';
    var p = el('p'); p.textContent = 'Fallback cooldown (minutes) when a rule omits it. Per-rule values still win.';
    var init = val(CUR,'config.alerts.cooldown_min', val(CUR,'alerts.cooldown_min',5));
    wrap.appendChild(h); wrap.appendChild(p);
    wrap.appendChild(makeRow('alerts.cooldown_min', init, 1, 'min', function(v){ save({ alerts: { cooldown_min: v } }, {}); }));
    pane.appendChild(wrap);
  }

  function tick(){
    var sec = activeSection();
    var pane = currentPane();
    if (!pane) return;
    if (sec === 'server tests') { ensureServerTests(pane); return; }
    if (sec === 'alerts') { ensureAlerts(pane); return; }
    if (sec !== 'server tests'){ var a = document.getElementById('server-tests-rate-card'); if (a && a.parentNode) a.parentNode.removeChild(a); }
    if (sec !== 'alerts'){ var b = document.getElementById('alerts-cooldown-card'); if (b && b.parentNode) b.parentNode.removeChild(b); }
  }

  document.addEventListener('DOMContentLoaded', function(){
    loadCfg().then(function(){ tick(); });
    var root = document.querySelector('#config-root') || document.body;
    var obs = new MutationObserver(function(){ tick(); });
    obs.observe(root, { childList:true, subtree:true, attributes:true, attributeFilter:['class','aria-selected'] });
    setInterval(tick, 800);
  });
})();
