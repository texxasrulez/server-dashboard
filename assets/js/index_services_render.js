(function(){
  const API_LIST = 'api/services_list.php';

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

    return `
      <div class="card service-card" data-role="svc-card" data-svc-id="${id}" data-svc-name="${name}" data-svc-host="${host}" data-svc-port="${port}" data-svc-enabled="${enabledAttr}">
        <div class="row between">
          <div class="svc-name">${name}</div>
          <div class="row" style="gap:.5rem;align-items:center">
            <span class="pill neutral">--</span>
            <span class="svc-latency muted"></span>
          </div>
        </div>
        <div class="muted small" style="margin-top:.25rem">
          ${type ? `<span class="tag">${type}</span>` : ''}
          ${check ? `<span class="tag">${check}</span>` : ''}
          ${host ? `<span class="tag">${host}${port?(':'+port):''}${path?(''+path):''}</span>` : ''}
          ${url ? `<a class="tag" href="${url}" target="_blank" rel="noopener">open</a>` : ''}
        </div>
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
    const root = document.getElementById('services');
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

  document.addEventListener('DOMContentLoaded', build);
  window.addEventListener('dashboard:refresh', build);
})();