(function(){
  const API_TICK = 'api/services_probe_all.php';
  const API_GET  = 'api/autoprobe_get.php';
  const API_SET  = 'api/autoprobe_set.php';
  const KEY = 'gw.autoprobe';
  let timer = null;
  let savingTimer = null;

  function readLocal(){ try{ return JSON.parse(localStorage.getItem(KEY)) || {}; }catch{ return {}; } }
  function writeLocal(cfg){ localStorage.setItem(KEY, JSON.stringify(cfg)); }

  async function fetchServerCfg(){
    try{
      const r = await fetch(API_GET, {cache:'no-store'});
      if(!r.ok) throw 0;
      const s = await r.json();
      return { enabled: !!s.enabled, interval: Math.max(5, parseInt(s.interval||60,10)) };
    }catch(e){
      return null;
    }
  }
  async function saveServerCfg(cfg){
    if (savingTimer) clearTimeout(savingTimer);
    savingTimer = setTimeout(async ()=>{
      try{
        await fetch(API_SET, {
          method:'POST',
          headers:{'Content-Type':'application/json'},
          body: JSON.stringify({ enabled: !!cfg.enabled, interval: Math.max(5, parseInt(cfg.interval||60,10)) })
        });
      }catch(e){}
    }, 250);
  }

  function readCfgSync(){
    const local = readLocal();
    if (typeof local.enabled !== 'boolean') local.enabled = true;
    if (!local.interval || local.interval<5) local.interval = 60;
    return local;
  }

  async function initCfg(){
    const local = readCfgSync();
    const server = await fetchServerCfg();
    const cfg = server ? server : local;
    writeLocal(cfg);
    window.dispatchEvent(new CustomEvent('autoprobe:config', { detail: cfg }));
    return cfg;
  }

  async function tick(){
    try{
      const r = await fetch(API_TICK);
      const data = await r.json();
      window.dispatchEvent(new CustomEvent('services:probeUpdate', { detail: data }));
      const sum = {up:0,warn:0,down:0,total:0, ts: Date.now()};
      (data.results||[]).forEach(x=>{
        sum.total++;
        if (x.status==='up') sum.up++;
        else if (x.status==='warn') sum.warn++;
        else sum.down++;
      });
      window.dispatchEvent(new CustomEvent('services:summary', { detail: sum }));
    }catch(e){}
  }

  function start(cfg){
    stop();
    if (!cfg || !cfg.enabled) return;
    const ms = Math.max(5, parseInt(cfg.interval||60,10)) * 1000;
    timer = setInterval(tick, ms);
    tick();
  }
  function stop(){ if (timer){ clearInterval(timer); timer = null; } }

  window.AutoProbe = {
    get: readCfgSync,
    async set(cfg){
      const curr = readCfgSync();
      const next = { enabled: !!cfg.enabled, interval: Math.max(5, parseInt(cfg.interval||curr.interval||60,10)) };
      writeLocal(next);
      saveServerCfg(next);
      window.dispatchEvent(new CustomEvent('autoprobe:config', { detail: next }));
      start(next);
    },
    restart(){ start(readCfgSync()); },
    stop
  };

  window.addEventListener('storage', (e)=>{ if (e.key === KEY) start(readCfgSync()); });
  initCfg().then(start);
})();