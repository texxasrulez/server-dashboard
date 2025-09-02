(function(){
  const el = document.getElementById('sys-badge');
  if (!el) return;
  const txt = el.querySelector('.txt');

  function paint(kind, label){
    el.classList.remove('ok','warn','danger');
    el.classList.add(kind);
    txt.textContent = label;
  }
  function label(up, total){
    if (!total) return ['warn','Status unknown'];
    if (up===total) return ['ok', `All systems operational  ${up}/${total} up`];
    if (up>0)       return ['warn', `Degraded  ${up}/${total} up`];
    return ['danger', `Major outage  ${up}/${total} up`];
  }
  function norm(v){ return (v==null?'':String(v)).trim().toLowerCase(); }
  function idOf(x){ return norm(x.id || x.name || x.title); }
  function isEnabled(x){
    // Show on index if not explicitly disabled.
    // Your services_list.php sets enabled=true by default.
    if (x == null) return false;
    if (x.enabled === false) return false;
    if (String(x.enabled||'').toLowerCase() === 'false') return false;
    if (String(x.hidden||'').toLowerCase() === 'true') return false; // safety
    return true;
  }
  async function j(url){
    const r = await fetch(url+(url.includes('?')?'&':'?')+'cb='+Date.now(), {cache:'no-store'});
    if (!r.ok) throw new Error('HTTP '+r.status);
    return await r.json();
  }

  async function refresh(){
    try{
      // 1) Load the configured services (what index will render)
      const list = await j('api/services_list.php');
      const items = Array.isArray(list && list.items) ? list.items : [];
      const enabled = items.filter(isEnabled);
      const enabledMap = new Map(enabled.map(x=>[idOf(x), x]));

      let up = 0, total = enabled.length;

      // 2) Load the latest probe results and count only for enabled services
      try{
        const status = await j('api/services_status.php?trace=1');
        const results = Array.isArray(status && status.results) ? status.results
                       : Array.isArray(status && status.items)   ? status.items
                       : [];
        const resMap = new Map(results.map(x=>[idOf(x), x]));
        for (const [id, svc] of enabledMap){
          const rec = resMap.get(id);
          if (!rec) continue;
          const state = norm(rec.state || rec.status);
          const ok = rec.ok === true || rec.up === true ||
                     state === 'up' || state === 'ok' || state === 'running';
          if (ok) up++;
        }
      }catch(_){
        // Fallback: try state file directly
        try{
          const s = await j('state/services_status.json');
          const results = Array.isArray(s && s.results) ? s.results
                         : Array.isArray(s && s.items)   ? s.items
                         : [];
          const resMap = new Map(results.map(x=>[idOf(x), x]));
          for (const [id, svc] of enabledMap){
            const rec = resMap.get(id);
            if (!rec) continue;
            const state = norm(rec.state || rec.status);
            const ok = rec.ok === true || rec.up === true ||
                       state === 'up' || state === 'ok' || state === 'running';
            if (ok) up++;
          }
        }catch(__){ /* ignore */ }
      }

      const [kind, msg] = label(up, total);
      paint(kind, msg);
      el.title = msg;
    }catch(e){
      paint('warn','Status unknown');
    }
  }

  refresh();
  setInterval(refresh, 60000);
})();
