(function(){
  const API_LIST = 'api/services_list.php';
  const API_ALERTS_BULK = 'api/alerts_bulk.php';
  let servicesRoot = null;

  function ynTrue(v){
    if (v === true) return true;
    if (typeof v === 'number') return v > 0;
    if (typeof v === 'string'){
      const s = v.trim().toLowerCase();
      return s === '1' || s === 'true' || s === 'on' || s === 'yes' || s === 'y' || s === 't';
    }
    return false;
  }
  function ynFalse(v){
    if (v === false) return true;
    if (v === 0) return true;
    if (typeof v === 'string'){
      const s = v.trim().toLowerCase();
      return s === '0' || s === 'false' || s === 'off' || s === 'no' || s === 'n' || s === 'f';
    }
    return false;
  }
  function isEnabled(it){
    if (it.disabled != null) return !ynTrue(it.disabled);
    if (it.enabled != null) return ynTrue(it.enabled);
    if (it.active  != null) return ynTrue(it.active);
    return true;
  }

  function relTime(ts){
    if (!ts) return '';
    if (ts > 1e12) ts = Math.floor(ts/1000);
    const diff = Math.max(0, Math.floor(Date.now()/1000) - ts);
    if (diff < 45) return 'just now';
    const m = Math.floor(diff/60); if (m < 60) return m+'m ago';
    const h = Math.floor(m/60); if (h < 24) return h+'h ago';
    const d = Math.floor(h/24); return d+'d ago';
  }

  function cardTemplate(item){
    const id   = item.id || '';
    const name = item.name || '';
    const host = item.host || '';
    const port = item.port != null ? String(item.port) : '';
    const type = item.type || '';
    const path = item.path || '';
    const check = item.check || '';
    const url = (check==='http' && host) ? (host.match(/^https?:/)? host : ('http://' + host + (port?(':'+port):'') + (path||''))) : '';

    const enabledAttr = isEnabled(item) ? '1' : '0';
    const statusMeta = item.status_meta || {};
    const uptimeMeta = item.uptime_meta || {};
    const alertMeta = item.alert_meta || {};
    const pillState = statusMeta.status || 'neutral';
    const latencyText = statusMeta.latency_ms != null ? (statusMeta.latency_ms + ' ms') : '';
    const statusTime = statusMeta.ts ? relTime(statusMeta.ts) : '';
    const uptimeTag = (uptimeMeta && uptimeMeta.uptime_pct != null) ? `<span class="tag">uptime ${uptimeMeta.uptime_pct}%</span>` : '';
    const alertTag = (alertMeta && alertMeta.last_alert) ? `<span class="tag warn">alert ${relTime(alertMeta.last_alert.ts)}</span>` : '';
    const muteTag = (alertMeta && alertMeta.silenced_until) ? `<span class="tag muted">muted ${relTime(alertMeta.silenced_until)}</span>` : '';
    const historyHref = id ? `history.php?service=${encodeURIComponent(id)}` : '';
    const actionChips = [];
    if (historyHref){
      actionChips.push(`<a class="chip small neutral svc-action" href="${historyHref}" target="_blank" rel="noopener">View history</a>`);
    }
    if (alertMeta && Array.isArray(alertMeta.rule_ids) && alertMeta.rule_ids.length){
      actionChips.push(`<a class="chip small warn svc-action" href="#" data-action="mute" data-rules="${alertMeta.rule_ids.join(',')}">Silence alerts</a>`);
    }
    const actionsHtml = actionChips.length ? `<div class="svc-actions">${actionChips.join('')}</div>` : '';

    return `
      <div class="card service-card" data-role="svc-card" data-svc-id="${id}" data-svc-name="${name}" data-svc-host="${host}" data-svc-port="${port}" data-svc-enabled="${enabledAttr}">
        <div class="row between">
          <div class="svc-name">${name}</div>
          <div class="row" style="gap:.5rem;align-items:center">
            <span class="pill ${pillState}">${pillState==='neutral'?'--':pillState.toUpperCase()}</span>
            <span class="svc-latency muted">${latencyText}${statusTime ? ' · '+statusTime : ''}</span>
          </div>
        </div>
        <div class="muted small" style="margin-top:.25rem">
          ${type ? `<span class="tag">${type}</span>` : ''}
          ${check ? `<span class="tag">${check}</span>` : ''}
          ${host ? `<span class="tag">${host}${port?(':'+port):''}${path?(''+path):''}</span>` : ''}
          ${url ? `<a class="tag" href="${url}" target="_blank" rel="noopener">open</a>` : ''}
          ${uptimeTag}
          ${alertTag}
          ${muteTag}
        </div>
        ${actionsHtml}
      </div>`;
  }

  async function fetchList(){
    try{
      const r = await fetch(API_LIST, {cache:'no-store'});
      if (!r.ok) throw 0;
      return await r.json();
    }catch(e){ return null; }
  }

  async function build(){
    if (!servicesRoot) servicesRoot = document.getElementById('services');
    const root = servicesRoot;
    if (!root) return;
    const data = await fetchList();
    const items = (
      (data && Array.isArray(data.items)) ? data.items :
      (data && Array.isArray(data.services)) ? data.services :
      []
    );
    const enabled = items.filter(isEnabled);
    root.innerHTML = enabled.map(cardTemplate).join('');
    window.dispatchEvent(new CustomEvent('services:rendered', {detail:{count: enabled.length}}));
  }

  async function silenceRules(ids, minutes){
    const res = await fetch(API_ALERTS_BULK, {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({action:'silence', ids: ids, silence_minutes: minutes})
    });
    if (!res.ok) throw new Error('HTTP '+res.status);
    return res.json();
  }

  function handleCardActions(ev){
    const btn = ev.target.closest('[data-action]');
    if (!btn || btn.dataset.action !== 'mute') return;
    ev.preventDefault();
    const rules = (btn.dataset.rules||'').split(',').map(s=>s.trim()).filter(Boolean);
    if (!rules.length){
      window.toast && window.toast.warn && window.toast.warn('No alert rules found for this service.');
      return;
    }
    const card = btn.closest('[data-role="svc-card"]');
    const svcName = card?.dataset?.svcName || card?.dataset?.svcId || 'service';
    const minsInput = prompt(`Mute alerts for ${svcName} (minutes):`, '60');
    if (!minsInput) return;
    const mins = Math.max(1, parseInt(minsInput, 10) || 60);
    const prev = btn.textContent;
    btn.textContent = 'Muting…';
    btn.classList.add('is-busy');
    silenceRules(rules, mins).then(()=>{
      window.toast && window.toast.success && window.toast.success(`Alerts muted for ${mins}m.`);
      build();
    }).catch(err=>{
      window.toast && window.toast.error && window.toast.error('Mute failed: ' + (err && err.message ? err.message : err));
    }).finally(()=>{
      btn.textContent = prev;
      btn.classList.remove('is-busy');
    });
  }

  document.addEventListener('DOMContentLoaded', ()=>{
    servicesRoot = document.getElementById('services');
    if (servicesRoot) servicesRoot.addEventListener('click', handleCardActions);
    build();
  });
  window.addEventListener('dashboard:refresh', build);
})();
