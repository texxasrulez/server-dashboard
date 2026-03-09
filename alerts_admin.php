<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/auth.php';
require_admin();

require_once __DIR__ . '/lib/Config.php';
\App\Config::init(__DIR__);
$alertsUi = [
  'mute_presets' => (string) \App\Config::get('alerts.mute_presets', ''),
  'service_defaults' => [
    'latency_warn_ms' => (int) \App\Config::get('alerts.service_defaults.latency_warn_ms', 0),
    'latency_fail_ms' => (int) \App\Config::get('alerts.service_defaults.latency_fail_ms', 0),
  ],
];

$PAGE_TITLE = 'Alerts';
$PAGE_CSS   = 'assets/css/pages/alerts_admin.css';
$REQUIRE_ADMIN = true;
include __DIR__.'/includes/head.php'; ?>

<div class="card">
 <div class="card">
  <div class="row between">
    <div class="section-title">Alerts</div>
    <div class="muted">Define alert rules per service.</div>
  </div>

  <form id="alertForm" class="grid" style="grid-template-columns: repeat(auto-fit,minmax(240px,1fr)); gap: 1rem; margin-top: .75rem;">
    <label>Rule name
      <input required type="text" id="a_name" name="name" maxlength="80" placeholder="e.g., API latency too high">
    </label>

    <label>Service
      <select required id="a_service_id" name="service_id">
        <option value="">Loading services…</option>
      </select>
    </label>

    <label>Metric
      <select required id="a_metric" name="metric">
        <option value="status">status (up/down)</option>
        <option value="latency_ms">latency_ms</option>
        <option value="http_code">http_code</option>
        <option value="packet_loss_pct">packet_loss_pct</option>
      </select>
    </label>

    <label>Operator
      <select required id="a_op" name="op">
        <option value=">">&gt;</option>
        <option value=">=">&ge;</option>
        <option value="==">==</option>
        <option value="<=">&le;</option>
        <option value="<">&lt;</option>
        <option value="!=">!=</option>
      </select>
    </label>

    <label>Threshold
      <input required type="number" id="a_threshold" name="threshold" step="1" inputmode="numeric" placeholder="e.g., 500">
      <div id="thresholdHelper" class="muted small" style="margin-top:4px" hidden></div>
    </label>

    <label>For consecutive failures
      <input required type="number" id="a_consecutive" name="consecutive" min="1" max="1000" value="3">
    </label>

    <label>Cooldown (minutes)
      <input required type="number" id="a_cooldown_min" name="cooldown_min" min="1" max="1440" value="30">
    </label>

    <label>Severity
      <select id="a_severity" name="severity">
        <option value="info">info</option>
        <option value="warn" selected>warn</option>
        <option value="crit">crit</option>
      </select>
    </label>

    <label>Email recipients (comma‑sep)
      <input type="email" id="a_email" name="email" multiple placeholder="ops@example.com, you@domain.tld" pattern="^[^,]+(@[^,]+)(\s*,\s*[^,]+@[^,]+)*$">
    </label>

    <label>Webhook URL
      <input type="url" id="a_webhook_url" name="webhook_url" placeholder="https://hooks.example.com/…">
    </label>

    <label class="row" style="align-items:center; gap:.5rem">Enabled
      <input type="checkbox" id="a_enabled" name="enabled" checked>
    </label>
  </form>

  <div class="card-actions" style="margin-top:.75rem">
    <div class="row gap wrap">
      <button id="btnPreview" class="btn" type="button">Preview JSON</button>
      <button id="btnSave" class="btn" type="button" disabled title="API wiring pending"><span data-i18n="common.save">Save</span></button>
    </div>
    <div class="row gap-s wrap bulk-actions" style="margin-top:.5rem">
      <span class="chip-label">Bulk actions:</span>
      <button class="btn" data-act="bulk-enable" type="button">Enable</button>
      <button class="btn" data-act="bulk-disable" type="button">Disable</button>
      <button class="btn" data-act="bulk-unsilence" type="button">Unmute</button>
      <button class="btn danger" data-act="bulk-delete" type="button"><span data-i18n="common.delete">Delete</span></button>
      <button class="btn" data-act="bulk-silence" type="button" title="Silence selected for N minutes">Silence…</button>
    </div>
    <div class="row gap-xs wrap quick-mute" id="bulkMuteRow" hidden style="margin-top:.4rem"></div>
  </div>

  <details id="previewBox" class="muted" style="margin-top: .75rem;" hidden>
    <summary>Payload preview</summary>
    <pre id="payloadPreview" class="code card" style="white-space: pre-wrap; word-break: break-word;"></pre>
  </details>

  </div>
  <div>
  <div class="card">
    <div class="section-subtitle">Existing rules</div>
    <div id="rulesEmpty" class="muted">No rules yet.</div>
    <table id="rulesTable" class="table" style="width:100%; display:none">
      
      <thead>
        <tr>
          <th>Status</th>
          <th>Name</th>
          <th>Service</th>
          <th>Metric</th>
          <th>Condition</th>
          <th>Notify</th>
          <th>Severity</th>
          <th>Cooldown</th>
          <th>Last triggered</th>
          <th>Times</th>
          <th>Actions</th>
          <th>Select</th>
        </tr>
      </thead>
      <tbody id="rulesTbody"></tbody>
    </table>
  </div>
</div>
</div>

<script>
(function(){
  "use strict";
  const URLS = {
    services: '<?= h(project_url('/api/services_list.php')) ?>',
    list: '<?= h(project_url('/api/alerts_list.php')) ?>',
    upsert: '<?= h(project_url('/api/alerts_upsert.php')) ?>',
    bulk: '<?= h(project_url('/api/alerts_bulk.php')) ?>'
  };
  const svcSel = document.getElementById('a_service_id');
  const metricSel = document.getElementById('a_metric');
  const threshold = document.getElementById('a_threshold');
  const email = document.getElementById('a_email');
  const webhook = document.getElementById('a_webhook_url');
  const btnPreview = document.getElementById('btnPreview');
  const previewBox = document.getElementById('previewBox');
  const preview = document.getElementById('payloadPreview');
  const btnSave = document.getElementById('btnSave');
  const alertsConfig = <?= json_encode($alertsUi, JSON_UNESCAPED_SLASHES) ?>;
  const serviceDefaults = (alertsConfig && alertsConfig.service_defaults) || {};
  const mutePresets = parseMutePresets((alertsConfig && alertsConfig.mute_presets) || '');
  const bulkMuteRow = document.getElementById('bulkMuteRow');
  const thresholdHelper = document.getElementById('thresholdHelper');
  const tbody = document.getElementById('rulesTbody');
  const empty = document.getElementById('rulesEmpty');
  const table = document.getElementById('rulesTable');
  let rulesCache = [];
  function csrfToken(){
    const m = document.querySelector('meta[name="csrf-token"]');
    return (m && m.content) ? String(m.content) : '';
  }
  function apiFetch(url, init){
    const opts = init ? Object.assign({}, init) : {};
    if (!opts.credentials) opts.credentials = 'same-origin';
    const method = String(opts.method || 'GET').toUpperCase();
    if (method !== 'GET' && method !== 'HEAD' && method !== 'OPTIONS'){
      const token = csrfToken();
      if (token){
        const headers = new Headers(opts.headers || {});
        if (!headers.has('X-CSRF-Token')) headers.set('X-CSRF-Token', token);
        opts.headers = headers;
        if (opts.body instanceof URLSearchParams && !opts.body.has('_csrf')){
          opts.body.set('_csrf', token);
        } else if ((headers.get('Content-Type') || '').indexOf('application/json') >= 0 && typeof opts.body === 'string'){
          try {
            const parsed = JSON.parse(opts.body);
            if (parsed && typeof parsed === 'object' && !parsed._csrf && !parsed.csrf){
              parsed._csrf = token;
              opts.body = JSON.stringify(parsed);
            }
          } catch (_e) {}
        }
      }
    }
    return fetch(url, opts);
  }

  // Populate services select
  apiFetch(URLS.services, {credentials:'same-origin'})
    .then(r => r.json()).then(data => {
      const items = (data && data.items) ? data.items : [];
      svcSel.innerHTML = '<option value="">Select service…</option>'
        + items.map(s => '<option value="'+encodeURIComponent(s.id)+'" data-name="'+escapeHtml(s.name || s.host)+'">'+escapeHtml(s.name || s.host)+'</option>').join('');
    }).catch(() => {
      svcSel.innerHTML = '<option value="">(failed to load)</option>';
    });

  function updateThresholdStep(){
    const m = metricSel.value;
    threshold.step = (m === 'latency_ms' || m === 'packet_loss_pct') ? '0.1' : '1';
    threshold.placeholder = m === 'http_code' ? 'e.g., 500' : (m === 'status' ? '1=up, 0=down' : 'e.g., 600');
    applyLatencyDefaults();
  }
  function applyLatencyDefaults(){
    if (!thresholdHelper) return;
    const metric = metricSel.value;
    const warn = Number(serviceDefaults.latency_warn_ms || 0);
    const fail = Number(serviceDefaults.latency_fail_ms || 0);
    if (metric !== 'latency_ms' || (!warn && !fail)){
      thresholdHelper.hidden = true;
      thresholdHelper.textContent = '';
      return;
    }
    const bits = [];
    if (warn) bits.push('warn '+warn+'ms');
    if (fail) bits.push('fail '+fail+'ms');
    thresholdHelper.hidden = false;
    thresholdHelper.textContent = 'Defaults from Config → Alerts: ' + bits.join(' · ');
    if (!threshold.value && warn){
      threshold.value = warn;
    }
  }
  metricSel.addEventListener('change', updateThresholdStep);
  updateThresholdStep();

  function byId(id){ return document.getElementById(id); }

  function getPayload(){
    const svcOpt = svcSel.options[svcSel.selectedIndex] || {};
    const svcName = svcOpt.getAttribute('data-name') || '';
    return {
      id: 'alert_' + Math.random().toString(36).slice(2, 10),
      name: byId('a_name').value.trim(),
      service_id: decodeURIComponent(svcSel.value || ''),
      service_name: svcName,
      metric: metricSel.value,
      op: byId('a_op').value,
      threshold: parseFloat(byId('a_threshold').value),
      consecutive: parseInt(byId('a_consecutive').value || '1', 10),
      cooldown_min: parseInt(byId('a_cooldown_min').value || '30', 10),
      severity: byId('a_severity').value,
      notify: {
        email: (email.value || '').trim(),
        webhook_url: (webhook.value || '').trim()
      },
      enabled: byId('a_enabled').checked
    };
  }

  btnPreview.addEventListener('click', function(e){
    e.preventDefault();
    const p = getPayload();
    if (!p.name || !p.service_id){ return toastWarn('Name and Service are required.'); }
    preview.textContent = JSON.stringify(p, null, 2);
    previewBox.hidden = false;
    previewBox.open = true;
  });

  function loadRules(){
    apiFetch(URLS.list, {credentials:'same-origin'})
      .then(r=>r.json()).then(data=>{
        const items = (data && data.items) ? data.items : [];
        rulesCache = items;
        if (!items.length){
          empty.style.display='block';
          empty.textContent='No rules yet.';
          table.style.display='none';
          tbody.innerHTML='';
        } else {
          empty.style.display='none';
          table.style.display='table';
          tbody.innerHTML = items.map(renderRow).join('');
        }
        updateBulkMuteRow();
      }).catch(()=>{
        rulesCache = [];
        tbody.innerHTML='';
        empty.textContent='Failed to load';
        empty.style.display='block';
        table.style.display='none';
        updateBulkMuteRow();
      });
  }

  function renderRow(a){
    const notify = [];
    if (a.notify && a.notify.email) notify.push('email');
    if (a.notify && a.notify.webhook_url) notify.push('webhook');
    const cond = `${a.metric || ''} ${a.op || ''} ${a.threshold ?? ''}`.trim();
    const silencedMs = coerceTs(a.silenced_until);
    return `<tr data-id="${escapeHtml(a.id)}">
      <td>${renderStatusPill(a, silencedMs)}</td>
      <td>${escapeHtml(a.name||'')}</td>
      <td>${escapeHtml(a.service_name||a.service_id||'')}</td>
      <td>${escapeHtml(a.metric||'')}</td>
      <td>${escapeHtml(cond)}</td>
      <td>${notify.length ? escapeHtml(notify.join(', ')) : '-'}</td>
      <td>${escapeHtml(a.severity||'')}</td>
      <td>${escapeHtml(String(a.cooldown_min||0))} min</td>
      <td>${renderLastCell(a.last_triggered)}</td>
      <td>${escapeHtml(String(a.times_triggered||0))}</td>
      <td>${renderActionsCell(silencedMs)}</td>
      <td><input type="checkbox" class="row-select" aria-label="select"></td>
    </tr>`;
  }

  tbody?.addEventListener('click', function(e){
    const btn = e.target.closest('button');
    if (!btn) return;
    const tr = e.target.closest('tr');
    const id = tr?.getAttribute('data-id');
    const act = btn.dataset.act;
    if (!id) return;
    if (act === 'delete'){
      if (!confirm('Delete this alert?')) return;
      performBulk('delete', {ids:[id]}).then(loadRules).catch(err=>toastError(err.message||'Delete failed'));
      return;
    }
    if (act === 'edit'){
      const found = rulesCache.find(x => x.id === id);
      if (!found) return;
      byId('a_name').value=found.name||'';
      const opt=[...svcSel.options].find(o=>decodeURIComponent(o.value)===found.service_id);
      if(opt){ svcSel.value=encodeURIComponent(found.service_id); }
      metricSel.value=found.metric||'status';
      updateThresholdStep();
      byId('a_op').value=found.op||'>';
      byId('a_threshold').value=found.threshold||'';
      byId('a_consecutive').value=found.consecutive||3;
      byId('a_cooldown_min').value=found.cooldown_min||30;
      byId('a_severity').value=found.severity||'warn';
      email.value=(found.notify&&found.notify.email)||'';
      webhook.value=(found.notify&&found.notify.webhook_url)||'';
      byId('a_enabled').checked=!!found.enabled;
      btnSave.disabled=false; btnSave.dataset.editId=id;
      window.scrollTo({top:0, behavior:'smooth'});
      return;
    }
    if (act === 'row-silence'){
      const mins = parseInt(btn.dataset.minutes,10) || 60;
      performBulk('silence',{ids:[id], minutes:mins}).then(loadRules).catch(err=>toastError(err.message||'Mute failed'));
      return;
    }
    if (act === 'row-unsilence'){
      performBulk('unsilence',{ids:[id]}).then(loadRules).catch(err=>toastError(err.message||'Unmute failed'));
    }
  });

  btnSave.disabled=false;
  btnSave.addEventListener('click', function(e){
    e.preventDefault();
    const p=getPayload();
    if (!p.name || !p.service_id){ return toastWarn('Name and Service are required.'); }
    if (btnSave.dataset.editId){ p.id = btnSave.dataset.editId; }
    apiFetch(URLS.upsert, {
      method:'POST',
      credentials:'same-origin',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify(p)
    }).then(r=>r.json()).then(resp=>{
      if (resp && resp.error){ throw new Error(resp.error); }
      btnSave.removeAttribute('data-edit-id');
      document.getElementById('alertForm').reset();
      updateThresholdStep();
      loadRules();
      toastSuccess('Alert saved.');
    }).catch((e)=>{
      toastError('Save failed: ' + (e?.message||'unknown'));
      if (window.clientLog && typeof window.clientLog.error === 'function'){
        window.clientLog.error('alerts_save_failed',{err:String(e)});
      }
    });
  });

  document.addEventListener('click', function(e){
    const b=e.target.closest('button');
    if(!b) return;
    const act=b.dataset.act;
    if(!act || act.indexOf('bulk')!==0) return;
    e.preventDefault();
    const ids=selectedIds();
    if(!ids.length){ return toastWarn('Select at least one alert.'); }
    const actionMap = {
      'bulk-enable':'enable',
      'bulk-disable':'disable',
      'bulk-delete':'delete',
      'bulk-silence':'silence',
      'bulk-unsilence':'unsilence'
    };
    const action = actionMap[act];
    if(!action) return;
    let minutes = null;
    if (action === 'silence'){
      minutes = parseInt(b.dataset.minutes,10) || null;
      if (!minutes){
        const mins=prompt('Silence for how many minutes?', '60');
        if(!mins) return;
        minutes = parseInt(mins,10) || 0;
      }
      if(!minutes){ return toastWarn('Enter a valid number of minutes.'); }
    }
    performBulk(action,{ids, minutes})
      .then(()=>{ loadRules(); toastSuccess('Bulk action applied.'); })
      .catch(err=>toastError(err.message||'Bulk action failed'));
  });

  document.addEventListener('change', function(e){
    if (e.target.classList.contains('row-select')){
      updateBulkMuteRow();
    }
  });

  function performBulk(action, opts){
    const ids = Array.isArray(opts.ids) ? opts.ids.filter(Boolean) : [];
    if (!ids.length) return Promise.reject(new Error('No ids selected'));
    const payload = { ids: ids, action: action };
    if (action === 'silence'){
      payload.silence_minutes = Math.max(1, parseInt(opts.minutes,10) || 60);
    }
    return apiFetch(URLS.bulk, {
      method:'POST',
      credentials:'same-origin',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify(payload)
    }).then(r=>r.json()).then(resp=>{
      if (!resp || resp.error){ throw new Error(resp?.error || 'Bulk action failed'); }
      updateBulkMuteRow();
      return resp;
    });
  }

  function renderStatusPill(a, silencedMs){
    if (!a.enabled){
      return '<span class="status-pill muted">Disabled</span>';
    }
    if (silencedMs && silencedMs > Date.now()){
      return '<span class="status-pill quiet">Muted <span class="status-sub">'+escapeHtml(relativeTime(silencedMs))+'</span></span>';
    }
    return '<span class="status-pill ok">Active</span>';
  }

  function renderLastCell(ts){
    const ms = coerceTs(ts);
    if (!ms) return '-';
    return '<div class="last-meta"><span class="last-rel">'+escapeHtml(relativeTime(ms))+'</span><span class="last-abs">'+escapeHtml(formatAbs(ms))+'</span></div>';
  }

  function renderActionsCell(silencedMs){
    const quick = mutePresets.length ? '<div class="row gap-xs wrap mute-quick"><span class="chip-label">Mute for:</span>'
      + mutePresets.map(min => '<button type="button" class="btn-chip" data-act="row-silence" data-minutes="'+min+'" title="Mute for '+min+' minutes">'+min+'m</button>').join('')
      + '<button type="button" class="btn-chip ghost" data-act="row-unsilence" '+(silencedMs && silencedMs>Date.now()?'':'disabled')+'>Unmute</button></div>' : '';
    return '<div class="actions-cell">'
      + '<div class="row gap-xs wrap">'
      +   '<button class="btn" type="button" data-act="edit"><span data-i18n="common.edit">Edit</span></button>'
      +   '<button class="btn danger" type="button" data-act="delete"><span data-i18n="common.delete">Delete</span></button>'
      + '</div>'
      + quick
      + '</div>';
  }

  function selectedIds(){
    return Array.prototype.slice.call(document.querySelectorAll('#rulesTbody .row-select:checked'))
      .map(cb => cb.closest('tr')?.getAttribute('data-id'))
      .filter(Boolean);
  }

  function updateBulkMuteRow(){
    if (!bulkMuteRow) return;
    if (!mutePresets.length){
      bulkMuteRow.hidden = true;
      bulkMuteRow.innerHTML = '';
      return;
    }
    const ids = selectedIds();
    if (!ids.length){
      bulkMuteRow.hidden = true;
      bulkMuteRow.innerHTML = '';
      return;
    }
    bulkMuteRow.hidden = false;
    bulkMuteRow.innerHTML = '<span class="chip-label">Quick mute:</span>'
      + mutePresets.map(min => '<button type="button" class="btn-chip" data-act="bulk-silence" data-minutes="'+min+'">'+min+'m</button>').join('');
  }

  function parseMutePresets(str){
    return String(str||'').split(/[\s,]+/).map(function(n){ return parseInt(n,10)||0; }).filter(function(n){ return n>0; }).slice(0,5);
  }

  function coerceTs(ts){
    if (ts == null) return null;
    if (typeof ts === 'number'){
      return ts < 1e12 ? ts * 1000 : ts;
    }
    const n = parseInt(ts, 10);
    if (!isNaN(n)) return n < 1e12 ? n * 1000 : n;
    const parsed = Date.parse(ts);
    return isFinite(parsed) ? parsed : null;
  }

  function relativeTime(ms){
    const now = Date.now();
    const diff = ms - now;
    const abs = Math.abs(diff);
    const units = [
      {limit:60000, name:'s', div:1000},
      {limit:3600000, name:'m', div:60000},
      {limit:86400000, name:'h', div:3600000},
      {limit:604800000, name:'d', div:86400000},
      {limit:31536000000, name:'w', div:604800000}
    ];
    for (let i=0;i<units.length;i++){
      if (abs < units[i].limit){
        const val = Math.max(1, Math.round(abs / units[i].div));
        return diff >= 0 ? ('in '+val+units[i].name) : (val+units[i].name+' ago');
      }
    }
    return diff >= 0 ? 'in >1y' : '>1y ago';
  }

  function formatAbs(ms){
    try {
      return new Date(ms).toLocaleString();
    } catch(e){
      return '';
    }
  }

  function toastWarn(msg){
    try { window.toast && window.toast.warn ? window.toast.warn(msg) : alert(msg); } catch(_){}
  }
  function toastError(msg){
    try { window.toast && window.toast.error ? window.toast.error(msg) : alert(msg); } catch(_){}
  }
  function toastSuccess(msg){
    try { window.toast && window.toast.success && window.toast.success(msg); } catch(_){}
  }

  function escapeHtml(str){
    return String(str || '').replace(/[&<>"']/g, function(s){
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[s];
    });
  }

  // initial load
  loadRules();
})();
</script>

<?php include __DIR__.'/includes/foot.php'; ?>
