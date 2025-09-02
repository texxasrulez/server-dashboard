<?php
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/auth.php';
require_admin();

$PAGE_TITLE = 'Alerts';
$PAGE_CSS   = 'assets/css/pages/alerts_admin.css';
$REQUIRE_ADMIN = true; include __DIR__.'/includes/head.php'; ?>

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

  <div class=\"row gap\" style=\"margin-top:.5rem\">
    <button id="btnPreview" class="btn">Preview JSON</button>
    <button id="btnSave" class="btn" disabled title="API wiring pending">Save</button>
  
  <div class="row gap" style="margin-left:auto">
    <button class="btn" data-act="bulk-enable">Enable</button>
    <button class="btn danger" data-act="bulk-disable">Disable</button>
    <button class="btn danger" class="btn danger">Delete</button>
    <button class="btn" data-act="bulk-silence" title="Silence selected for N minutes">Silence…</button>
  </div>
</div>

  <details id="previewBox" class="muted" style="margin-top: .75rem;" hidden>
    <summary>Payload preview</summary>
    <pre id="payloadPreview" class="code" style="white-space: pre-wrap; word-break: break-word;"></pre>
  </details>

  <hr style="margin:1rem 0"/>

  <div>
    <div class="section-subtitle">Existing rules</div>
    <div id="rulesEmpty" class="muted">No rules yet.</div>
    <table id="rulesTable" class="table" style="width:100%; display:none">
      
      <thead>
        <tr>
          <th>Enabled</th>
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
        </tr>
      </thead>
      <tbody id="rulesTbody"></tbody>
    </table>
  </div>
</div>

<script>
(function(){
  const svcSel = document.getElementById('a_service_id');
  const metricSel = document.getElementById('a_metric');
  const threshold = document.getElementById('a_threshold');
  const email = document.getElementById('a_email');
  const webhook = document.getElementById('a_webhook_url');
  const btnPreview = document.getElementById('btnPreview');
  const previewBox = document.getElementById('previewBox');
  const preview = document.getElementById('payloadPreview');
  const btnSave = document.getElementById('btnSave');

  // Populate services
  fetch('<?= h(project_url('/api/services_list.php')) ?>', {credentials:'same-origin'})
    .then(r => r.json()).then(data => {
      const items = (data && data.items) ? data.items : [];
      svcSel.innerHTML = '<option value="">Select service…</option>'
        + items.map(s => '<option value="'+encodeURIComponent(s.id)+'" data-name="'+escapeHtml(s.name || s.host)+'">'+escapeHtml(s.name || s.host)+'</option>').join('');
    }).catch(() => {
      svcSel.innerHTML = '<option value="">(failed to load)</option>';
    });

  // Metric tweaks
  const metricUnits = {
    status: '',
    latency_ms: 'ms',
    http_code: '',
    packet_loss_pct: '%'
  };
  function updateThresholdStep(){
    const m = metricSel.value;
    threshold.step = (m === 'latency_ms' || m === 'packet_loss_pct') ? '0.1' : '1';
    threshold.placeholder = m === 'http_code' ? 'e.g., 500' : (m === 'status' ? '1=up, 0=down' : 'e.g., 600');
  }
  metricSel.addEventListener('change', updateThresholdStep);
  updateThresholdStep();

  // Build preview payload
  function getPayload(){
    const svcOpt = svcSel.options[svcSel.selectedIndex] || {};
    const svcName = svcOpt.getAttribute('data-name') || '';
    const payload = {
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
    return payload;
  }

  btnPreview.addEventListener('click', function(e){
    e.preventDefault();
    const p = getPayload();
    if (!p.name || !p.service_id) { try{ toast && toast.warn('Name and Service are required.'); }catch(e){} return; }
    preview.textContent = JSON.stringify(p, null, 2);
    previewBox.hidden = false;
    previewBox.open = true;
  });

  // util
  function byId(id){ return document.getElementById(id); }

  const tbody = document.getElementById('rulesTbody');
  const empty = document.getElementById('rulesEmpty');
  const table = document.getElementById('rulesTable');

  function loadRules(){
    fetch('<?= h(project_url('/api/alerts_list.php')) ?>', {credentials:'same-origin'})
      .then(r=>r.json()).then(data=>{
        const items = (data && data.items) ? data.items : [];
        if (!items.length){ empty.style.display='block'; table.style.display='none'; return; }
        empty.style.display='none'; table.style.display='table';
        tbody.innerHTML = items.map(renderRow).join('');
      }).catch(()=>{
        tbody.innerHTML=''; empty.textContent='Failed to load'; empty.style.display='block'; table.style.display='none';
      });
  }

  
  function renderRow(a){
    const notify = [];
    if (a.notify && a.notify.email) notify.push('email');
    if (a.notify && a.notify.webhook_url) notify.push('webhook');
    const cond = `${a.metric} ${a.op} ${a.threshold}`;
    return `<tr data-id="${escapeHtml(a.id)}">
      <td><input type="checkbox" ${a.enabled?'checked':''} data-field="enabled" aria-label="enabled" disabled></td>
      <td>${escapeHtml(a.name||'')}</td>
      <td>${escapeHtml(a.service_name||a.service_id||'')}</td>
      <td>${escapeHtml(a.metric||'')}</td>
      <td>${escapeHtml(cond)}</td>
      <td>${notify.join(', ')||'-'}</td>
      <td>${escapeHtml(a.severity||'')}</td>
      <td>${(a.cooldown_min||0)} min</td>
      <td>${formatLast(a.last_triggered)}</td>
      <td>${a.times_triggered||0}</td>
      <td>
        <button class="btn" data-act="edit">Edit</button>
        <button class="btn danger">Delete</button>
      </td>
    <td><input type="checkbox" class="row-select" aria-label="select"></td>
    </tr>`;
  }


  tbody?.addEventListener('click', function(e){
    const btn = e.target.closest('button'); if (!btn) return;
    const tr = e.target.closest('tr'); const id = tr?.getAttribute('data-id');
    if (btn.dataset.act==='delete' && id){
      if (!confirm('Delete this alert?')) return;
      fetch('<?= h(project_url('/api/alerts_delete.php')) ?>?id='+encodeURIComponent(id), {method:'POST', credentials:'same-origin'})
        .then(r=>r.json()).then(()=>loadRules());
    }
    if (btn.dataset.act==='edit' && id){
      // Load row into form for quick edit
      fetch('<?= h(project_url('/api/alerts_list.php')) ?>', {credentials:'same-origin'})
        .then(r=>r.json()).then(d=>{
          const a=(d.items||[]).find(x=>x.id===id); if(!a) return;
          byId('a_name').value=a.name||'';
          const opt=[...svcSel.options].find(o=>decodeURIComponent(o.value)===a.service_id); if(opt){ svcSel.value=encodeURIComponent(a.service_id); }
          metricSel.value=a.metric||'status'; updateThresholdStep();
          byId('a_op').value=a.op||'>';
          byId('a_threshold').value=a.threshold||'';
          byId('a_consecutive').value=a.consecutive||3;
          byId('a_cooldown_min').value=a.cooldown_min||30;
          byId('a_severity').value=a.severity||'warn';
          email.value=(a.notify&&a.notify.email)||'';
          webhook.value=(a.notify&&a.notify.webhook_url)||'';
          byId('a_enabled').checked=!!a.enabled;
          btnSave.disabled=false; btnSave.dataset.editId=id;
          window.scrollTo({top:0, behavior:'smooth'});
        });
    }
  });

  // Enable Save now that APIs exist
  btnSave.disabled=false;
  btnSave.addEventListener('click', function(e){
    e.preventDefault();
    const p=getPayload();
    if (!p.name || !p.service_id){ try{ toast && toast.warn('Name and Service are required.'); }catch(e){} return; }
    if (btnSave.dataset.editId){ p.id = btnSave.dataset.editId; }
    fetch('<?= h(project_url('/api/alerts_upsert.php')) ?>', {
      method:'POST', credentials:'same-origin',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify(p)
    }).then(r=>r.json()).then(resp=>{
      btnSave.removeAttribute('data-edit-id');
      document.getElementById('alertForm').reset();
      updateThresholdStep();
      loadRules();
    }).catch((e)=>{ try{ toast && toast.error('Save failed'); }catch(_){ } clientLog && clientLog.error('alerts_save_failed',{err:String(e)}); });
  });

  document.addEventListener('click', function(e){
  const b=e.target.closest('button'); if(!b) return;
  const act=b.dataset.act; if(!act||!act.startsWith('bulk')) return;
  e.preventDefault();
  const ids=[...document.querySelectorAll('#rulesTbody .row-select:checked')]
    .map(cb=>cb.closest('tr')?.getAttribute('data-id')).filter(Boolean);
  if(!ids.length){ alert('Select at least one row.'); return; }
  const payload={ ids, action: act==='bulk-enable'?'enable': act==='bulk-disable'?'disable': act==='bulk-delete'?'delete':'silence' };
  if (payload.action==='silence'){
     const mins=prompt('Silence for how many minutes?', '60');
     if(!mins) return; payload.silence_minutes=parseInt(mins,10)||60;
  }
  fetch('<?= h(project_url('/api/alerts_bulk.php')) ?>', {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload)})
   .then(r=>r.json()).then(()=>loadRules());
});

// initial load
  loadRules();

  function formatLast(ts){
    if (!ts) return '-';
    try{
      const d = new Date(ts);
      if (isNaN(d.getTime())) return String(ts);
      return d.toLocaleString();
    }catch(e){ return String(ts); }
  }

  function escapeHtml(str){
    return String(str || '').replace(/[&<>"']/g, s => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[s]));
  }

  function formatLast(ts){
    if (!ts) return '-';
    try {
      const d = new Date(typeof ts === 'number' ? ts*1000 : ts);
      if (!isFinite(d.getTime())) return '-';
      return d.toLocaleString();
    } catch(e){ return '-'; }
  }
})();
</script>

<?php include __DIR__.'/includes/foot.php'; ?>
