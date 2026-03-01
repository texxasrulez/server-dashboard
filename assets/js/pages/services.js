(function(){
  const $  = (s,c)=> (c||document).querySelector(s);
  const $$ = (s,c)=> Array.from((c||document).querySelectorAll(s));

  const modal = $('#svcModal');
  const form  = $('#svcForm');

  const api = {
    list:   ()=> fetch('api/services_list.php').then(r=>{ if(!r.ok){ throw new Error('HTTP '+r.status); } return r.json(); }),
    upsert: (item)=> fetch('api/service_upsert.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(item)}).then(r=>{ if(!r.ok){ throw new Error('HTTP '+r.status); } return r.json(); }),
    del:    (id)=> fetch('api/service_delete.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({id})}).then(r=>{ if(!r.ok){ throw new Error('HTTP '+r.status); } return r.json(); }),
    probe:  (item)=> fetch('api/service_probe.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(item)}).then(r=>{ if(!r.ok){ throw new Error('HTTP '+r.status); } return r.json(); }),
    toggle: (id)=> fetch('api/service_toggle.php',{method:'POST',body:new URLSearchParams({id})}).then(r=>{ if(!r.ok){ throw new Error('HTTP '+r.status); } return r.json(); }),
    importJson: (payload)=> fetch('api/services_import.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)}).then(r=>{ if(!r.ok){ throw new Error('HTTP '+r.status); } return r.json(); }),
    importCsv:  (csvText)=> fetch('api/services_import.php?format=csv',{method:'POST',headers:{'Content-Type':'text/csv'},body:csvText}).then(r=>{ if(!r.ok){ throw new Error('HTTP '+r.status); } return r.json(); }),
    probeAll: ()=> fetch('api/services_probe_all.php').then(r=>{ if(!r.ok){ throw new Error('HTTP '+r.status); } return r.json(); }),
    silenceRules: (ids, minutes)=> fetch('api/alerts_bulk.php',{
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({action:'silence', ids: ids, silence_minutes: minutes})
    }).then(r=>{ if(!r.ok){ throw new Error('HTTP '+r.status); } return r.json(); })
  };
  const SERVICE_CACHE = new Map();

  // ---------- helpers ----------
  const isPort = (v)=> Number.isInteger(v) && v>=1 && v<=65535;

  function showAlert(msg){
    const el = $('#formAlert');
    if (!el) return;
    el.textContent = msg || '';
    el.hidden = !msg;
  }
  function clearErrors(){
    $$('.is-invalid').forEach(x=>x.classList.remove('is-invalid'));
    $$('.field-msg').forEach(x=>x.remove());
    showAlert('');
  }
  function setErr(input, msg){
    if (!input) return;
    input.classList.add('is-invalid');
    const small = document.createElement('div', 'card');
    small.className = 'field-msg';
    small.textContent = msg;
    if (input.parentElement) input.parentElement.appendChild(small);
  }

  function pill(status){
    const s = status==='up'?'up':(status==='warn'?'warn':'down');
    const span = document.createElement('span');
    span.className = 'chip '+s;
    span.textContent = s.toUpperCase();
    return span;
  }
  function relTime(ts){
    if (!ts) return '';
    if (ts > 1e12) ts = Math.floor(ts / 1000);
    const diff = Math.max(0, Math.floor(Date.now()/1000) - ts);
    if (diff < 45) return 'just now';
    const m = Math.floor(diff/60); if (m < 60) return m+'m ago';
    const h = Math.floor(m/60); if (h < 24) return h+'h ago';
    const d = Math.floor(h/24); return d+'d ago';
  }
  function badge(text, cls){
    const span = document.createElement('span');
    span.className = 'chip '+(cls||'neutral');
    span.textContent = text;
    return span;
  }

  // Sorting
  let sortKey = null;
  let sortDir = 1; // 1 asc, -1 desc
  function sortItems(items){
    if(!sortKey) return items;
    return items.slice().sort((a,b)=>{
      let va = a[sortKey]; let vb = b[sortKey];
      if (typeof va === 'string') va = va.toLowerCase();
      if (typeof vb === 'string') vb = vb.toLowerCase();
      if (va < vb) return -1*sortDir;
      if (va > vb) return  1*sortDir;
      return 0;
    });
  }
  $('#servicesTable thead')?.addEventListener('click', (e)=>{
    const th = e.target.closest('th.sortable'); if(!th) return;
    const key = th.dataset.sort;
    if (sortKey === key) sortDir = -sortDir; else { sortKey = key; sortDir = 1; }
    refresh();
  });

  function trTemplate(it){
    const tr = document.createElement('tr');
    tr.dataset.id = it.id;
    tr.innerHTML = `
      <td class="nowrap">
        <div class="nm">${it.name||''}</div>
        <div class="muted mini-result"></div>
      </td>
      <td>${it.type||'other'}</td>
      <td>${it.host||''}</td>
      <td class="center">${it.port||''}</td>
      <td class="center">${it.check||'tcp'}</td>
      <td class="center">${it.timeout_ms||800} ms</td>
      <td class="center">${it.check==='http' ? (it.path||'/') : '-'}</td>
      <td class="center enabled-pill ${it.enabled?'on':'off'}">
        <div class="cell-center">
          <nav class="tabs compact"><a href="#" data-act="toggle">${it.enabled?'On':'Off'}</a></nav>
        </div>
      </td>
      <td class="actions-tabs">
        <div class="cell-center">
          <nav class="tabs compact">
            <a href="#" data-act="test">Test</a>
            <a href="#" data-act="edit">Edit</a>
            <a href="#" data-act="del">Delete</a>
          </nav>
        </div>
      </td>`;
    return tr;
  }

  function openModal(editing=false, data=null){
    // Default values on "Add Service"
    try {
      const hostInput = document.getElementById('f_host');
      if (!editing && hostInput) {
        // Use placeholder if present, else fall back to 127.0.0.1
        const defHost = hostInput.getAttribute('placeholder') || '127.0.0.1';
        if (!hostInput.value) hostInput.value = defHost;
      }
    } catch(_){}

    clearErrors();
    $('#modalTitle').textContent = editing ? 'Edit Service' : 'Add Service';
    if (form) form.reset();
    $('#f_id').value      = data?.id || '';
    $('#f_name').value    = data?.name || '';
    $('#f_type').value    = data?.type || 'other';
    $('#f_host').value    = data?.host || '127.0.0.1';
    $('#f_port').value    = data?.port || '';
    $('#f_check').value   = data?.check || 'tcp';
    $('#f_timeout').value = (data?.timeout_ms ?? 800);
    $('#f_path').value    = data?.path || '';
    $('#f_enabled').checked = !!(data?.enabled ?? true);
    $('#f_testOnSave').checked = true;
    togglePath();
    modal.hidden = false;
    setTimeout(()=> $('#f_name')?.focus(), 0);
  }
  function closeModal(){ modal.hidden = true; }

  function togglePath(){
    const isHttp = $('#f_check').value === 'http';
    $('#f_path').disabled = !isHttp;
    $('#f_path').placeholder = isHttp ? '/health' : '(not used)';
  }
  $('#f_check')?.addEventListener('change', togglePath);

  async function refresh(){
    const tbody = $('#svcBody'); if(!tbody) return;
    tbody.innerHTML='';
    try {
      const data = await api.list();
      SERVICE_CACHE.clear();
      const items = sortItems(data.items||[]);
      items.forEach(it => {
        if (it && it.id) SERVICE_CACHE.set(String(it.id), it);
        const row = trTemplate(it);
        tbody.appendChild(row);
        decorateRow(row, it);
      });
    } catch (e) {
      console.error('Failed to load services', e);
    }
  }
  function decorateRow(row, svc){
    const mini = row.querySelector('.mini-result');
    if (!mini) return;
    mini.innerHTML = '';
    const meta = svc.status_meta || {};
    if (meta.status){
      mini.appendChild(pill(meta.status));
    }
    const details = [];
    if (meta.latency_ms != null) details.push(meta.latency_ms + ' ms');
    if (meta.http_code) details.push('HTTP ' + meta.http_code);
    if (meta.ts) details.push(relTime(meta.ts));
    if (details.length){
      const span = document.createElement('span');
      span.className = 'muted';
      span.textContent = ' ' + details.join(' · ');
      mini.appendChild(span);
    }
    const uptime = svc.uptime_meta;
    if (uptime && uptime.uptime_pct != null){
      mini.appendChild(badge('Uptime '+uptime.uptime_pct+'%', uptime.uptime_pct >= 95 ? 'ok' : (uptime.uptime_pct >= 80 ? 'warn' : 'down')));
    }
    const alertMeta = svc.alert_meta || {};
    if (alertMeta.last_alert && alertMeta.last_alert.ts){
      const last = alertMeta.last_alert;
      const chip = badge('Alert '+relTime(last.ts), 'warn');
      chip.title = (last.name||'') + ' ('+(last.severity||'warn')+')';
      mini.appendChild(chip);
    }
    if (alertMeta.silenced_until){
      const mute = badge('Muted '+relTime(alertMeta.silenced_until), 'neutral');
      mini.appendChild(mute);
    }
    const linkRow = document.createElement('div');
    linkRow.className = 'svc-links';
    if (svc.id){
      const hist = document.createElement('a');
      hist.className = 'chip neutral small';
      hist.textContent = 'History';
      hist.href = 'history.php?service=' + encodeURIComponent(svc.id);
      hist.target = '_blank';
      hist.rel = 'noopener';
      linkRow.appendChild(hist);
    }
    if (alertMeta.rule_ids && alertMeta.rule_ids.length){
      const muteBtn = document.createElement('a');
      muteBtn.href = '#';
      muteBtn.dataset.act = 'silence-service';
      muteBtn.dataset.rules = alertMeta.rule_ids.join(',');
      muteBtn.className = 'chip warn small';
      muteBtn.textContent = 'Mute alerts';
      linkRow.appendChild(muteBtn);
    }
    if (linkRow.childNodes.length) mini.appendChild(linkRow);
  }

  // Filter
  $('#svcSearch')?.addEventListener('input', (e)=>{
    const q = String((e && e.target && e.target.value) || '').toLowerCase().trim();
    $$('#svcBody tr').forEach(tr => { tr.hidden = q && !String(tr.textContent || '').toLowerCase().includes(q); });
  });

  // Table actions
  $('#servicesTable')?.addEventListener('click', async (e)=>{
    const a = e.target.closest('a[data-act]'); if(!a) return;
    e.preventDefault();
    const tr = a.closest('tr'); if(!tr) return;
    const id = tr.dataset.id;
    let it = SERVICE_CACHE.get(id);
    if (!it) {
      const list = await api.list();
      it = (list.items||[]).find(x=>x.id===id);
    }
    if (!it) return;

    const act = a.dataset.act;
    if(act==='edit'){
      openModal(true, it);
    } else if(act==='del'){
      if(confirm('Delete this service?')){ await api.del(id); refresh(); }
    } else if(act==='test'){
      try{ if (window.toast) toast.info('Probe kicked'); }catch(e){}
      const res = await api.probe(it);
      applyProbe(tr, res);
    } else if(act==='toggle'){
      const res = await api.toggle(id);
      if(res.item){
        const cell = tr.querySelector('.enabled-pill');
        cell.classList.toggle('on', !!res.item.enabled);
        cell.classList.toggle('off', !res.item.enabled);
        cell.querySelector('a[data-act="toggle"]').textContent = res.item.enabled ? 'On':'Off';
        SERVICE_CACHE.set(id, Object.assign({}, it, res.item));
      }
    } else if (act==='silence-service'){
      const ruleIds = (a.dataset.rules||'').split(',').map(s=>s.trim()).filter(Boolean);
      if (!ruleIds.length){ window.toast && window.toast.warn && window.toast.warn('No alert rules to mute.'); return; }
      const minsInput = prompt('Mute related alerts for how many minutes?', '60');
      if (!minsInput) return;
      const mins = Math.max(1, parseInt(minsInput, 10) || 60);
      try{
        await api.silenceRules(ruleIds, mins);
        window.toast && window.toast.success && window.toast.success('Alerts muted for '+mins+'m.');
        refresh();
      }catch(err){
        window.toast && window.toast.error && window.toast.error('Mute failed: ' + (err.message||err));
      }
    }
  });

  function applyProbe(tr, res){
    /* TOAST: probe result */
    try{
      if(window.toast){
        var name = tr.querySelector('.nm')?.textContent?.trim() || 'Service';
        var lat  = (res && res.latency_ms!=null) ? (' '+res.latency_ms+' ms') : '';
        var code = (res && res.http_code!=null) ? (' • HTTP '+res.http_code) : '';
        var msg  = name+': '+String(res.status||'unknown').toUpperCase()+lat+code;
        if(res.status==='up') toast.success(msg);
        else if(res.status==='warn') toast.warn(msg);
        else toast.error(msg);
      }
    }catch(e){}

    decorateRow(tr, {
      status_meta: {
        status: res.status || 'unknown',
        latency_ms: res.latency_ms ?? null,
        http_code: res.http_code ?? null,
        ts: Math.floor(Date.now()/1000)
      }
    });
  }

  // Toolbar buttons
  $('#btnAddService')?.addEventListener('click', (e)=>{ e.preventDefault(); openModal(false, null); });

  // Import: trigger hidden file input
  $('#btnImport')?.addEventListener('click', (e)=>{ e.preventDefault(); $('#importFile').click(); });
  $('#importFile')?.addEventListener('change', async (e)=>{
    const file = e.target.files[0]; if(!file) return;
    const ext = (file.name.split('.').pop()||'').toLowerCase();
    const text = await file.text();
    let res;
    if (ext === 'csv') res = await api.importCsv(text);
    else {
      let json; try { json = JSON.parse(text); } catch { alert('Invalid JSON'); return; }
      res = await api.importJson(json);
    }
    if(res && !res.error){ refresh(); }
    else alert(res.error || 'Import failed');
    e.target.value = '';
  });

// ---- Global AutoProbe bridging ----
function syncFromGlobal(){
  if (!window.AutoProbe) return;
  const cfg = window.AutoProbe.get();
  const sec = Math.max(5, parseInt(cfg.interval||60,10));
  const on = !!cfg.enabled;
  const cb = $('#autoProbe'); const inp = $('#autoProbeSec');
  if (cb) cb.checked = on; if (inp) inp.value = sec;
}
function writeGlobal(){
  if (!window.AutoProbe) return;
  const cfg = window.AutoProbe.get();
  cfg.enabled = $('#autoProbe')?.checked || false;
  cfg.interval = Math.max(5, parseInt($('#autoProbeSec')?.value||'60',10));
  window.AutoProbe.set(cfg);
}
// listen to background probe results to update the visible table live
window.addEventListener('services:probeUpdate', (ev)=>{
  const res = ev.detail || {};
  (res.results||[]).forEach(r=>{
    const tr = document.querySelector('#svcBody tr[data-id="'+r.id+'"]');
    if (!tr) return;
    const mini = tr.querySelector('.mini-result'); if (!mini) return;
    mini.innerHTML = '';
    const span = document.createElement('span');
    span.className = 'pill ' + (r.status==='up'?'up':(r.status==='warn'?'warn':'down'));
    span.textContent = (r.status||'').toUpperCase();
    mini.appendChild(span);
    if (r.latency_ms!=null){
      const t = document.createElement('span');
      t.className='muted';
      t.textContent = ' ' + r.latency_ms + ' ms' + (r.http_code?(' • HTTP '+r.http_code):'');
      mini.appendChild(t);
    }
  });
});

  // Auto probe
    function setAutoProbe(on){ writeGlobal(); }
  $('#autoProbe')?.addEventListener('change', ()=> writeGlobal());
  $('#autoProbeSec')?.addEventListener('change', ()=> writeGlobal());
  // sync UI with global on load and when config changes
  window.addEventListener('autoprobe:config', syncFromGlobal);
  document.addEventListener('DOMContentLoaded', syncFromGlobal);

  // Modal actions
  $('#modalClose')?.addEventListener('click', (e)=>{ e.preventDefault(); closeModal(); });
  $('#saveService')?.addEventListener('click', async (e)=>{
    e.preventDefault();
    clearErrors();
    const item = {
      id: $('#f_id').value || undefined,
      name: $('#f_name').value.trim(),
      type: $('#f_type').value,
      host: $('#f_host').value.trim(),
      port: parseInt($('#f_port').value,10)||0,
      check: $('#f_check').value,
      timeout_ms: parseInt($('#f_timeout').value,10)||800,
      path: $('#f_path').value.trim() || '/',
      enabled: $('#f_enabled').checked
    };

    let valid = true;
    if (!item.name) { setErr($('#f_name'), 'Name is required.'); valid = false; }
    if (!item.host) { setErr($('#f_host'), 'Host is required.'); valid = false; }
    if (!Number.isFinite(item.port) || !isPort(item.port)) { setErr($('#f_port'), 'Port must be 1–65535.'); valid = false; }
    if (item.check === 'http' && (!item.path || item.path[0] !== '/')) {
      $('#f_path').value = '/' + (item.path||'');
      item.path = $('#f_path').value;
    }
    if (!valid) { showAlert('Please correct the highlighted fields.'); return; }

    const res = await api.upsert(item);
    if(res.error){ showAlert(res.error); return; }
    closeModal();
    await refresh();

    if ($('#f_testOnSave')?.checked) {
      const id = (res.item && res.item.id) ? res.item.id : item.id;
      const tr = id ? $('#svcBody tr[data-id="'+id+'"]') : null;
      const probeRes = await api.probe(res.item || item);
      if (tr) applyProbe(tr, probeRes);
    }
  });

  
  // Exports
  try{
    const makeExportUrl = (fmt)=>{
      try { return new URL('api/services_export.php?format=' + encodeURIComponent(fmt), window.location.href).toString(); }
      catch(e){ return 'api/services_export.php?format=' + encodeURIComponent(fmt); }
    };
    document.getElementById('btnExportJson')?.addEventListener('click', (e)=>{
      e.preventDefault();
      window.location.href = makeExportUrl('json');
    });
    document.getElementById('btnExportCsv')?.addEventListener('click', (e)=>{
      e.preventDefault();
      window.location.href = makeExportUrl('csv');
    });
  }catch(e){ /* no-op */ }

document.addEventListener('DOMContentLoaded', refresh);
})();


  // Toast helpers: hook into common actions if present
  try{
    document.getElementById('saveService')?.addEventListener('click', ()=>{ if(window.toast) toast.success('Service save requested'); });
    document.getElementById('btnAddService')?.addEventListener('click', ()=>{ if(window.toast) toast.info('Add service'); });
  }catch(e){}
