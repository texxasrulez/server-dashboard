/* assets/js/pages/bookmarks.js */
(function(){
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once:true}); else init();

  let editId = null;
  var API_BASE = null;
  let CATS = [];
  let BOOKS = [];

  function $(s, root){ return (root||document).querySelector(s); }
  function esc(s){ return String(s||'').replace(/[&<>"']/g, function(c){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[c]); }); }
  function tOK(m){ try{ toast && toast.success && toast.success(m);}catch(e){} }
  function tWarn(m){ try{ toast && toast.warn && toast.warn(m);}catch(e){} }
  function tErr(m, sticky){ try{ toast && toast.error && toast.error(m,{sticky:!!sticky}); }catch(e){} }
  function logI(ev, data){ try{ clientLog && clientLog.info && clientLog.info(ev, data||{});}catch(e){} }
  function logE(ev, data){ try{ clientLog && clientLog.error && clientLog.error(ev, data||{});}catch(e){} }

  function wireCategoryForm(opts){
    var form = $(opts.formSel);
    if (!form) return;
    var nameEl = $(opts.nameSel);
    var idEl = opts.idSel ? $(opts.idSel) : null;
    var cancelBtn = $(opts.cancelSel);
    form.addEventListener('submit', function(ev){
      ev.preventDefault();
      var id = idEl && idEl.value || '';
      var name = (nameEl && nameEl.value || '').trim();
      if (!name){ tWarn('Category name required'); nameEl && nameEl.focus(); return; }
      var url = apiUrl('bm_categories_upsert.php');
      var body = new URLSearchParams(); if (id) body.set('id', id); body.set('name', name);
      fetch(url, {method:'POST', body, credentials:'same-origin'})
        .then(parseJsonResponse)
        .then(function(){
          tOK('Category saved');
          if (nameEl) nameEl.value='';
          if (idEl) idEl.value='';
          loadCategories().then(function(){ renderCategories && renderCategories(); });
        })
        .catch(function(err){ tErr('Save failed: '+(err&&err.message||err), true); });
    });
    if (cancelBtn){
      cancelBtn.addEventListener('click', function(ev){ ev.preventDefault(); if (nameEl) nameEl.value=''; if (idEl) idEl.value=''; });
    }
  }


  // Modal helpers
  function openModal(){ var m = $('#bmModal'); if(!m) return; m.hidden=false; m.setAttribute('aria-hidden','false'); }
  function closeModal(){ var m = $('#bmModal'); if(!m) return; m.hidden=true; m.setAttribute('aria-hidden','true'); }
  function wireModalButtons(){
    var openBtn = $('#openManage');
    var closeBtn = $('#bmModal .modal-close');
    if (openBtn) openBtn.addEventListener('click', function(e){ e.preventDefault(); openModal(); });
    if (closeBtn) closeBtn.addEventListener('click', function(e){ e.preventDefault(); closeModal(); });
    // Close on backdrop click
    var m = $('#bmModal');
    if (m) m.addEventListener('click', function(e){ if (e.target === m) closeModal(); });
  }

  function join(a,b){ if(!a.endsWith('/')) a+='/'; return a + b.replace(/^\/+/, ''); }
  function dirname(p){ var i=p.lastIndexOf('/'); if(i<=0) return '/'; return p.slice(0,i+1); }
  function uniq(a){ var s=new Set(),o=[]; for (var i=0;i<a.length;i++){ if(!s.has(a[i])){s.add(a[i]);o.push(a[i]);}} return o; }

  function resolveCandidates(endpoint){
    var loc = window.location;
    var base = loc.origin + loc.pathname; var dir = dirname(base);
    var c = [];
    try { if (typeof project_url === 'function') c.push(project_url('/api/'+endpoint)); } catch(e){}
    c.push(apiUrl(endpoint));
    c.push(loc.origin + '/api/' + endpoint);
    c.push(join(dir, 'api/'+endpoint));
    var up = dirname(dir.slice(0, -1));
    c.push(join(up, 'api/'+endpoint));
    c.push('api/'+endpoint);
    return uniq(c);
  }
  function fetchFirstGood(endpoint, opt){
    var urls = resolveCandidates(endpoint);
    var idx = 0, lastErr = null, tried=[];
    return new Promise(function(resolve, reject){
      (function step(){
        if (idx >= urls.length) return reject({message:'All endpoints failed', tried: tried, lastErr: lastErr});
        var url = urls[idx++]; tried.push(url);
        fetch(url + (opt && opt.cacheBust ? ((url.indexOf('?')>=0?'&':'?')+'t='+Date.now()) : ''), {
          credentials: 'same-origin', cache: 'no-store',
          method: (opt && opt.method) || 'GET',
          headers: (opt && opt.headers) || undefined,
          body: (opt && opt.body) || undefined
        }).then(function(r){
          if (!r.ok) { lastErr = 'HTTP '+r.status; return step(); }
          resolve({url:url, resp:r, tried: tried});
        }).catch(function(err){ lastErr = err && err.message || String(err); step(); });
      })();
    });
  }
  
// --- robust project root + api base resolution (drop-anywhere) ---
function __projectBase(){
  try { if (typeof window !== 'undefined' && window.PROJECT_BASE) return String(window.PROJECT_BASE); } catch(e){}
  try {
    var scripts = document.getElementsByTagName('script');
    for (var i=scripts.length-1;i>=0;i--){
      var src = scripts[i].getAttribute('src') || '';
      var m = src.match(/^(.*)\/assets\/js\/(?:pages|util)\/[^\/]+\.js(?:\?.*)?$/i);
      if (m) return m[1] || '/';
    }
  } catch(e){}
  try {
    var p = location.pathname.replace(/\/[^\/]*$/, '');
    return p || '/';
  } catch(e){}
  return '/';
}
function __apiBase(){
  try { if (typeof window !== 'undefined' && window.API_BASE) return String(window.API_BASE); } catch(e){}
  var base = __projectBase().replace(/\/+$/,'');
  return base + '/api/';
}
function __join(a,b){ if(!a) return b; if(!b) return a; return (a.replace(/\/+$/,'') + '/' + String(b).replace(/^\/+/,'')); }
function apiUrl(ep){ return __join(__apiBase(), ep); }
// --- end robust resolution ---
  function parseJsonResponse(r){
    return r.text().then(function(txt){
      if (!r.ok){
        const head = txt.slice(0,120).replace(/\s+/g,' ').trim();
        const msg = 'HTTP '+r.status+(head?(' – '+head):'');
        throw new Error(msg);
      }
      try { return JSON.parse(txt); } catch(e){ logE('bm_bad_json', {snippet: txt.slice(0,200)}); throw new Error('Bad JSON from server'); }
    });
  }

  function init(){
    // Prefer server-provided base (data attribute)
    var root = $('#bookmarks-root');
    var hinted = root && root.dataset && root.dataset.apiBase;
    if (hinted){
      API_BASE = hinted.endsWith('/') ? hinted : (hinted + '/');
      logI('bookmarks_api_base_hint', {base: API_BASE});
      wireUI(); wireModalButtons(); wireCategoryForm({formSel:'#catForm', nameSel:'#catName', idSel:'#catId', cancelSel:'#catCancel'}); wireCategoryForm({formSel:'#catFormMain', nameSel:'#catNameMain', idSel:'#catIdMain', cancelSel:'#catCancelMain'});
      loadCategories().then(loadList).catch(function(){ loadList(); });
    // Handle bookmarklet prefill via location.hash (#add?u=...&t=...&tags=...)
    try {
      var hash = (location.hash||'').replace(/^#/, '');
      if (hash.startsWith('add?')) {
        var qs = new URLSearchParams(hash.slice(4));
        var u = qs.get('u') || '';
        var t = qs.get('t') || '';
        var tg = qs.get('tags') || '';
        $('#bmTitle').value = decodeURIComponent(t);
        $('#bmUrl').value = decodeURIComponent(u);
        $('#bmTags').value = decodeURIComponent(tg);
        $('#bmTitle').focus();
        // clear hash so refresh won't keep it
        history.replaceState(null, document.title, location.pathname + location.search);
      }
    } catch(e){ /* non-fatal */ }

      return;
    }

    // Fallback: discover API base using bookmarks_list.php
    fetchFirstGood('bookmarks_list.php', {cacheBust:true}).then(function(res){
      API_BASE = res.url.replace(/bookmarks_list\.php[\s\S]*$/,''); // keep trailing slash
      logI('bookmarks_api_base', {base: API_BASE, tried: res.tried});
      wireUI(); wireModalButtons(); wireCategoryForm({formSel:'#catForm', nameSel:'#catName', idSel:'#catId', cancelSel:'#catCancel'}); wireCategoryForm({formSel:'#catFormMain', nameSel:'#catNameMain', idSel:'#catIdMain', cancelSel:'#catCancelMain'});
      loadCategories().then(loadList).catch(function(){ loadList(); });
    // Handle bookmarklet prefill via location.hash (#add?u=...&t=...&tags=...)
    try {
      var hash = (location.hash||'').replace(/^#/, '');
      if (hash.startsWith('add?')) {
        var qs = new URLSearchParams(hash.slice(4));
        var u = qs.get('u') || '';
        var t = qs.get('t') || '';
        var tg = qs.get('tags') || '';
        $('#bmTitle').value = decodeURIComponent(t);
        $('#bmUrl').value = decodeURIComponent(u);
        $('#bmTags').value = decodeURIComponent(tg);
        $('#bmTitle').focus();
        // clear hash so refresh won't keep it
        history.replaceState(null, document.title, location.pathname + location.search);
      }
    } catch(e){ /* non-fatal */ }

    }).catch(function(err){
      var tried = (err && err.tried) ? err.tried.slice(0,3).join(' | ') : '';
      tErr('Failed to load bookmarks (API not found). Tried: '+tried, true);
      logE('bookmarks_api_discovery_failed', err || {});
    });
  }

  function wireUI(){
    var r = $('#bmRefresh'); if (r) r.addEventListener('click', function(){ loadCategories().then(loadList).catch(loadList); });
    var c = $('#bmCancel');  if (c) c.addEventListener('click', resetForm);
    var s = $('#bmSave');    if (s) s.addEventListener('click', function(ev){ ev.preventDefault(); save(); });
    var cf = $('#bmCatFilter'); if (cf) cf.addEventListener('change', renderList);
    var so = $('#bmSort'); if (so) so.addEventListener('change', renderList);
    var cSave = $('#catSave'); if (cSave) cSave.addEventListener('click', function(ev){ ev.preventDefault(); catSave(); });
    var cCancel = $('#catCancel'); if (cCancel) cCancel.addEventListener('click', function(){ catReset(); });
    var cDel = $('#catDelete'); if (cDel) cDel.addEventListener('click', function(ev){ ev.preventDefault(); if(!$('#catId').value) return; if(!confirm('Delete this category? Bookmarks will become Uncategorized.')) return; fetch(apiUrl('bm_categories_delete.php?id='+encodeURIComponent($('#catId').value)), {method:'POST', credentials:'same-origin'}).then(parseJsonResponse).then(()=>{ tOK('Category deleted'); loadCategories().then(renderList); }).catch(err=> tErr('Delete failed: '+(err&&err.message||err), true)); });
    var cl = $('#catList'); if (cl) cl.addEventListener('click', onCatListClick);
  }

  // ---------- Categories ----------
  function loadCategories(){
    return fetch(apiUrl('bm_categories_list.php')+'?t='+Date.now(), {credentials:'same-origin', cache:'no-store'})
      .then(parseJsonResponse).then(function(data){
        CATS = (data && data.items) ? data.items.slice().sort(catSort) : [];
        renderCategories();
        fillCategorySelects();
        return CATS;
      }).catch(function(err){
        logE('bm_categories_load_failed', {err: String(err && err.message || err)});
      });
  }
  function catSort(a,b){ var sa = (a.sort|0), sb = (b.sort|0); if (sa!==sb) return sa-sb; return String(a.name||'').localeCompare(String(b.name||'')); }
  function renderCategories(){
    var root = $('#catList'), empty = $('#catEmpty');
    if (!root || !empty) return;
    if (!CATS || !CATS.length){ root.innerHTML=''; empty.style.display='block'; return; }
    empty.style.display='none';
    root.innerHTML = CATS.map(function(c){
      return `<div class="row" data-id="${esc(c.id)}">
        <span class="name">${esc(c.name)}</span>
        <span class="actions">
          <button class="btn" data-act="cup">↑</button>
          <button class="btn" data-act="cdn">↓</button>
          <button class="btn" data-act="cedit">Edit</button>
          <button class="btn danger" data-act="cdel">Delete</button>
        </span>
      </div>`;
    }).join('');
  }
  function fillCategorySelects(){
    var sel = $('#bmCategory'); if (!sel) return;
    var cf  = $('#bmCatFilter');
    var opts = [`<option value="">Uncategorized</option>`].concat(CATS.map(c=>`<option value="${esc(c.id)}">${esc(c.name)}</option>`));
    sel.innerHTML = opts.join('');
    if (cf) { cf.innerHTML = `<option value="">All</option>` + CATS.map(c=>`<option value="${esc(c.id)}">${esc(c.name)}</option>`).join(''); }
  }
  function onCatListClick(ev){
    var btn = ev.target.closest && ev.target.closest('button'); if (!btn) return;
    var row = ev.target.closest('.row'); var id = row && row.getAttribute('data-id'); if (!id) return;
    var idx = CATS.findIndex(c=>c.id===id); if (idx<0) return;
    var act = btn.dataset.act;
    if (act==='cedit'){
      $('#catId').value = CATS[idx].id;
      $('#catName').value = CATS[idx].name || '';
      $('#catName').focus();
    } else if (act==='cdel'){
      if (!confirm('Delete this category? Bookmarks will become Uncategorized.')) return;
      fetch(apiUrl('bm_categories_delete.php?id='+encodeURIComponent(id)), {method:'POST', credentials:'same-origin'})
        .then(parseJsonResponse).then(()=>{ tOK('Category deleted'); loadCategories().then(renderList); })
        .catch(err=> tErr('Delete failed: '+(err&&err.message||err), true));
    } else if (act==='cup' || act==='cdn'){
      var j = act==='cup' ? Math.max(0, idx-1) : Math.min(CATS.length-1, idx+1);
      if (j===idx) return;
      var x = CATS[idx]; CATS.splice(idx,1); CATS.splice(j,0,x);
      renderCategories();
      var ids = CATS.map(c=>c.id);
      fetch(apiUrl('bm_categories_reorder.php'), {
        method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ids: ids})
      }).then(parseJsonResponse).then(()=>{ tOK('Order saved'); fillCategorySelects(); renderList(); })
        .catch(err=> tErr('Reorder failed: '+(err&&err.message||err), true));
    }
  }
  function catReset(){ $('#catId').value=''; $('#catName').value=''; }
  function catSave(){
    var id = $('#catId').value || null;
    var name = ($('#catName')&&$('#catName').value||'').trim();
    if (!name){ tWarn('Category name required'); return; }
    fetch(apiUrl('bm_categories_upsert.php'), {
      method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'},
      body: JSON.stringify({id:id, name:name})
    }).then(parseJsonResponse).then(()=>{ tOK(id?'Category updated':'Category added'); catReset(); loadCategories().then(renderList); })
      .catch(err=> tErr('Save failed: '+(err&&err.message||err), true));
  }

  // ---------- Bookmarks ----------
  function loadList(){
    return fetch(apiUrl('bookmarks_list.php')+'?t='+Date.now(), {credentials:'same-origin', cache:'no-store'})
      .then(parseJsonResponse).then(function(data){
        BOOKS = (data && data.items) ? data.items : [];
        renderList();
      }).catch(err=>{
        tErr('Failed to load bookmarks: '+(err && err.message || err), true);
        logE('bookmarks_load_failed', {err: String(err && err.message || err)});
      });
  }
  function currentCatFilter(){ var v = ($('#bmCatFilter') && $('#bmCatFilter').value) || ''; return v; }
  function currentSort(){ var v = ($('#bmSort') && $('#bmSort').value) || 'updated_desc'; return v; }
  function renderList(){
    var list = $('#bmList'), empty = $('#bmEmpty'); if (!list || !empty) return;
    var cat = currentCatFilter(); var sort = currentSort();
    var items = BOOKS.slice();
    if (cat){ items = items.filter(b=>String(b.category_id||'')===String(cat)); }
    items.sort(function(a,b){
      if (sort==='title_asc'){ return String(a.title||'').localeCompare(String(b.title||'')); }
      if (sort==='host_asc'){ return String(a.host||'').localeCompare(String(b.host||'')); }
      return (b.updated||0)-(a.updated||0);
    });
    if (!items.length){ list.innerHTML=''; empty.style.display='block'; return; }
    empty.style.display='none';
    list.innerHTML = items.map(renderItem).join('');
    list.onclick = onListClick;
  }

  function renderItem(it){
    const fav = apiUrl('favicon_proxy.php') + '?host=' + encodeURIComponent(it.host||'');
    const tags = (it.tags||[]).join(', ');
    const upd = it.updated ? new Date(it.updated*1000).toLocaleString() : '-';
    const cat = it.category_id ? (CATS.find(c=>c.id===it.category_id)?.name || '') : '';
    return `<div class="row" data-id="${esc(it.id)}">
      <span class="title"><img src="${fav}" alt="" class="fav"> ${esc(it.title||'')} ${cat?`<span class="muted">· ${esc(cat)}</span>`:''}</span>
      <span class="link"><a href="${esc(it.url)}" target="_blank" rel="noopener">${esc(it.url)}</a></span>
      <span class="tags">${esc(tags)}</span>
      <span class="updated">${esc(upd)}</span>
      <span class="actions">
        <button class="btn" data-act="edit">Edit</button>
        <button class="btn danger" data-act="delete">Delete</button>
      </span>
    </div>`;
  }

  function onListClick(ev){
    const btn = ev.target.closest && ev.target.closest('button'); if (!btn) return;
    const row = ev.target.closest('.row'); const id = row && row.getAttribute('data-id');
    if (btn.dataset.act === 'edit') {
      editId = id;
      var saveB = $('#bmSave'); if (saveB) saveB.textContent='Update';
      var it = BOOKS.find(x=>String(x.id)===String(id)); if (!it) return;
      var t=$('#bmTitle'), u=$('#bmUrl'), g=$('#bmTags'), c=$('#bmCategory');
      if (t) t.value = it.title||'';
      if (u) u.value   = it.url||'';
      if (g) g.value  = (it.tags||[]).join(', ');
      if (c) c.value = it.category_id || '';
      window.scrollTo({top:0, behavior:'smooth'});
    }
    if (btn.dataset.act === 'delete' && id) {
      if (!confirm('Delete this bookmark?')) return;
      fetch(apiUrl('bookmarks_delete.php?id='+encodeURIComponent(id)), {method:'POST', credentials:'same-origin'})
        .then(parseJsonResponse).then(()=>{ tOK('Bookmark deleted'); loadList(); })
        .catch(err=> { tErr('Delete failed: '+(err && err.message || err), true); });
    }
  }

  
  function resetForm(){
    editId = null;
    var sBtn = $('#bmSave'); if (sBtn) sBtn.textContent = 'Save';
    if ($('#bmTitle')) $('#bmTitle').value='';
    if ($('#bmUrl')) $('#bmUrl').value='';
    if ($('#bmTags')) $('#bmTags').value='';
    if ($('#bmCategory')) $('#bmCategory').value='';
  }

  function save(){
    const payload = {
      id: editId,
      title: ($('#bmTitle')&&$('#bmTitle').value||'').trim(),
      url: ($('#bmUrl')&&$('#bmUrl').value||'').trim(),
      tags: ($('#bmTags')&&$('#bmTags').value||'').split(',').map(x=>x.trim()).filter(Boolean),
      category_id: ($('#bmCategory')&&$('#bmCategory').value) || null
    };
    if (!payload.url) { tWarn('URL is required'); return; }
    fetch(apiUrl('bookmarks_upsert.php'), {
      method:'POST', credentials:'same-origin',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify(payload)
    }).then(parseJsonResponse).then(resp=>{
      if (!resp || resp.error){ tErr('Save failed: '+(resp && resp.error || '')); return; }
      tOK(editId? 'Bookmark updated':'Bookmark added');
      editId = null;
      if ($('#bmTitle')) $('#bmTitle').value='';
      if ($('#bmUrl')) $('#bmUrl').value='';
      if ($('#bmTags')) $('#bmTags').value='';
      if ($('#bmCategory')) $('#bmCategory').value='';
      loadList();
    }).catch(err=> tErr('Save failed: '+(err&&err.message||err), true));
  }
})();