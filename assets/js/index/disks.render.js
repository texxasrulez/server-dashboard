/* assets/js/index/disks.render.js
   Hard Drive cards: builds 5 cards and hydrates from api/metrics_summary.php.
   - Immediate render, then polls every 5s
   - Threshold colors via data-state="ok|warn|bad"
   - Stale LED after 30s without successful update (data-stale="1")
   - Slide-in on first render; subtle 'updated' flash on subtitle
*/
(function(){
  function apiUrl(rel){ try{ return new URL(rel, location.origin + location.pathname.replace(/[^\/]*$/, '')); }catch(_){ return rel; } }
  const API = (document.body && document.body.dataset && document.body.dataset.apiMetrics) ? document.body.dataset.apiMetrics : apiUrl('api/metrics_summary.php');
  const POLL_MS = 5000;
  const STALE_MS = 30000;

  let timer = null;
  const SHOWAPI = (new URLSearchParams(location.search).get('showapi') === '1');
  if (!window.DashboardDebug) window.DashboardDebug = {};
  if (!window.DashboardDebug.hideApiLinks) window.DashboardDebug.hideApiLinks = function(){ document.querySelectorAll('.api-link').forEach(el=>el.remove()); };
  try{ console.debug('API metrics endpoint', API.toString ? API.toString() : API); }catch(_){}
  let staleTimer = null;
  let lastUpdateTs = 0;
  let loggedOnce = false;

  function card(label, id){
    return (
      `<div class="disk-card" data-id="${id}">
        <div class="device">
          <div class="led" title="Drive status"></div>
          <div class="slot"><div class="progress" style="width:0%"></div></div>
        </div>
        <div class="text">
          <div class="title">Disk /</div>
          <div class="sub small" data-role="meta">${label}</div>
        </div>
      </div>`
    );
  }

  function ensure(){
    const root = document.getElementById('diskGrid');
    if (!root) return null;
    if (!root._built){
      root.innerHTML = [
        card('Usage: --', 'usage'),
        card('Total: --', 'total'),
        card('Used: --',  'used'),
        card('Free: --',  'free'),
        card('Temp: --',  'temp')
      ].join('');
      // first render slide-in (staggered)
      const cards = root.querySelectorAll('.disk-card');
      cards.forEach((c,i)=>{
        c.classList.add('intro');
        c.style.animationDelay = (i*80)+'ms';
        setTimeout(()=>{ c.classList.remove('intro'); c.style.animationDelay=''; }, 900 + i*80);
      });
      root._built = true;
    }
    return root;
  }

  function setCard(root, id, pct, text, state){
    const el = root.querySelector(`.disk-card[data-id="${id}"]`);
    if (!el) return;
    const p = Math.max(0, Math.min(100, Number(pct)||0));
    const bar = el.querySelector('.progress');
    if (bar) bar.style.width = p + '%';
    const m = el.querySelector('[data-role="meta"]');
    if (m){ m.textContent = text || ''; m.classList.remove('updated'); void m.offsetWidth; m.classList.add('updated'); }
    if (state) el.setAttribute('data-state', state);
  }

  function stateFromUsage(p){ if (!isFinite(p)) return 'ok'; if (p >= 90) return 'bad'; if (p >= 75) return 'warn'; return 'ok'; }
  function stateFromFree(p){ if (!isFinite(p)) return 'ok'; if (p <= 10) return 'bad'; if (p <= 25) return 'warn'; return 'ok'; }
  function stateFromTemp(c){ if (c==null || !isFinite(c)) return 'ok'; if (c >= 80) return 'bad'; if (c >= 60) return 'warn'; return 'ok'; }

  function applyStale(root){
    const stale = (Date.now() - lastUpdateTs) > STALE_MS;
    root.querySelectorAll('.disk-card').forEach(c => {
      if (stale) c.setAttribute('data-stale','1'); else c.removeAttribute('data-stale');
    });
  }

  function toGB(x){
    const n = Number(x);
    if (!isFinite(n) || n <= 0) return 0;
    return n / (1024*1024*1024);
  }

  function showError(root, msg){
    try{
      setCard(root, 'usage', 0, 'Error: ' + msg, 'bad');
      setCard(root, 'total', 0, 'Total: --', 'bad');
      setCard(root, 'used',  0, 'Used: --', 'bad');
      setCard(root, 'free',  0, 'Free: --', 'bad');
      setCard(root, 'temp',  0, 'Temp: n/a', 'bad');
      applyStale(root);
    }catch(_){}
  }

  async function tick(){
    const root = ensure(); if (!root) return;
    try{
      const r = await fetch(API, {cache:'no-store'});
      if (!r.ok) throw new Error('HTTP '+r.status);
      const j = await r.json();
      lastUpdateTs = Date.now();
      const d = (j && (j.disks || j.disk)) || {};
      if (!loggedOnce){ try{ console.debug('HDD JSON', d); }catch(_){} loggedOnce = true; }

      const usedPct = Number(d.used_percent ?? d.usage ?? d.used_pct ?? 0);
      const totalB = d.total_bytes ?? d.total ?? 0;
      const usedB  = d.used_bytes  ?? d.used  ?? 0;
      const freeB  = d.free_bytes  ?? d.free  ?? 0;
      const totalGB = toGB(totalB);
      const usedGB  = toGB(usedB);
      const freeGB  = toGB(freeB);
      const tempC = (d.temp_c != null) ? Number(d.temp_c) : null;

      setCard(root, 'usage', usedPct, `Usage: ${isFinite(usedPct)? usedPct.toFixed(0):'--'}%`, stateFromUsage(usedPct));
      if (totalGB > 0){
        setCard(root, 'total', 100, `Total: ${totalGB.toFixed(1)} GB`, 'ok');
        const usedPctBar = Math.max(0, Math.min(100, (usedGB/totalGB)*100));
        setCard(root, 'used', usedPctBar, `Used: ${usedGB.toFixed(1)} GB`, stateFromUsage(usedPct));
        const freePctBar = Math.max(0, Math.min(100, (freeGB/totalGB)*100));
        setCard(root, 'free', freePctBar, `Free: ${freeGB.toFixed(1)} GB`, stateFromFree(freePctBar));
      } else {
        setCard(root, 'total', 0, 'Total: --', 'ok');
        setCard(root, 'used',  0, 'Used: --',  'ok');
        setCard(root, 'free',  0, 'Free: --',  'ok');
      }
      setCard(root, 'temp', tempC!=null ? Math.min(tempC,100):0, `Temp: ${tempC!=null? tempC+'°C':'n/a'}`, stateFromTemp(tempC));

      applyStale(root);
    }catch(e){
      console.error('disks.render tick error', e);
    }
  }

  function start(){
    if (!timer){ tick(); timer = setInterval(tick, POLL_MS); }
    if (!staleTimer){ const root = ensure(); staleTimer = setInterval(()=>{ const r = ensure(); if (r) applyStale(r); }, 1000); }
  }
  function stop(){
    if (timer) clearInterval(timer), timer=null;
    if (staleTimer) clearInterval(staleTimer), staleTimer=null;
  }

  // Kick off immediately
  if (document.readyState === 'loading'){
    document.addEventListener('DOMContentLoaded', () => { ensure(); start(); });
  } else {
    ensure(); start();
  }
})();