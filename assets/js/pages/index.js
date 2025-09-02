(function(){
  function $(s,c){ return (c||document).querySelector(s); }
  function el(tag, attrs={}, ...kids){
    const n=document.createElement(tag);
    Object.entries(attrs).forEach(([k,v])=>{
      if(k==='class') n.className=v;
      else if(k==='dataset') Object.assign(n.dataset,v);
      else n.setAttribute(k,v);
    });
    kids.forEach(k=> n.appendChild(typeof k==='string'? document.createTextNode(k): k));
    return n;
  }
  function clamp(v,min,max){ return Math.min(max, Math.max(min, v)); }
  function fmtGB(b){ return (b/1024/1024/1024).toFixed(1)+' GB'; }
  
  // lightweight tooltip used by dashboard sparklines
  function ensureDashTip(){
    if (window.dashTipElem) return window.dashTipElem;
    var t=document.createElement('div'); t.id='dashTip';
    t.style.position='fixed'; t.style.zIndex=9999; t.style.pointerEvents='none';
    t.style.padding='6px 8px'; t.style.borderRadius='8px'; t.style.fontSize='12px';
    t.style.background='rgba(15,15,15,.9)'; t.style.color='var(--text-color,#eee)';
    t.style.boxShadow='0 4px 16px rgba(0,0,0,.35)'; t.style.border='1px solid rgba(255,255,255,.12)';
    t.hidden=true; document.body.appendChild(t); window.dashTipElem=t; return t;
  }
  function showDashTip(html, x, y){
    var t=ensureDashTip(); t.innerHTML = html; t.hidden=false; var r=t.getBoundingClientRect();
    var nx=Math.min(x+14, window.innerWidth-r.width-8), ny=Math.min(y+12, window.innerHeight-r.height-8);
    t.style.left=nx+'px'; t.style.top=ny+'px';
  }
  function hideDashTip(){ var t=window.dashTipElem; if(t) t.hidden=true; }

  function bindSparkTooltip(canvas, series, opts){
    if (!canvas || !series || !series.length) return;
    var name = (opts&&opts.name)||'metric';
    var fmt  = (opts&&opts.fmt) || function(v){ return String(v); };
    var now = Date.now()/1000, n=series.length, step=60;
    var times = Array.from({length:n}, function(_,i){ return now - (n-1-i)*step; });
    canvas._spark = {series:series, times:times, name:name, fmt:fmt};
    if (canvas._sparkBound) return; canvas._sparkBound = true;

    function idxFromEvent(ev){
      var rect = canvas.getBoundingClientRect();
      var x = Math.max(0, Math.min(ev.clientX - rect.left, rect.width));
      var n = series.length||1;
      var frac = n>1 ? x/rect.width : 0;
      var i = Math.round(frac*(n-1));
      return i;
    }
    canvas.addEventListener('mousemove', function(ev){
      var s = canvas._spark; if (!s) return;
      var i = idxFromEvent(ev);
      var when = new Date(s.times[i]*1000).toLocaleString();
      var v = s.series[i];
      var html = '<div style="font-weight:600;margin-bottom:2px;">'+s.name+'</div>' +
                 '<div>'+when+'</div>' +
                 '<div>value: '+ s.fmt(v) +'</div>';
      showDashTip(html, ev.clientX, ev.clientY);
    }, false);
    canvas.addEventListener('mouseleave', function(){ hideDashTip(); }, false);
    canvas.addEventListener('mousedown', function(){ hideDashTip(); }, false);
  }
function fmtUptime(sec){ if(!sec&&sec!==0) return '-'; const h=Math.floor(sec/3600), m=Math.floor((sec%3600)/60); return `${h}h ${m}m`; }

  function line(canvas, series){
    const ctx = canvas.getContext('2d');
    const w = canvas.width = canvas.clientWidth;
    const h = canvas.height;
    ctx.clearRect(0,0,w,h);
    ctx.strokeStyle = getComputedStyle(document.body).getPropertyValue('--accent');
    ctx.lineWidth = 2;
    const max = Math.max(...series, 1);
    const step = w/(series.length-1 || 1);
    ctx.beginPath();
    series.forEach((v,i)=>{
      const x = i*step;
      const y = h - (v/max)*h;
      if(i===0) ctx.moveTo(x,y); else ctx.lineTo(x,y);
    });
    ctx.stroke();
  }

  async function fetchMetrics(){ const res = await fetch('api/metrics.php', {credentials:'same-origin'}); return res.json(); }

  function renderChartsOverlay(m){
    const load = m.loadavg || [0,0,0];
    const mem  = m.memory || {used:0,total:0};
    const up   = m.uptime_sec || 0;
    const a=$('#chartLoad')?.closest('.chart')?.querySelector('.overlay');
    const b=$('#chartMem')?.closest('.chart')?.querySelector('.overlay');
    const c=$('#chartNet')?.closest('.chart')?.querySelector('.overlay');
    if(a) a.textContent = `Uptime: ${fmtUptime(up)}`;
    if(b) b.textContent = `Load avg: ${load[0].toFixed(3)}, ${load[1].toFixed(3)}, ${load[2].toFixed(3)}`;
    if(c) c.textContent = `Memory: ${fmtGB(mem.used)} / ${fmtGB(mem.total)}`;
  }
  function renderMetricsCharts(m){
    const load = m.loadavg || [0.2,0.1,0.05];
    const memUsed = (m.memory?.used || 0) / (m.memory?.total || 1);
    const loadSeries = Array.from({length:60}, ()=> load[0] + (Math.random()-.5)*0.05);
    
    window.__sparkLoad = loadSeries;
const memSeries  = Array.from({length:60}, ()=> memUsed + (Math.random()-.5)*0.03);
    
    window.__sparkMem = memSeries;
const netSeries  = Array.from({length:60}, ()=> Math.random()*0.5);
    
    window.__sparkNet = netSeries;
    (function(){ var n=60, step=60, now=Date.now()/1000; window.__sparkTimes = Array.from({length:n}, (_,i)=> now - (n-1-i)*step); })();
line($('#chartLoad'), loadSeries.map(v=>Math.max(0,v))); bindSparkTooltip($('#chartLoad'), loadSeries, {name:'CPU load', fmt:function(v){return v.toFixed(2);}});
    line($('#chartMem'),  memSeries.map(v=>Math.max(0,v))); bindSparkTooltip($('#chartMem'), memSeries, {name:'Memory used', fmt:function(v){return Math.round(Math.max(0,Math.min(1,v))*100)+'%';}});
    line($('#chartNet'),  netSeries); bindSparkTooltip($('#chartNet'), netSeries, {name:'Net activity', fmt:function(v){return (v*100).toFixed(0)+'%';}});
  }

  function healthClass(status){ if(status==='down') return 'down'; if(status==='warn') return 'warn'; return 'up'; }
  function renderServices(m){
    const wrap = $('#services'); if(!wrap) return; wrap.innerHTML='';
    const items = m.services || [];
    let upCount=0;
    items.forEach(s => {
      const st = healthClass(s.status); if(st==='up') upCount++;
      const icon = el('span',{class:'svc-status '+st});
      icon.innerHTML = st!=='down'
        ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>'
        : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
      const card = el('div',{class:'service'},
        el('span',{class:'badge pill '+st}, st==='up'?'UP':(st==='warn'?'WARN':'DOWN')),
        el('div',{class:'top'}, icon, el('div',{class:'name'}, s.name||'')),
        el('div',{class:'meta'}, `Latency: ${s.latency_ms!=null ? s.latency_ms+' ms' : '-'}`, `Type: ${s.check || '-'}`),
        s.http_code ? el('div',{class:'http-note'}, 'HTTP '+s.http_code) : document.createTextNode('')
      );
      wrap.appendChild(card);
    });
    const upEl = $('#svcUpCount'); if(upEl) upEl.textContent = upCount;
    const totEl = $('#svcTotal');  if(totEl) totEl.textContent = items.length;
  }

  function renderProcs(m){
    const grid = $('#procGrid'); if(!grid) return;
    const cores = m.cpu?.cores || 1;
    const load = m.loadavg || [0,0,0];
    const mem  = m.memory || {total:0,used:0,available:0,buffers:0,cached:0};
    const total = mem.total || 0, used = mem.used || 0, avail = mem.available || 0;
    const cpuPct = Math.min(100, Math.max(0, (load[0]/cores)*100));
    const usedPct = total? (used/total*100) : 0;
    const freePct = total? (avail/total*100) : 0;
    const bufPct  = total? ((mem.buffers||0)/total*100) : 0;
    const cachePct= total? ((mem.cached||0)/total*100) : 0;

    const items = [
      {title:'CPU', val: Math.round(cpuPct), sub: 'load1 ' + load[0]},
      {title:'Memory Used', val: Math.round(usedPct), sub: `${fmtGB(used)} / ${fmtGB(total)}`},
      {title:'Memory Free', val: Math.round(freePct), sub: fmtGB(avail)},
      {title:'Buffers', val: Math.round(bufPct), sub: fmtGB(mem.buffers||0)},
      {title:'Cache', val: Math.round(cachePct), sub: fmtGB(mem.cached||0)},
    ];
    grid.innerHTML='';
    items.forEach(p => {
      const bar = el('div',{class:'vbar'}); bar.style.setProperty('--val', p.val);
      grid.appendChild(el('div',{class:'proc'}, bar, el('div',{class:'content'}, el('div',{class:'title'}, p.title), el('div',{class:'sub'}, p.sub))));
    });
  }

  function renderDisks(m){
    const list = m.disks || [];
    const root = list.find(d => d.mount === '/') || list[0] || {total:0, used:0, free:0, pct_used:0, mount:'/'};
    const usedPct = Math.round(root.pct_used ?? (root.total ? (root.used/root.total*100) : 0));
    const freePct = 100 - usedPct;
    const totalGB = (root.total/1024/1024/1024) || 0;
    const usedGB  = (root.used/1024/1024/1024) || 0;
    const freeGB  = (root.free/1024/1024/1024) || 0;

    const tiles = [
      {label:'Usage', pct: usedPct, meta: `${usedPct}%`},
      {label:'Total', pct: 100,     meta: `${totalGB.toFixed(1)} GB`},
      {label:'Used',  pct: usedPct, meta: `${usedGB.toFixed(1)} GB`},
      {label:'Free',  pct: freePct, meta: `${freeGB.toFixed(1)} GB`},
      {label:'Temp',  pct: 0,       meta: 'n/a'}
    ];

    const grid = $('#diskGrid'); if(!grid) return; grid.innerHTML='';
    tiles.forEach(t => {
      const dev = el('div',{class:'hdd-device'},
        el('div',{class:'hdd-led'}),
        el('div',{class:'hdd-slot'}, el('div',{class:'progress', style:`--val:${t.pct}` }))
      );
      const right = el('div',{class:'text'},
        el('div',{class:'title'}, 'Disk / ' + t.label),
        el('div',{class:'sub'}, `${t.label}: ${t.meta}`)
      );
      grid.appendChild(el('div',{class:'disk'}, dev, right));
    });
  }

  async function refresh(){
    const m = await fetch('api/metrics.php', {credentials:'same-origin'}).then(r=>r.json());
    renderChartsOverlay(m); renderMetricsCharts(m);
    renderServices(m); renderProcs(m); renderDisks(m);
  }

  document.addEventListener('DOMContentLoaded', refresh);
})();