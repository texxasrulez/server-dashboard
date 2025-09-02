(function(){
  window.Dashboard = window.Dashboard || {};

  async function safeFetchJSON(url){
    try{
      const r = await fetch(url, {cache:'no-store'});
      if (!r.ok) throw new Error('HTTP '+r.status);
      return await r.json();
    }catch(e){ return null; }
  }

  window.Dashboard.refresh = async function(){
    // 1) trigger a fresh probe (non-blocking)
    safeFetchJSON('api/services_probe_all.php').then(data=>{
      if (data) window.dispatchEvent(new CustomEvent('services:probeUpdate',{detail:data}));
    });

    // 2) optional metrics endpoint if you add one
    // const metrics = await safeFetchJSON('api/metrics_summary.php');
    // if (metrics) window.dispatchEvent(new CustomEvent('metrics:update',{detail:metrics}));

    // 3) re-render gradient meters from current DOM values
    if (window.IndexProcessMeters && typeof window.IndexProcessMeters.update==='function'){
      window.IndexProcessMeters.update();
    }

    // 4) let other widgets hook in
    window.dispatchEvent(new Event('dashboard:refresh'));
  };

  document.addEventListener('visibilitychange', ()=>{
    if (document.visibilityState === 'visible'){
      setTimeout(()=> window.Dashboard.refresh(), 150);
    }
  });
})();    


  // --- Hide disabled services on index ---
  async function hideDisabledFromList(){
    try{
      const r = await fetch('api/services_list.php', {cache:'no-store'});
      if (!r.ok) return;
      const data = await r.json();
      const disabled = new Set((data.items||[]).filter(x=>!x.enabled).map(x=> x.id || (x.name||'').toLowerCase()+ '|' + (x.host||'') + '|' + (x.port||'')));
      const byName = new Set((data.items||[]).filter(x=>!x.enabled).map(x=> (x.name||'').toLowerCase()));
      document.querySelectorAll('[data-svc-id], [data-svc-name], .service-card').forEach(card=>{
        const id = card.getAttribute('data-svc-id');
        const nm = (card.getAttribute('data-svc-name') || card.querySelector('.svc-name')?.textContent || '').trim().toLowerCase();
        let shouldHide = false;
        if (id && disabled.has(id)) shouldHide = true;
        if (!shouldHide && nm && byName.has(nm)) shouldHide = true;
        card.toggleAttribute('hidden', shouldHide);
        if (shouldHide) card.classList.add('svc-disabled'); else card.classList.remove('svc-disabled');
      });
    }catch(e){}
  }

  (function(){
    const _orig = window.Dashboard.refresh;
    window.Dashboard.refresh = async function(){
      await _orig();
      hideDisabledFromList();
    };
  })();


// --- Improved: Hide disabled services on index (runs on load, refresh, and probe updates) ---
(function(){
  async function fetchDisabled(){
    try{
      const r = await fetch('api/services_list.php', {cache:'no-store'});
      if (!r.ok) return {byId:new Set(), byName:new Set()};
      const data = await r.json();
      const disabledItems = (data.items||[]).filter(x=>!x.enabled);
      const byId = new Set(disabledItems.map(x=> x.id).filter(Boolean));
      const byName = new Set(disabledItems.map(x=> (x.name||'').trim().toLowerCase()).filter(Boolean));
      return {byId, byName};
    }catch(e){ return {byId:new Set(), byName:new Set()}; }
  }

  function matchCards(){
    return Array.from(document.querySelectorAll('[data-svc-id], [data-svc-name], [data-svc], .service-card, .svc-card'));
  }

  async function hideDisabledServices(){
    const {byId, byName} = await fetchDisabled();
    if (!byId.size && !byName.size) return;

    matchCards().forEach(card=>{
      const id = card.getAttribute('data-svc-id');
      const nm = (card.getAttribute('data-svc-name') || card.querySelector('.svc-name')?.textContent || '').trim().toLowerCase();
      let hide = false;
      if (id && byId.has(id)) hide = true;
      if (!hide && nm && byName.has(nm)) hide = true;
      card.classList.toggle('svc-disabled', hide);
      if (hide) card.setAttribute('hidden', ''); else card.removeAttribute('hidden');
    });
  }

  document.addEventListener('DOMContentLoaded', ()=>{
    setTimeout(hideDisabledServices, 0);
  });
  window.addEventListener('dashboard:refresh', hideDisabledServices);
  window.addEventListener('services:probeUpdate', ()=> {
    clearTimeout(window.__hideDisabledDebounce__);
    window.__hideDisabledDebounce__ = setTimeout(hideDisabledServices, 150);
  });
})();

// --- Robust hide: use public enabled-ids endpoint, fallback to services_list ---
(function(){
  const SEL = '[data-svc-id], [data-svc-name], [data-svc], .service-card, .svc-card';

  async function getEnabled(){
    try{
      const r = await fetch('api/services_enabled_ids.php', {cache:'no-store'});
      if (r.ok) { return await r.json(); }
    }catch(e){}
    // fallback
    try{
      const r = await fetch('api/services_list.php', {cache:'no-store'});
      if (!r.ok) throw 0;
      const data = await r.json();
      const ids = [], names = [], triples = [];
      (data.items||[]).forEach(x=>{
        if (x.enabled){
          const id = x.id||null;
          const name=(x.name||'').trim().toLowerCase();
          const host=(x.host||'').trim().toLowerCase();
          const port=String(x.port||'');
          if (id) ids.push(id);
          if (name) names.push(name);
          triples.push(name+'|'+host+'|'+port);
        }
      });
      return {ids, names, triples};
    }catch(e){ return {ids:[], names:[], triples:[]}; }
  }

  let hideTimer=null;
  function queueHide(){ if(hideTimer) clearTimeout(hideTimer); hideTimer=setTimeout(applyHide, 80); }

  async function applyHide(){
    const en = await getEnabled();
    const enabledIds = new Set(en.ids||[]);
    const enabledNames = new Set((en.names||[]).map(s=> String(s).toLowerCase()));
    const enabledTriples = new Set(en.triples||[]);

    document.querySelectorAll(SEL).forEach(card=>{
      const id = card.getAttribute('data-svc-id') || card.dataset.svcId || '';
      const name = (card.getAttribute('data-svc-name') || card.dataset.svcName || (card.querySelector('.svc-name, .name')?.textContent||'')).trim().toLowerCase();
      const host = (card.getAttribute('data-svc-host') || card.dataset.svcHost || (card.querySelector('[data-svc-host]')?.textContent||'')).trim().toLowerCase();
      const portRaw = (card.getAttribute('data-svc-port') || card.dataset.svcPort || (card.querySelector('[data-svc-port]')?.textContent||'')).trim();
      const port = (/^\d{1,5}$/.test(portRaw) ? portRaw : (portRaw.match(/(\d{1,5})$/)?.[1] || ''));
      const triple = (name||'')+'|'+(host||'')+'|'+(port||'');

      let show = false;
      if (id && enabledIds.has(id)) show = true;
      if (!show && name && enabledNames.has(name)) show = true;
      if (!show && (host||port) && enabledTriples.has(triple)) show = true;

      card.toggleAttribute('hidden', !show);
      card.classList.toggle('svc-disabled', !show);
    });
  }

  if (!window.__applyHideWrap){
    window.__applyHideWrap = true;
    const _orig = window.Dashboard && window.Dashboard.refresh || (async function(){});
    window.Dashboard = window.Dashboard || {};
    window.Dashboard.refresh = async function(){
      await _orig();
      queueHide();
    };
  }

  window.addEventListener('services:probeUpdate', queueHide);
  window.addEventListener('dashboard:refresh', queueHide);
  document.addEventListener('DOMContentLoaded', queueHide);

  let __dashCleaned = false;
  function cleanup(){
    if (__dashCleaned) return;
    __dashCleaned = true;
    window.removeEventListener('services:probeUpdate', queueHide);
    window.removeEventListener('dashboard:refresh', queueHide);
    document.removeEventListener('DOMContentLoaded', queueHide);
  }
  document.addEventListener('visibilitychange', ()=>{ if (document.hidden) cleanup(); });
  window.addEventListener('pagehide', cleanup);

})();