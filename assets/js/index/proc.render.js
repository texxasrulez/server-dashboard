/* assets/js/index/proc.render.js
 * Server Processes half-moon gauges (#procGrid) from api/metrics_summary.php
 * Clean needle + gradient edition (uniform 2px line, left->right mapping).
 */
(function(){
  const Bus = (window.Dashboard && window.Dashboard.Bus) || new EventTarget();
  function apiUrl(rel){ try{ return new URL(rel, location.origin + location.pathname.replace(/[^\/]*$/, '')); }catch(_){ return rel; } }
  const API = (document.body && document.body.dataset && document.body.dataset.apiMetrics) ? document.body.dataset.apiMetrics : apiUrl('api/metrics_summary.php');
  const POLL_MS = 5000;
  let timer = null, staleTimer=null, lastUpdateTs=0, loggedOnce=false;

  const ARC_D = "M10,50 A40,40 0 0 1 90,50"; // 180° arc

  function gaugeCard(title, id, subtitle){
    return `<div class="proc-card" data-id="${id}">
      <svg class="arc gauge" viewBox="0 0 100 60" aria-hidden="true">
        <path class="track" d="${ARC_D}" pathLength="100" />
        <path class="progress" d="${ARC_D}" pathLength="100" />
        <circle class="led" cx="88" cy="12" r="4" />
      </svg>
      <div class="label">${title}</div>
      <div class="meta small" data-role="meta">${subtitle||''}</div>
    </div>`;
  }

  function ensure(cardsList){
    const root = document.getElementById('procGrid');
    if (!root) return null;
    if (!root._built){
      root.innerHTML = cardsList.join('');
      // intro animation
      root.querySelectorAll('.proc-card').forEach((c,i)=>{
        c.classList.add('intro');
        c.style.animationDelay = (i*80)+'ms';
        setTimeout(()=>{ c.classList.remove('intro'); c.style.animationDelay=''; }, 800+i*80);
      });
      root._built = true;
      try{ __procGaugeInstall(root); }catch(e){}
    }
    return root;
  }

  function setGauge(root, id, pct, text, state){
    const el = root.querySelector(`.proc-card[data-id="${id}"]`);
    if (!el) return;
    pct = Math.max(0, Math.min(100, Number(pct)||0));
    const p = el.querySelector('.progress');
    if (p){ p.style.strokeDashoffset = String(100 - pct); }
    const m = el.querySelector('[data-role="meta"]');
    if (m){ m.textContent = text || ''; m.classList.remove('updated'); void m.offsetWidth; m.classList.add('updated'); }
    if (state) el.setAttribute('data-state', state);

    // rotate needle (inline, reliable even inside IIFE)
    try{
      const nd = el.querySelector('.needle');
      if (nd){
        const ang = (pct/100)*180 - 90; // 0% left, 100% right
        nd.setAttribute('transform','rotate(' + ang + ' 50 50)');
        nd.style.transform = 'rotate(' + ang + 'deg)';
      }
    }catch(_){}
  }

  function stateFromPct(p){ if (!isFinite(p)) return 'ok'; if (p >= 90) return 'bad'; if (p >= 75) return 'warn'; return 'ok'; }
  function stateFromFreePct(p){ if (!isFinite(p)) return 'ok'; if (p <= 10) return 'bad'; if (p <= 25) return 'warn'; return 'ok'; }

  function applyStale(root){
    const stale = (Date.now() - lastUpdateTs) > 30000; // 30s
    root.querySelectorAll('.proc-card').forEach(c => {
      if (stale) c.setAttribute('data-stale','1'); else c.removeAttribute('data-stale');
    });
  }

  function toGB(bytes){ const n = Number(bytes)||0; return n>0 ? (n/1073741824) : 0; }
  const MB = 1024*1024;

  function showError(root, msg){
    try{
      setGauge(root, 'cpu', 0, 'Error: ' + msg, 'bad');
      setGauge(root, 'mem_used', 0, '-- / --', 'bad');
      setGauge(root, 'mem_free', 0, '--', 'bad');
      setGauge(root, 'buffers', 0, '--', 'bad');
      setGauge(root, 'cache', 0, '--', 'bad');
      applyStale(root);
    }catch(_){}
  }

  const cardsList = [
    gaugeCard('CPU', 'cpu', 'load —'),
    gaugeCard('Memory Used', 'mem_used', '-- / --'),
    gaugeCard('Memory Free', 'mem_free', '--'),
    gaugeCard('Buffers', 'buffers', '--'),
    gaugeCard('Cache', 'cache', '--')
  ];

  async function tick(){
    const root = ensure(cardsList); if (!root) return;
    try{
      let r; try { r = await fetch(API, {cache:'no-store'}); } catch(err){ console.error('PROC fetch failed', err); const root = ensure(cardsList); if (root) showError(root, 'network'); return; }
      if (!r.ok){ console.error('PROC HTTP', r.status); const root = ensure(cardsList); if (root) showError(root, 'HTTP ' + r.status); return; }
      let jtxt = await r.text();
      let j;
      try { j = JSON.parse(jtxt); } catch(err){ console.error('PROC JSON parse failed', err, jtxt?.slice?.(0,180)); const root = ensure(cardsList); if (root) showError(root, 'json'); return; }
      if (!loggedOnce){ try{ console.debug('PROC JSON', j); }catch(_){ } loggedOnce=true; }
      lastUpdateTs = Date.now();

      // CPU
      let cores = Number(j?.cpu?.cores) || 1; if (!isFinite(cores)||cores<=0) cores=1;
      const load1 = Number((j?.cpu?.load1 ?? (Array.isArray(j?.loadavg) ? j.loadavg[0] : 0))) || 0;
      const cpuPct = Math.max(0, Math.min(100, (load1 / cores) * 100));
      setGauge(root, 'cpu', cpuPct, `load ${load1.toFixed(2)} / ${cores} core${cores>1?'s':''}`, stateFromPct(cpuPct));

      // Memory (strict)
      const mem = j?.memory || {};
      const totalB   = Number(mem.total_bytes)  || 0;
      const usedB    = Number(mem.used_bytes)   || 0;
      const freeB    = Number(mem.free_bytes)   || 0;
      const buffersB = Number(mem.buffers_bytes)|| 0;
      const cachedB  = Number(mem.cached_bytes) || 0;

      const total   = toGB(totalB), used = toGB(usedB), free = toGB(freeB);
      const buffers = toGB(buffersB), cached = toGB(cachedB);

      const usedPct = total>0 ? (used/total*100) : 0;
      const freePct = total>0 ? (free/total*100) : 0;
      const bufPct  = total>0 ? (buffers/total*100) : 0;
      const cachePct= total>0 ? (cached/total*100) : 0;

      // MB labels (strict)
      const MB = 1024*1024;
      const buffersLabel = buffersB<=0 ? '0 MB' : (buffersB<MB ? '<1 MB' : Math.round(buffersB/MB)+' MB');
      const cachedLabel  = cachedB<MB && cachedB>0 ? '<1 MB' : (cachedB<1024**3 ? Math.round(cachedB/MB)+' MB' : (cachedB/(1024**3)).toFixed(1)+' GB');

      setGauge(root, 'mem_used', usedPct, `${used.toFixed(1)} GB / ${total.toFixed(1)} GB`, stateFromPct(usedPct));
      setGauge(root, 'mem_free', freePct, `${free.toFixed(1)} GB`, stateFromFreePct(freePct));
      setGauge(root, 'buffers',  bufPct, buffersLabel, stateFromPct(bufPct>=5?75:0));
      setGauge(root, 'cache',     cachePct, cachedLabel, 'ok');

      applyStale(root);
    }catch(e){}
  }

  function start(){ if (!timer){ tick(); timer = setInterval(tick, POLL_MS); } if (!staleTimer){ const root = document.getElementById('procGrid'); staleTimer = setInterval(()=>{ const r = document.getElementById('procGrid'); if (r) applyStale(r); }, 1000); } }
  function stop(){ if (timer) clearInterval(timer), timer=null; if (staleTimer) clearInterval(staleTimer), staleTimer=null; }

  document.addEventListener('DOMContentLoaded', () => { ensure(cardsList); start(); });
  Bus.addEventListener('dashboard:tick', tick);
})();

/* ---- proc gauges: gradient + needle (theme-safe) ---- */
function __procGaugeInstall(root){
  var host = root || document.getElementById('procGrid') || document;
  var cards = host.querySelectorAll('.proc-card');

  for (var i=0;i<cards.length;i++){
    var cardEl = cards[i];
    var id = cardEl.getAttribute('data-id') || String(i);
    var svg = cardEl.querySelector('svg.arc.gauge'); if (!svg) continue;

    // defs + gradient (use CSS vars via style so theme switches live-update)
    var defs = svg.querySelector('defs');
    if (!defs){ defs = document.createElementNS('http://www.w3.org/2000/svg','defs'); svg.insertBefore(defs, svg.firstChild); }
    var gid = 'grad-' + id;
    if (!svg.querySelector('#'+gid)){
      var lg = document.createElementNS('http://www.w3.org/2000/svg','linearGradient');
      lg.setAttribute('id', gid);
      lg.setAttribute('x1','10'); lg.setAttribute('y1','50');
      lg.setAttribute('x2','90'); lg.setAttribute('y2','50');
      lg.setAttribute('gradientUnits','userSpaceOnUse');

      var stops = [
        ['0%','var(--ok)'],
        ['60%','var(--warn)'],
        ['85%','var(--danger)'],
        ['100%','var(--danger)']
      ];
      for (var s=0;s<stops.length;s++){
        var st = document.createElementNS('http://www.w3.org/2000/svg','stop');
        st.setAttribute('offset', stops[s][0]);
        // important: use style with var(), not the attribute with a fixed color
        st.setAttribute('style', 'stop-color: ' + stops[s][1]);
        lg.appendChild(st);
      }
      defs.appendChild(lg);

      var prog = svg.querySelector('.progress');
      if (prog){
        // gradient reference stays the same; stops themselves are var()-driven
        prog.style.stroke = 'url(#'+gid+')';
        prog.removeAttribute('stroke');
      }
    }

    // needle: bind to a CSS var so theme switches recolor it automatically
    if (!svg.querySelector('.needle')){
      var g = document.createElementNS('http://www.w3.org/2000/svg','g');
      g.setAttribute('class','needle');
      g.style.transformOrigin = '50px 50px';
      g.style.transform = 'rotate(-90deg)';
      g.setAttribute('transform','rotate(-90 50 50)'); // 0% left before first update

      // straight, slim line from hub center to near arc
      var tipR = 33.0, baseR = 6.0;
      var x1 = 50, y1 = 50 - baseR;
      var x2 = 50, y2 = 50 - tipR;

      var blade = document.createElementNS('http://www.w3.org/2000/svg','line');
      blade.setAttribute('class','blade');
      blade.setAttribute('x1', x1); blade.setAttribute('y1', y1);
      blade.setAttribute('x2', x2); blade.setAttribute('y2', y2);
      blade.setAttribute('stroke-width','2');
      blade.setAttribute('stroke-linecap','round');
      blade.setAttribute('fill','none');
      // key: use CSS var directly so it updates with theme
      blade.setAttribute('style','stroke: var(--accent)');

      var hub = document.createElementNS('http://www.w3.org/2000/svg','circle');
      hub.setAttribute('class','hub');
      hub.setAttribute('cx','50'); hub.setAttribute('cy','50');
      hub.setAttribute('r','3.2');
      // optional: hub can follow a muted/focus variable
      hub.setAttribute('style','fill: var(--border, transparent); stroke: var(--accent, transparent); stroke-width: 0');

      g.appendChild(blade); g.appendChild(hub); svg.appendChild(g);
    }
  }
}
