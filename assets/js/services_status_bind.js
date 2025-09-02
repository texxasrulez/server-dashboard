(function(){
  let FETCH_CTRL = null;
  function abortFetch(){ try{ FETCH_CTRL && FETCH_CTRL.abort(); }catch(_){} FETCH_CTRL = null; }
  function newCtrl(){ abortFetch(); try{ FETCH_CTRL = new AbortController(); }catch(_){ FETCH_CTRL = null; } return FETCH_CTRL; }

  const STATUS_API = 'api/services_status.php';
  const LIST_API   = 'api/services_list.php';
  const DEBUG = /[?&]debugStatus=1/.test(location.search);

  const STICKY_MS = 1200;
  const APPLY_DEBOUNCE_MS = 80;

  let ENABLED_IDS = null;

  function now(){ return Date.now(); }
  function norm(v){ return (v||'').toString().trim().toLowerCase(); }

  function itemsFrom(data){
    if (!data) return [];
    if (Array.isArray(data.results)) return data.results;
    if (Array.isArray(data.items))   return data.items;
    if (Array.isArray(data.list))    return data.list;
    if (Array.isArray(data.services))return data.services;
    return [];
  }
  function rootTs(data){
    if (!data) return null;
    const t = data.ts || data.timestamp || null;
    if (typeof t==='number') return t < 2e12 ? t*1000 : t;
    return null;
  }

  function ynTrue(v){
    if (v === true) return true;
    if (typeof v === 'number') return v > 0;
    if (typeof v === 'string'){
      const s = v.trim().toLowerCase();
      return s === '1' || s === 'true' || s === 'on' || s === 'yes' || s === 'y' || s === 't';
    }
    return false;
  }

  async function ensureEnabledSet(){
    if (ENABLED_IDS) return;
    try{
      const r = await fetch(LIST_API, {cache:'no-store'}, (typeof LIST_API, {cache:'no-store'} === 'object' ? LIST_API, {cache:'no-store'} : {}));
      if (!r.ok) throw 0;
      const data = await r.json();
      const arr = itemsFrom(data);
      ENABLED_IDS = new Set();
      for (const it of arr){
        if (it && (it.disabled!=null ? !ynTrue(it.disabled) :
                   (it.enabled!=null ? ynTrue(it.enabled) :
                   (it.active!=null ? ynTrue(it.active) : true)))) {
          if (it.id != null) ENABLED_IDS.add(String(it.id));
        }
      }
      if (DEBUG) console.debug('enabled set', ENABLED_IDS);
    }catch(e){
      ENABLED_IDS = null;
    }
  }

  function pillEl(card){ return card.querySelector('.pill,[data-role=\"svc-pill\"]'); }
  function latEl(card){ return card.querySelector('.svc-latency,[data-role=\"svc-latency\"]'); }

  function extractLatency(o){
    if (!o || typeof o !== 'object') return null;
    if (o.latency_ms!=null) return Number(o.latency_ms);
    if (o.latency!=null)    return Number(o.latency);
    if (o.time_ms!=null)    return Number(o.time_ms);
    if (o.rt!=null)         return Number(o.rt);
    return null;
  }
  function extractStatus(o){
    const lat = extractLatency(o);
    const s = norm(o?.status || o?.state || o?.result || o?.health);
    if (s) {
      if (/^(up|ok|online|open|alive|running|passing|pass|success|good)$/.test(s)) return {state:'up', latency:lat};
      if (/^(down|fail|failed|closed|offline|error|dead|critical)$/.test(s)) return {state:'down', latency:lat};
      if (/^(warn|warning|degraded|slow|partial)$/.test(s)) return {state:'warn', latency:lat};
    }
    const code = Number(o?.code || o?.http_code || o?.status_code);
    if (!Number.isNaN(code) && code>0){
      if (code>=200 && code<400) return {state:'up', latency:lat};
      if (code>=500) return {state:'down', latency:lat};
      return {state:'warn', latency:lat};
    }
    if (typeof o?.up === 'boolean')    return {state:o.up?'up':'down', latency:lat};
    if (typeof o?.ok === 'boolean')    return {state:o.ok?'up':'down', latency:lat};
    if (typeof o?.alive === 'boolean') return {state:o.alive?'up':'down', latency:lat};
    return {state:null, latency:lat};
  }
  function extractTs(o, fallback){
    let t = o && (o.ts || o.timestamp);
    if (typeof t==='number' && t < 2e12) t = t*1000;
    return t || fallback || now();
  }

  function getCards(){
    let nodes = Array.from(document.querySelectorAll('#services .service-card, #services .svc-card, #services [data-svc-id], #services [data-svc-name], #services [data-role=\"svc-card\"]'));
    if (nodes.length) return nodes;
    const pills = document.querySelectorAll('#services .pill');
    const set = new Set();
    pills.forEach(p => {
      const host = p.closest('[data-svc-id], [data-svc-name], .service-card, .svc-card, .card, li, div');
      if (host) set.add(host);
    });
    return Array.from(set);
  }
  function keyForCard(card){
    const id = card.getAttribute('data-svc-id') || card.dataset.svcId || '';
    if (id) return String(id);
    const nm = card.getAttribute('data-svc-name') || card.dataset.svcName || (card.querySelector('.svc-name')?.textContent||'');
    return nm ? norm(nm) : null;
  }

  function enforceDisabledVisibility(card){
    const de = card.getAttribute('data-svc-enabled');
    if (de === '0' || de === 'false'){
      card.style.display = 'none';
      return false;
    }
    if (ENABLED_IDS){
      const id = card.getAttribute('data-svc-id') || '';
      if (id && !ENABLED_IDS.has(String(id))){
        card.style.display = 'none';
        return false;
      }
    }
    if (card.style.display === 'none') card.style.display = '';
    return true;
  }

  function setPill(card, state){
    const pill = pillEl(card); if (!pill) return;
    const cur = pill.classList.contains('up') ? 'up' :
                pill.classList.contains('down') ? 'down' :
                pill.classList.contains('warn') ? 'warn' : 'neutral';
    if (cur === state) return;
    pill.classList.remove('up','down','warn','neutral');
    let txt='--', cls='neutral';
    if (state==='up'){ txt='UP'; cls='up'; }
    else if (state==='down'){ txt='DOWN'; cls='down'; }
    else if (state==='warn'){ txt='WARN'; cls='warn'; }
    pill.classList.add(cls);
    pill.textContent = txt;
  }
  function setLatency(card, ms){
    const el = latEl(card); if (!el) return;
    const v = Math.round(Number(ms)||0);
    const next = v ? (v+' ms') : '';
    if (el.textContent !== next) el.textContent = next;
  }

  const last = new Map(); // key -> {state, at, ts}
  let qTimer = null, queued = null, lastBatch = null, observerStarted = false;

  function applyByKey(items, baseTs){
    const byKey = new Map();
    for (const it of items){
      const id = it.id || it.service_id || null;
      const name = it.name || it.service || it.svc || null;
      const key = id!=null ? String(id) : (name ? norm(name) : null);
      if (!key) continue;
      const {state, latency} = extractStatus(it);
      const ts = extractTs(it, baseTs);
      byKey.set(key, {state:state||'down', latency, ts});
    }

    const cards = getCards();
    let applied = 0;
    const tNow = now();
    for (const card of cards){
      if (!enforceDisabledVisibility(card)) continue;
      const key = keyForCard(card);
      if (!key) continue;
      const rec = byKey.get(key);
      if (!rec) continue;
      const prev = last.get(key);
      const change = prev && prev.state !== rec.state;
      const tooSoon = change && (tNow - prev.at < STICKY_MS);
      const stale = prev && prev.ts && rec.ts && rec.ts < prev.ts;
      if (stale || tooSoon) continue;
      setPill(card, rec.state);
      setLatency(card, rec.latency);
      last.set(key, {state:rec.state, at:tNow, ts:rec.ts||tNow});
      applied++;
    }
    return applied;
  }

  function orderAutowire(items){
    const cards = getCards();
    if (!items.length || !cards.length) return 0;
    if (Math.abs(items.length - cards.length) > 3) return 0;
    let applied = 0;
    const tNow = now();
    for (let i=0;i<Math.min(items.length, cards.length);i++){
      const it = items[i];
      const card = cards[i];
      if (!enforceDisabledVisibility(card)) continue;
      if (!card.hasAttribute('data-svc-id') && it.id){
        card.setAttribute('data-svc-id', String(it.id));
      }
      const {state, latency} = extractStatus(it);
      setPill(card, state||'down');
      setLatency(card, latency);
      const key = keyForCard(card);
      if (key) last.set(key, {state:state||'down', at:tNow, ts:extractTs(it)});
      applied++;
    }
    if (DEBUG) console.debug('order-autowire applied', applied, 'items');
    return applied;
  }

  function ensureObserver(){
    if (observerStarted) return;
    const container = document.getElementById('services');
    if (!container) return;
    try{
      const obs = new MutationObserver(()=>{
        if (lastBatch){
          scheduleApply(lastBatch.items, lastBatch.ts);
        }
      });
      obs.observe(container, {childList:true, subtree:true});
      observerStarted = true;
    }catch(e){}
  }

  function scheduleApply(items, ts){
    const baseTs = (typeof ts==='number' && ts<2e12) ? ts*1000 : ts;
    lastBatch = {items, ts: baseTs || now()};
    queued = lastBatch;
    if (qTimer) clearTimeout(qTimer);
    qTimer = setTimeout(async ()=>{
      await ensureEnabledSet();
      const batch = queued; queued = null;
      const done = applyByKey(batch.items, batch.ts);
      if (!done) orderAutowire(batch.items);
    }, APPLY_DEBOUNCE_MS);
    ensureObserver();
  }

  async function fetchAndApply(){
    try{
      const r = await fetch(STATUS_API, {cache:'no-store'}, (typeof STATUS_API, {cache:'no-store'} === 'object' ? STATUS_API, {cache:'no-store'} : {}));
      if (!r.ok) throw 0;
      const data = await r.json();
      const arr = itemsFrom(data);
      if (arr.length) scheduleApply(arr, rootTs(data));
      else if (DEBUG) console.warn('status: empty payload');
    }catch(e){
      if (DEBUG) console.warn('status fetch failed', e);
    }
  }

  document.addEventListener('DOMContentLoaded', fetchAndApply);
  window.addEventListener('dashboard:refresh', fetchAndApply);
  window.addEventListener('services:rendered', fetchAndApply);
  window.addEventListener('services:probeUpdate', (e)=>{
    const d = e && e.detail || {};
    const arr = Array.isArray(d.results) ? d.results : (Array.isArray(d.items)? d.items : []);
    if (arr.length) scheduleApply(arr, d.ts||d.timestamp||null);
    else fetchAndApply();
  });

  // Memory-friendly: avoid doing work when tab hidden; abort in-flight fetch and skip.
  document.addEventListener('visibilitychange', ()=>{ if (document.hidden) abortFetch(); });
  window.addEventListener('pagehide', ()=>{ abortFetch(); });

})();