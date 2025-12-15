/* assets/js/index/cpu.render.js
 * CPU Status half-moon gauges (#cpuGrid) from api/metrics_summary.php.
 * Mirrors the Server Processes gauges style (same SVG, gradients, needles).
 */
(function(){
  const Bus = (window.Dashboard && window.Dashboard.Bus) || new EventTarget();

  function apiUrl(rel){
    try{
      return new URL(rel, location.origin + location.pathname.replace(/[^\/]*$/, ''));
    }catch(_){
      return rel;
    }
  }

  const API = (document.body && document.body.dataset && document.body.dataset.apiMetrics)
    ? document.body.dataset.apiMetrics
    : apiUrl('api/metrics_summary.php');

  const POLL_MS = 5000;
  let timer = null, staleTimer = null, lastUpdateTs = 0, loggedOnce = false;

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

  function __cpuGaugeInstall(root){
    var host = root || document.getElementById('cpuGrid') || document;
    var cards = host.querySelectorAll('.proc-card');

    for (var i=0;i<cards.length;i++){
      var cardEl = cards[i];
      var id = cardEl.getAttribute('data-id') || String(i);
      var svg = cardEl.querySelector('svg.arc.gauge'); if (!svg) continue;

      // defs + gradient (CSS var driven)
      var defs = svg.querySelector('defs');
      if (!defs){
        defs = document.createElementNS('http://www.w3.org/2000/svg','defs');
        svg.insertBefore(defs, svg.firstChild);
      }
      var gid = 'grad-cpu-' + id;
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
          st.setAttribute('style', 'stop-color: ' + stops[s][1]);
          lg.appendChild(st);
        }
        defs.appendChild(lg);

        var prog = svg.querySelector('.progress');
        if (prog){
          prog.style.stroke = 'url(#'+gid+')';
          prog.removeAttribute('stroke');
        }
      }

      // needle group (same as proc.render.js)
      if (!svg.querySelector('.needle')){
        var g = document.createElementNS('http://www.w3.org/2000/svg','g');
        g.setAttribute('class','needle');
        g.style.transformOrigin = '50px 50px';
        g.style.transform = 'rotate(-90deg)';
        g.setAttribute('transform','rotate(-90 50 50)');

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
        blade.setAttribute('style','stroke: var(--accent)');

        var hub = document.createElementNS('http://www.w3.org/2000/svg','circle');
        hub.setAttribute('class','hub');
        hub.setAttribute('cx','50'); hub.setAttribute('cy','50');
        hub.setAttribute('r','3.2');
        hub.setAttribute('style','fill: var(--border, transparent); stroke: var(--accent, transparent); stroke-width: 0');

        g.appendChild(blade); g.appendChild(hub); svg.appendChild(g);
      }
    }
  }

  function ensure(cards){
    const root = document.getElementById('cpuGrid');
    if (!root) return null;
    if (!root._built){
      root.innerHTML = cards.join('');
      root.querySelectorAll('.proc-card').forEach((c,i)=>{
        c.classList.add('intro');
        c.style.animationDelay = (i*80)+'ms';
        setTimeout(()=>{ c.classList.remove('intro'); c.style.animationDelay=''; }, 800+i*80);
      });
      root._built = true;
      try{ __cpuGaugeInstall(root); }catch(e){}
    }
    return root;
  }

  function stateFromPct(p){
    if (!isFinite(p)) return 'ok';
    if (p >= 90) return 'bad';
    if (p >= 75) return 'warn';
    return 'ok';
  }

  function tempState(tempC){
    if (tempC == null || isNaN(tempC)) return null;
    if (tempC < 60) return 'ok';
    if (tempC < 80) return 'warn';
    return 'bad';
  }

  function setGauge(root, id, pct, text, state){
    const el = root.querySelector(`.proc-card[data-id="${id}"]`);
    if (!el) return;
    pct = Math.max(0, Math.min(100, Number(pct)||0));
    const p = el.querySelector('.progress');
    if (p){ p.style.strokeDashoffset = String(100 - pct); }
    const m = el.querySelector('[data-role="meta"]');
    if (m){
      m.textContent = text || '';
      m.classList.remove('updated'); void m.offsetWidth; m.classList.add('updated');
    }
    if (state) el.setAttribute('data-state', state);

    try{
      const nd = el.querySelector('.needle');
      if (nd){
        const ang = (pct/100)*180 - 90;
        nd.setAttribute('transform','rotate(' + ang + ' 50 50)');
        nd.style.transform = 'rotate(' + ang + 'deg)';
      }
    }catch(_){}
  }

  const cardsList = [
    gaugeCard('Load (1m)', 'cpu_load1', 'load —'),
    gaugeCard('Load (5m)', 'cpu_load5', 'load —'),
    gaugeCard('Load (15m)', 'cpu_load15', 'load —'),
    gaugeCard('CPU Package Temp', 'cpu_temp_pkg', '--'),
    gaugeCard('CPU Core Avg Temp', 'cpu_temp_avg', '--')
  ];

  async function tick(){
    const root = ensure(cardsList); if (!root) return;
    try{
      let r;
      try {
        r = await fetch(API, {cache:'no-store'});
      } catch(err){
        console.error('CPU fetch failed', err);
        return;
      }
      if (!r.ok){
        console.error('CPU HTTP', r.status);
        return;
      }
      let jtxt = await r.text();
      let j;
      try { j = JSON.parse(jtxt); }
      catch(err){
        console.error('CPU JSON parse failed', err, jtxt?.slice?.(0,180));
        return;
      }
      if (!loggedOnce){
        try{ console.debug('CPU JSON', j); }catch(_){}
        loggedOnce = true;
      }
      lastUpdateTs = Date.now();

      let cores = Number(j?.cpu?.cores) || 1;
      if (!isFinite(cores) || cores <= 0) cores = 1;

      const load1  = Number(j?.cpu?.load1 ?? 0) || 0;
      const load5  = Number(j?.cpu?.load5 ?? 0) || 0;
      const load15 = Number(j?.cpu?.load15 ?? 0) || 0;

      const pct1  = Math.max(0, Math.min(100, (load1/cores)*100));
      const pct5  = Math.max(0, Math.min(100, (load5/cores)*100));
      const pct15 = Math.max(0, Math.min(100, (load15/cores)*100));

      setGauge(root, 'cpu_load1', pct1, `load ${load1.toFixed(2)} / ${cores} core${cores>1?'s':''}`, stateFromPct(pct1));
      setGauge(root, 'cpu_load5', pct5, `load ${load5.toFixed(2)} / ${cores} core${cores>1?'s':''}`, stateFromPct(pct5));
      setGauge(root, 'cpu_load15', pct15, `load ${load15.toFixed(2)} / ${cores} core${cores>1?'s':''}`, stateFromPct(pct15));

      const temps = j?.cpu?.temps || {};
      const pkg   = typeof temps.package === 'number' ? temps.package : null;
      const coresAvg = (temps.cores && typeof temps.cores === 'object')
        ? (function(obj){
            const vals = Object.values(obj).map(Number).filter(v=>!isNaN(v));
            if (!vals.length) return null;
            return vals.reduce((a,b)=>a+b,0) / vals.length;
          })(temps.cores)
        : null;

      if (pkg != null){
        const tpct = Math.max(0, Math.min(100, pkg));
        setGauge(root, 'cpu_temp_pkg', tpct, pkg.toFixed(1)+'°C', tempState(pkg));
      }

      if (coresAvg != null){
        const apct = Math.max(0, Math.min(100, coresAvg));
        setGauge(root, 'cpu_temp_avg', apct, coresAvg.toFixed(1)+'°C', tempState(coresAvg));
      }

    }catch(err){
      console.error('CPU tick error', err);
    }
  }

  function start(){
    if (timer) clearInterval(timer);
    timer = setInterval(tick, POLL_MS);
    tick();
  }

  if (document.readyState === 'complete' || document.readyState === 'interactive'){
    start();
  } else {
    window.addEventListener('DOMContentLoaded', start, {once:true});
  }

  try{ Bus.dispatchEvent(new CustomEvent('cpu.ready')); }catch(_){}

})();
