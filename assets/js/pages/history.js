(function(){
  'use strict';
  function $(s, ctx){ return (ctx||document).querySelector(s); }
  function $all(s, ctx){ return Array.prototype.slice.call((ctx||document).querySelectorAll(s)); }
  function on(el, ev, fn, opts){ if(el) el.addEventListener(ev, fn, opts||false); }
  function throttle(fn, ms){ let t=0; return function(){ const n=Date.now(); if(n-t>ms){ t=n; fn.apply(this, arguments); } }; }

  
  
  

  function parseLooseJSON(txt){
    try { return JSON.parse(txt); } catch(e){}
    var s=(txt||'').replace(/^\uFEFF/, '').trim(); var i=s.indexOf('{'); var j=s.lastIndexOf('}');
    if (i>=0 && j>i){ try { return JSON.parse(s.slice(i,j+1)); } catch(e){} }
    return null;
  }
  function sinceFromRange(val){
    var now = Math.floor(Date.now()/1000);
    var map = {'1h':3600,'24h':86400,'7d':604800,'30d':2592000};
    return now - (map[val]||86400);
  }
  function rangeDuration(val){
    var map = {'1h':3600,'24h':86400,'7d':604800,'30d':2592000};
    return map[val]||86400;
  }
  function escapeHtml(s){ return (s==null?'':String(s)).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]; }); }
  function fmtDT(ts){ try{ return new Date((ts|0)*1000).toLocaleString(); }catch(e){ return String(ts); } }
  function fmtAgo(ts){
    if (!ts) return '—';
    var s = Math.max(0, Math.floor(Date.now()/1000) - (ts|0));
    if (s < 45) return 'just now';
    var m = Math.floor(s/60); if (m < 60) return m+'m ago';
    var h = Math.floor(m/60); if (h < 24) return h+'h ago';
    var d = Math.floor(h/24); return d+'d ago';
  }

  function sizeCanvas(cv){
    var dpr = window.devicePixelRatio || 1;
    var cssW = Math.max(220, Math.floor(cv.clientWidth || 320));
    var cssH = 56;
    cv.width  = Math.floor(cssW * dpr);
    cv.height = Math.floor(cssH * dpr);
    cv.style.width  = cssW + 'px';
    cv.style.height = cssH + 'px';
    return {W:cv.width,H:cv.height,dpr:dpr, cssW:cssW, cssH:cssH};
  }
  function drawSpark(cv, series, okBad, showBaseline, crossX, selX1, selX2){
    var d = sizeCanvas(cv); var ctx=cv.getContext('2d'); var W=d.W,H=d.H;
    ctx.clearRect(0,0,W,H);
    if (!series || !series.length) return;
    var min = Math.min.apply(null, series), max = Math.max.apply(null, series); if (max===min) max=min+1;
    var x = i => Math.round(i*(W-4)/Math.max(1,series.length-1))+2;
    var y = v => H - Math.round((v-min)/(max-min)*(H-4)) - 2;
    ctx.lineWidth = 1*d.dpr; ctx.lineJoin='round'; ctx.lineCap='round';
    if (showBaseline){
      ctx.strokeStyle = getComputedStyle(cv).getPropertyValue('--muted-color') || 'rgba(127,127,127,.25)';
      ctx.beginPath(); ctx.moveTo(0,H-1); ctx.lineTo(W,H-1); ctx.stroke();
    }
    var lastOk = okBad && okBad.length ? okBad[okBad.length-1] : true;
    var color = lastOk ? (getComputedStyle(cv).getPropertyValue('--good-color') || '#22a559')
                       : (getComputedStyle(cv).getPropertyValue('--bad-color')  || '#d95140');
    ctx.strokeStyle = (color && color.trim()) ? color.trim() : '#22a559';
    ctx.beginPath(); ctx.moveTo(x(0), y(series[0])); for (var i=1;i<series.length;i++) ctx.lineTo(x(i), y(series[i])); ctx.stroke();

    if (typeof selX1==='number' && typeof selX2==='number'){
      var a=Math.min(selX1,selX2), b=Math.max(selX1,selX2);
      ctx.save(); ctx.fillStyle='rgba(100,150,255,.15)'; ctx.strokeStyle='rgba(120,170,255,.55)';
      ctx.fillRect(a,0,b-a,H); ctx.strokeRect(a+0.5,0.5,b-a-1,H-1); ctx.restore();
    }
    if (typeof crossX === 'number'){
      ctx.save(); ctx.strokeStyle = 'rgba(255,255,255,.25)'; ctx.beginPath(); ctx.moveTo(crossX, 0); ctx.lineTo(crossX, H); ctx.stroke(); ctx.restore();
    }
  }

  var _CACHE = {probes:[], alerts:[]};
  var ZOOM = null;
  var CURRENT_RANGE = '24h';
  var LAST_PROBE_TS = null;

  function datasetBounds(){
    var minT=Infinity, maxT=-Infinity;
    for (var i=0;i<_CACHE.probes.length;i++){ var t=_CACHE.probes[i].ts|0; if(t){ if(t<minT)minT=t; if(t>maxT)maxT=t; } }
    if (!isFinite(minT) || !isFinite(maxT)){ var now=Math.floor(Date.now()/1000); return {min:now-3600, max:now}; }
    return {min:minT, max:maxT};
  }

  function ensureMetaChips(){
    var root = $('#history-root'); if (!root) return;
    var bar = root.querySelector('.toolbar'); if(!bar) return;
    var id='metaChipsWrap'; var ex = document.getElementById(id);
    if (ex) return ex;
    var w = document.createElement('div', 'card'); w.id=id; w.className='row gap-sm'; w.style.marginLeft='auto';
    w.innerHTML =
      '<span id="zoomBadge" class="chip small" hidden></span>' +
      '<span id="lastProbeChip" class="chip small" hidden title=""></span>' +
      '<button id="zoomClearBtn" class="btn micro secondary" hidden>Clear zoom</button>';
    bar.appendChild(w);
    on($('#zoomClearBtn'), 'click', function(){ ZOOM=null; updateMetaChips(); rerenderFromCache(); window.toast.info('Zoom cleared'); });
    return w;
  }
  function updateMetaChips(){
    ensureMetaChips();
    var zb = $('#zoomBadge'), cb = $('#zoomClearBtn'), lp = $('#lastProbeChip');
    if (ZOOM && zb && cb){
      var fmt = (t)=> new Date(t*1000).toLocaleString();
      zb.textContent = 'Zoom: ' + fmt(ZOOM.from) + ' → ' + fmt(ZOOM.to);
      zb.hidden = false; cb.hidden = false;
    }else{
      if (zb) zb.hidden = true;
      if (cb) cb.hidden = true;
    }
    if (lp){
      if (LAST_PROBE_TS){
        lp.textContent = 'Last probe: ' + fmtAgo(LAST_PROBE_TS);
        lp.title = fmtDT(LAST_PROBE_TS);
        lp.hidden = false;
      } else {
        lp.hidden = true;
      }
    }
  }

  function renderCards(items, since){
    var wrap = $('#cardsGrid'); if (!wrap) return;
    wrap.innerHTML='';
    var byId = {};
    (items||[]).forEach(function(it){ var id=it.id||'unknown'; (byId[id]||(byId[id]=[])).push(it); });
    var showBaseline = !!($('#baselineToggle') && $('#baselineToggle').checked);

    Object.keys(byId).sort().forEach(function(id){
      var arrAll = byId[id].sort(function(a,b){return a.ts-b.ts;});
      var arr = ZOOM
        ? arrAll.filter(function(it){ var t=it.ts|0; return t>=ZOOM.from && t<=ZOOM.to; })
        : arrAll.filter(function(it){ return (it.ts|0) >= since; });
      var usingFallback = false;
      if (!arr.length && arrAll.length) { arr = arrAll.slice(-40); usingFallback = true; }
      var last = arr[arr.length-1] || arrAll[arrAll.length-1] || {};
      var name = last.name || id;

      var times = arr.map(function(x){ return (x.ts|0); });
      var lat = arr.map(function(x){ return Number(x.latency_ms||0); });
      var oks = arr.map(function(x){ return (x.status==='up'||x.ok===1||x.ok===true)?1:0; });
      var codes = arr.map(function(x){ return x.http_code||''; });

      var subtitle = (last.status || (last.ok ? 'up' : 'down'));
      if (last.http_code) subtitle += ' · HTTP ' + last.http_code;
      subtitle += ' · ' + (last.latency_ms ?? 0) + ' ms';

      var card = document.createElement('div'); card.className='card';
      if (usingFallback) card.style.opacity = .75;

      var head = document.createElement('div'); head.className='row between align-center';
      var left = document.createElement('div'); left.innerHTML = '<strong>'+escapeHtml(name)+'</strong><span class="subtitle" style="margin-left:8px;">'+escapeHtml(subtitle)+'</span>';
      var right = document.createElement('div');
      var csvBtn = document.createElement('button'); csvBtn.className='btn secondary micro'; csvBtn.textContent='CSV';
      right.appendChild(csvBtn);
      head.appendChild(left); head.appendChild(right);

      var body = document.createElement('div');
      var cv = document.createElement('canvas'); cv.className='spark';
      cv.title='wheel: zoom · shift+wheel: pan · drag: select · dblclick: reset';body.appendChild(cv);
      card.appendChild(head); card.appendChild(body);
      wrap.appendChild(card);

      cv._series = { times: times, lat: lat, oks: oks, codes: codes, name: name };

      drawSpark(cv, lat, oks.map(Boolean), showBaseline);

      function idxFromEvent(ev){
        var rect = cv.getBoundingClientRect();
        var x = Math.min(Math.max(ev.clientX - rect.left, 0), rect.width);
        var n = lat.length||1;
        var frac = n>1 ? x/rect.width : 0;
        return {i: Math.round(frac*(n-1)), px: x * (window.devicePixelRatio||1)};
      }

      on(cv, 'mousemove', throttle(function(ev){
        if (!lat.length) return;
        var p = idxFromEvent(ev); var i=p.i;
        var when = new Date(cv._series.times[i]*1000).toLocaleString();
        var v = lat[i]|0, ok = !!cv._series.oks[i], code = cv._series.codes[i] || '';
        var html = '<div style="font-weight:600;margin-bottom:2px;">'+escapeHtml(name)+'</div>' +
                   '<div>'+escapeHtml(when)+'</div>' +
                   '<div>latency: <b>'+v+' ms</b>'+(code? ' · HTTP '+escapeHtml(String(code)):'')+'</div>' +
                   '<div>status: '+(ok?'<span style="color:#22a559">up</span>':'<span style="color:#d95140">down</span>')+'</div>';
        drawSpark(cv, lat, oks.map(Boolean), showBaseline, p.px, selPx1, selPx2);
        showTip(html, ev.clientX, ev.clientY);
      }, 16));
      on(cv, 'mouseleave', function(){ hideTip(); drawSpark(cv, lat, oks.map(Boolean), showBaseline); });

      var dragging=false, selPx1=null, selPx2=null, startPx=null, startIdx=null;
      on(cv, 'mousedown', function(ev){
        if (!lat.length) return;
        dragging=true; var p=idxFromEvent(ev); startPx=p.px; startIdx=p.i; selPx1=p.px; selPx2=p.px;
        drawSpark(cv, lat, oks.map(Boolean), showBaseline, null, selPx1, selPx2);
      });
      on(window, 'mousemove', function(ev){
        if (!dragging) return;
        var rect = cv.getBoundingClientRect();
        if (ev.clientY < rect.top-40 || ev.clientY > rect.bottom+40) return;
        var p=idxFromEvent(ev); selPx2=p.px;
        drawSpark(cv, lat, oks.map(Boolean), showBaseline, null, selPx1, selPx2);
      });
      on(window, 'mouseup', function(ev){
        if (!dragging) return;
        dragging=false;
        var end = idxFromEvent(ev); selPx2=end.px;
        drawSpark(cv, lat, oks.map(Boolean), showBaseline);
        var i1 = Math.max(0, Math.min(startIdx, end.i));
        var i2 = Math.max(0, Math.max(startIdx, end.i));
        if (i2-i1 < 2) return;
        var from = cv._series.times[i1], to = cv._series.times[i2];
        if (from === to) return;
        ZOOM = {from: from, to: to};
        updateMetaChips();
        rerenderFromCache();
        window.toast.info('Zoom applied');
      });

      on(cv, 'wheel', function(ev){
        if (!lat.length) return;
        ev.preventDefault();
        var now = Math.floor(Date.now()/1000);
        var dur = rangeDuration(CURRENT_RANGE);
        var windowMin = now - dur, windowMax = now;
        var bounds = datasetBounds();
        var curFrom = ZOOM ? ZOOM.from : Math.max(bounds.min, windowMin);
        var curTo   = ZOOM ? ZOOM.to   : Math.min(bounds.max, windowMax);
        var rect = cv.getBoundingClientRect();
        var frac = Math.min(Math.max((ev.clientX-rect.left)/rect.width,0),1);
        var pivot = Math.round(curFrom + frac*(curTo-curFrom));

        if (ev.shiftKey){
          var pan = Math.round((curTo-curFrom) * 0.10) * (ev.deltaY>0 ? 1 : -1);
          var nf = Math.max(bounds.min, curFrom + pan);
          var nt = Math.min(bounds.max, curTo + pan);
          var width = curTo-curFrom; if (nt-nf < width){ nf = nt - width; }
          ZOOM = {from:nf, to:nt}; updateMetaChips(); rerenderFromCache(); return;
        }
        var factor = (ev.deltaY < 0) ? 0.8 : 1.25;
        var newFrom = Math.round(pivot - (pivot - curFrom) * factor);
        var newTo   = Math.round(pivot + (curTo - pivot) * factor);
        var minSpan = 60;
        if (newTo - newFrom < minSpan){ var mid = Math.round((newFrom+newTo)/2); newFrom = mid - Math.floor(minSpan/2); newTo = mid + Math.ceil(minSpan/2); }
        newFrom = Math.max(bounds.min, Math.max(windowMin, newFrom));
        newTo   = Math.min(bounds.max, Math.min(windowMax, newTo));
        if (newTo <= newFrom) return;
        ZOOM = {from:newFrom, to:newTo}; updateMetaChips(); rerenderFromCache();
      }, {passive:false});
      // Double-click to reset zoom
      on(cv, 'dblclick', function(){
        ZOOM = null;
        updateMetaChips();
        rerenderFromCache();
        try{ window.toast.info('Zoom cleared'); }catch(_){}
      });
    

      on(csvBtn, 'click', function(){
        if (!lat.length) { window.toast.warn('No data'); return; }
        var lines = ['ts,iso,latency_ms,status,http_code,service'];
        for (var i=0;i<lat.length;i++){
          var t=cv._series.times[i], iso = new Date(t*1000).toISOString();
          var s = cv._series.oks[i] ? 'up' : 'down';
          var h = cv._series.codes[i] || '';
          lines.push([t, iso, lat[i], s, h, JSON.stringify(name)].join(','));
        }
        var blob = new Blob([lines.join('\n')], {type:'text/csv'});
        var a = document.createElement('a'); a.href = URL.createObjectURL(blob);
        a.download = (name||'service') + '_history.csv'; a.click();
        setTimeout(function(){ URL.revokeObjectURL(a.href); }, 1500);
      });
    });
  }

  function fetchText(url){ return fetch(url, { credentials:'include', cache:'no-store' }).then(r=>r.text().then(t=>({ok:r.ok, status:r.status, text:t}))); }
  function fetchJSON(url){ return fetchText(url).then(({ok, status, text})=>{ var j=parseLooseJSON(text); if(!ok && j && j.error) throw new Error(j.error+' (HTTP '+status+')'); if(!ok) throw new Error('HTTP '+status); return j||{}; }); }

  function renderAlerts(items){
    var tb = $('#alertsTable tbody'); if (!tb) return;
    tb.innerHTML='';
    var filt = items||[];
    if (ZOOM) filt = filt.filter(function(it){ var t=it.ts|0; return t>=ZOOM.from && t<=ZOOM.to; });
    filt.slice(-50).reverse().forEach(function(it){
      var tr = document.createElement('tr');
      var when = new Date((it.ts|0)*1000).toLocaleString();
      var cond = (it.metric||'')+' '+(it.op||'')+' '+(it.threshold??'');
      tr.innerHTML = '<td>'+escapeHtml(when)+'</td>'
                   + '<td>'+escapeHtml(it.service_name||it.service_id||'')+'</td>'
                   + '<td>'+escapeHtml(it.alert_name||'')+'</td>'
                   + '<td>'+escapeHtml(cond)+'</td>'
                   + '<td>'+escapeHtml(String(it.value??''))+'</td>'
                   + '<td>'+escapeHtml(it.severity||'')+'</td>';
      tb.appendChild(tr);
    });
  }

  function setBusy(btn, on){ if(!btn) return; btn._origText = btn._origText || btn.textContent; btn.disabled = !!on; btn.textContent = on ? (btn.dataset.busyText||'Working…') : btn._origText; }

  function load(range){
    var root = $('#history-root'); if (!root) return;
    CURRENT_RANGE = range||CURRENT_RANGE;
    var since = sinceFromRange(CURRENT_RANGE);
    var probesUrl = root.dataset.exportProbes + '&limit=1600&start=' + since + '&_=' + Date.now();
    var alertsUrl = root.dataset.exportAlerts + '&limit=600&start=' + since + '&_=' + Date.now();
    var cronUrl = 'api/cron_health.php';
    var diag = $('#diagBox'); if (diag) diag.textContent = 'Loading…';
    return Promise.all([ fetchJSON(probesUrl), fetchJSON(alertsUrl), fetchJSON(cronUrl) ]).then(function(res){
      _CACHE.probes = (res[0] && res[0].items) ? res[0].items : [];
      _CACHE.alerts = (res[1] && res[1].items) ? res[1].items : [];
      // infer last probe ts from dataset and cron marker
      var maxT = 0; for (var i=0;i<_CACHE.probes.length;i++){ var t=_CACHE.probes[i].ts|0; if(t>maxT) maxT=t; }
      if (maxT) LAST_PROBE_TS = maxT;
      try { var ch = res[2] || {}; if (ch && ch.history_ts) { LAST_PROBE_TS = Math.max(LAST_PROBE_TS||0, ch.history_ts|0); } } catch (_) {}
      try { var hd = res[3] || {}; if (hd && hd.last_ts) { LAST_PROBE_TS = Math.max(LAST_PROBE_TS||0, hd.last_ts|0); } } catch (_) {}
      updateMetaChips();

      renderCards(_CACHE.probes, since);
      renderAlerts(_CACHE.alerts);
      if (diag) diag.textContent = 'Probes: '+(_CACHE.probes.length|0)+' · Alerts: '+(_CACHE.alerts.length|0)+' · Updated '+new Date().toLocaleTimeString();
      window.toast.info('History loaded');
    }).catch(function(e){
      if (diag) diag.textContent = 'Load failed';
      window.toast.error('Load failed: '+e.message);
    });
  }

  function rerenderFromCache(){
    var since = sinceFromRange(CURRENT_RANGE);
    renderCards(_CACHE.probes, since);
    renderAlerts(_CACHE.alerts);
  }

  function wire(){
    var selInit = document.querySelector('#rangeSelect');
    if (selInit) { CURRENT_RANGE = selInit.value || CURRENT_RANGE; }
    ensureMetaChips(); updateMetaChips();
    var sel = $('#rangeSelect'); var baseline = $('#baselineToggle');
    var root = $('#history-root');

    function reload(btn){ if(btn) setBusy(btn, true); ZOOM=null; updateMetaChips(); load(sel ? sel.value : '24h').finally(function(){ if(btn) setBusy(btn, false); }); }
    on(sel, 'change', function(){ CURRENT_RANGE = sel.value||'24h'; rerenderFromCache(); reload(); });
    on(baseline, 'change', rerenderFromCache);

    var toolbar = root.querySelector('.toolbar') || document;
    on(toolbar, 'click', function(ev){
      var t = ev.target.closest('button'); if (!t) return;
      var txt = (t.textContent||'').trim().toLowerCase();
      var act = (t.dataset.action||'').toLowerCase();
      if (t.id==='probeBtn' || act==='probe-now' || txt==='probe now'){
        ev.preventDefault();
        var url = root.dataset.probeEval; if (!url) { window.toast.error('No probe URL'); return; }
        setBusy(t, true);
        var diag = $('#diagBox'); if (diag) diag.textContent = 'Probing…';
        fetchText(url).then(function(res){
          var j = parseLooseJSON(res.text) || {};
          if (res.ok && !j.error){
            LAST_PROBE_TS = Math.floor(Date.now()/1000);
            updateMetaChips();
            window.toast.success('Probe kicked');
          } else {
            throw new Error((j && j.error) ? j.error : ('HTTP '+res.status));
          }
        }).catch(function(e){ window.toast.error('Probe failed: '+e.message); })
          .finally(function(){ setBusy(t, false); reload(); });
      }
      
      if (t.id==='resetHistoryBtn' || act==='reset-history' || txt==='reset history'){
        ev.preventDefault();
        if (!confirm('Reset probe & alert history now? This archives current logs.')) return;
        setBusy(t, true);
        fetchJSON('api/history_rotate.php').then(function(j){
          if (j && j.ok){ window.toast.success('History rotated'); }
          else { throw new Error((j && j.error) ? j.error : 'Rotate failed'); }
        }).catch(function(e){ window.toast.error(e.message || 'Rotate failed'); })
          .finally(function(){ setBusy(t, false); reload(); });
        return;
      }
    if (t.id==='refreshBtn' || act==='refresh' || txt==='refresh'){
        ev.preventDefault();
        reload(t);
      }
      if (t.id==='exportProbesBtn' || act==='export-probes'){
        ev.preventDefault(); doExport('probes');
      }
      if (t.id==='exportAlertsBtn' || act==='export-alerts'){
        ev.preventDefault(); doExport('alerts');
      }
    });

    function doExport(which){
      var url = which==='alerts' ? root.dataset.exportAlerts : root.dataset.exportProbes;
      fetchJSON(url + '&limit=2000&start=0').then(function(j){
        var modal = $('#exportModal'), pre = $('#exportPre');
        if (!modal){ var blob = new Blob([JSON.stringify(j, null, 2)], {type:'application/json'});
          var a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = which+'_export.json'; a.click();
          setTimeout(function(){ URL.revokeObjectURL(a.href); }, 2000); return; }
        pre.textContent = JSON.stringify(j, null, 2);
        modal.hidden = false;
        on($('#exportClose'), 'click', function(){ modal.hidden = true; }, {once:true});
        on($('#exportCopy'), 'click', function(){ navigator.clipboard.writeText(pre.textContent).then(function(){ window.toast.success('Copied'); }); }, {once:true});
        on($('#exportDownload'), 'click', function(){
          var blob = new Blob([pre.textContent], {type:'application/json'});
          var a = document.createElement('a'); a.href = URL.createObjectURL(blob);
          a.download = which+'_export.json'; a.click(); setTimeout(function(){ URL.revokeObjectURL(a.href); }, 2000);
        }, {once:true});
      }).catch(function(e){ window.toast.error('Export failed: '+e.message); });
    }

    on(window, 'resize', throttle(rerenderFromCache, 250));
    on(window, 'keydown', function(ev){ if (ev.key === 'Escape' && ZOOM){ ZOOM=null; updateMetaChips(); rerenderFromCache(); window.toast.info('Zoom cleared'); } });

    load(sel ? sel.value : '24h');
  }

  // Tooltip impl (shared)
  function ensureTip(){ if (window.historyTipElem) return window.historyTipElem;
    var t=document.createElement('div'); t.id='historyTip'; t.style.position='fixed'; t.style.zIndex=9999; t.style.pointerEvents='none';
    t.style.padding='6px 8px'; t.style.borderRadius='8px'; t.style.fontSize='12px'; t.style.background='rgba(15,15,15,.9)';
    t.style.color='var(--text-color,#eee)'; t.style.boxShadow='0 6px 18px rgba(0,0,0,.35)'; t.style.border='1px solid rgba(255,255,255,.12)';
    t.hidden=true; document.body.appendChild(t); window.historyTipElem=t; return t; }
  function showTip(html,x,y){ var t=ensureTip(); t.innerHTML=html; t.hidden=false; const r=t.getBoundingClientRect();
    const nx=Math.min(x+14, window.innerWidth-r.width-8), ny=Math.min(y+14, window.innerHeight-r.height-8); t.style.left=nx+'px'; t.style.top=ny+'px'; }
  function hideTip(){ var t=window.historyTipElem; if(t) t.hidden=true; }

  if (document.readyState==='loading') document.addEventListener('DOMContentLoaded', wire);
  else wire();
})();
