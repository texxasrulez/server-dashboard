(function(){
  'use strict';
  function $(s){ return document.querySelector(s); }
  function $all(s){ return Array.prototype.slice.call(document.querySelectorAll(s)); }
  function el(tag, cls){ var n=document.createElement(tag); if(cls) n.className=cls; return n; }
  function paneEl(){ return document.getElementById('configPane') || document.getElementById('pane'); }
  function isSiteActive(){
    var btn = $('#configTabs .btn.primary');
    if (btn && (btn.dataset.section||'').toLowerCase()==='site') return true;
    // fallback to hash
    var h = (location.hash||'').toLowerCase();
    return h.indexOf('site')>=0;
  }
  function ensureImportExport(pane){
    pane = pane || paneEl(); if (!pane) return;
    var has = false;
    pane.querySelectorAll('.card h3').forEach(function(h){
      var t=(h.textContent||'').toLowerCase();
      if (t.indexOf('backup & restore')>=0 || t.indexOf('backup and restore')>=0) has = true;
    });
    if (has) return;
    var card = el('div', 'card');
    var h = el('h3'); h.textContent = 'Backup & Restore'; card.appendChild(h);
    var row = el('div'); row.style.display='flex'; row.style.flexWrap='wrap'; row.style.alignItems='center'; row.style.gap='12px';
    var exportBtn = el('button','btn'); exportBtn.textContent='Export Config';
    exportBtn.addEventListener('click', function(){
      var url = 'api/config_export.php?_csrf=' + encodeURIComponent(window.__CONFIG_CSRF__ || '');
      window.location.href = url;
    });
    var file = el('input'); file.type='file'; file.accept='application/json,.json';
    var importBtn = el('button','btn secondary'); importBtn.textContent='Import Config';
    importBtn.addEventListener('click', function(){
      if(!file.files || !file.files[0]){ window.toast && window.toast.warn('Choose a backup file first'); return; }
      var fd = new FormData();
      fd.append('_csrf', window.__CONFIG_CSRF__||'');
      fd.append('file', file.files[0]);
      fetch('api/config_import.php', { method:'POST', body: fd })
        .then(function(r){ return r.json().catch(function(){ return null; }); })
        .then(function(j){ if(j&&j.ok){ window.toast&&window.toast.success('Imported. Reloading…'); setTimeout(function(){ location.reload(); }, 700); } else { throw new Error((j&&j.error)||'Import failed'); } })
        .catch(function(e){ window.toast&&window.toast.error('Import failed: '+(e&&e.message||'unknown')); });
    });
    row.appendChild(exportBtn);
    row.appendChild(file);
    row.appendChild(importBtn);
    card.appendChild(row);
    pane.appendChild(card);
  }
  function ensureRetention(pane){
    pane = pane || paneEl(); if (!pane) return;
    var exists = false;
    pane.querySelectorAll('.card h3').forEach(function(h){
      var t=(h.textContent||'').toLowerCase();
      if (t.indexOf('backups — retention')>=0 || t.indexOf('backups - retention')>=0) exists = true;
    });
    if (exists) return;
    var card2 = el('div', 'card');
    var h2 = el('h3'); h2.textContent='Backups — Retention'; card2.appendChild(h2);
    var row2 = el('div'); row2.style.display='flex'; row2.style.flexWrap='wrap'; row2.style.alignItems='center'; row2.style.gap='12px';
    var keepDefault = 20;
    try { if (window.__CONFIG_DATA__ && window.__CONFIG_DATA__.site && typeof window.__CONFIG_DATA__.site.backup_keep === 'number') keepDefault = window.__CONFIG_DATA__.site.backup_keep; } catch(_){}
    var keepLbl = el('label'); keepLbl.textContent='Keep last'; keepLbl.style.opacity=.8;
    var keep = el('input'); keep.type='number'; keep.min='5'; keep.max='200'; keep.value=String(keepDefault); keep.title='How many backups to keep'; keep.name='site.backup_keep';
    var keepLbl2 = el('span'); keepLbl2.textContent='files'; keepLbl2.style.opacity=.8;
    var stats = el('span'); stats.className='muted'; stats.textContent='(loading stats…)';
    function refreshStats(){
      fetch('api/config_backup.php?stats=1', {credentials:'same-origin'})
      .then(function(r){ return r.json().catch(function(){ return null; }); })
      .then(function(j){
        if(!j||!j.ok) throw new Error('stats failed');
        var cnt=(j.count!=null? j.count : '?'); var sz=(j.total_size_human || j.total_size || '');
        stats.textContent='(current: '+cnt+' file'+(cnt==1?'':'s')+' / '+sz+')';
      }).catch(function(){ stats.textContent=''; });
    }
    refreshStats();
    var pruneBtn = el('button','btn'); pruneBtn.textContent='Prune now';
    pruneBtn.addEventListener('click', function(){
      var v=Math.max(5, Math.min(200, parseInt(keep.value||'20',10)));
      fetch('api/config_backup.php?prune=1&keep='+encodeURIComponent(v), {method:'POST'})
      .then(function(r){ return r.json().catch(function(){ return null; }); })
      .then(function(j){
        if(j&&j.ok){ window.toast&&window.toast.success('Pruned — kept '+(j.keep||'?')+', deleted '+(j.deleted||0)); refreshStats(); }
        else { throw new Error((j&&j.error)||'Prune failed'); }
      }).catch(function(e){ window.toast&&window.toast.error('Prune failed: '+(e&&e.message||'unknown')); });
    });
    var mkBtn = el('button','btn'); mkBtn.textContent='Create backup now';
    mkBtn.addEventListener('click', function(){
      fetch('api/config_backup.php', {method:'POST'})
      .then(function(r){ return r.json().catch(function(){ return null; }); })
      .then(function(j){ if(j&&j.ok){ window.toast&&window.toast.success('Backup saved: '+(j.path||'')); refreshStats(); } else { throw new Error((j&&j.error)||'Backup failed'); } })
      .catch(function(e){ window.toast&&window.toast.error('Backup failed: '+(e&&e.message||'unknown')); });
    });
    var dlBtn = el('button','btn'); dlBtn.textContent='Download latest backup';
    dlBtn.addEventListener('click', function(){
      fetch('api/config_backup.php?latest=1', {credentials:'same-origin'})
      .then(function(r){ return r.json().catch(function(){ return null; }); })
      .then(function(j){ if(j&&j.ok&&j.exists){ window.location.href='api/config_backup.php?download_latest=1'; } else { throw new Error('No backups found'); } })
      .catch(function(e){ window.toast&&window.toast.error('Download failed: '+(e&&e.message||'unknown')); });
    });
    row2.appendChild(keepLbl); row2.appendChild(keep); row2.appendChild(keepLbl2); row2.appendChild(stats);
    row2.appendChild(pruneBtn); row2.appendChild(mkBtn); row2.appendChild(dlBtn);
    card2.appendChild(row2);
    pane.appendChild(card2);
  }
  function ensureSiteCards(){
    if (!isSiteActive()) return;
    var p = paneEl(); if (!p) return;
    ensureImportExport(p);
    ensureRetention(p);
    ensureLanguage(p);
  }
  // Run on load, after a tick
  document.addEventListener('DOMContentLoaded', function(){ setTimeout(ensureSiteCards, 50); });
  
  


function ensureLanguage(pane){
  pane = pane || paneEl(); if (!pane) return;
  if (pane.querySelector('#siteLangSelect')) return;

  function el(tag, cls){ var n=document.createElement(tag); if(cls) n.className=cls; return n; }
  function norm(t){ return String(t||'').trim().toLowerCase(); }

  // Find the Theme label exactly
  var themeLbl = pane.querySelector('label.label[for="site.theme"]');
  if (!themeLbl){
    var labs = pane.querySelectorAll('label');
    for (var i=0;i<labs.length;i++){
      var txt = (labs[i].textContent||'').trim();
      if (txt === 'Theme' || norm(txt) === 'theme'){ themeLbl = labs[i]; break; }
    }
  }
  if (!themeLbl) return;

  var themeRow = themeLbl.parentNode || null;
  var parent = themeRow && themeRow.parentNode;

  var valueCell = null;
  if (themeRow){
    for (var j=0;j<themeRow.children.length;j++){
      var c = themeRow.children[j];
      if (c !== themeLbl){ valueCell = c; break; }
    }
  }

  var row = document.createElement('div');
  row.className = themeRow ? themeRow.className : 'row';

  var label = document.createElement('label');
  label.className = themeLbl ? themeLbl.className : 'label';
  label.textContent = 'Language';

  var valWrap = document.createElement('div');
  valWrap.className = valueCell ? valueCell.className : '';

  var sel = document.createElement('select'); sel.className = 'input'; sel.id = 'siteLangSelect';
  var save = document.createElement('button'); save.className = 'btn'; save.id = 'siteLangSave'; save.textContent = 'Save';
  var st = document.createElement('span'); st.className = 'muted'; st.id = 'siteLangStatus'; st.style.marginLeft = '8px';

  valWrap.appendChild(sel); valWrap.appendChild(save); valWrap.appendChild(st);
  row.appendChild(label); row.appendChild(valWrap);

  if (parent && themeRow && parent.insertBefore){
    if (themeRow.nextSibling) parent.insertBefore(row, themeRow.nextSibling);
    else parent.appendChild(row);
  } else {
    pane.appendChild(row);
  }

  var cur = 'en';
  try { if (window.__CONFIG_DATA__ && window.__CONFIG_DATA__.i18n && window.__CONFIG_DATA__.i18n.locale){ cur = String(window.__CONFIG_DATA__.i18n.locale || 'en'); } } catch(_){}
  fetch('api/i18n_languages.php', {credentials:'same-origin'})
    .then(function(r){ return r.json(); })
    .then(function(j){
      if (!j || !j.ok || !Array.isArray(j.languages)) return;
      sel.innerHTML='';
      j.languages.forEach(function(it){
        var o = document.createElement('option'); o.value = it.code; o.textContent = it.name || (it.code||'').toUpperCase();
        if (it.code === cur) o.selected = true;
        sel.appendChild(o);
      });
    }).catch(function(){});

  save.addEventListener('click', function(){
    var locale = sel.value || 'en';
    var body = JSON.stringify({ config: { i18n: { locale: locale } } });
    var url = 'api/config_import.php?_csrf=' + encodeURIComponent(window.__CONFIG_CSRF__||'');
    save.disabled = true; st.textContent = 'Saving…';
    fetch(url, { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'}, body: body })
      .then(function(r){ return r.json().catch(function(){return null;}); })
      .then(function(j){
        if (j && j.ok) {
          st.textContent = 'Saved';
          try { document.documentElement.setAttribute('lang', locale); } catch(_){}
          try { window.__CONFIG_DATA__ = window.__CONFIG_DATA__ || {}; (window.__CONFIG_DATA__.i18n = window.__CONFIG_DATA__.i18n || {}).locale = locale; } catch(_){}
        } else {
          st.textContent = 'Save failed';
        }
      })
      .catch(function(){ st.textContent = 'Save failed'; })
      .finally(function(){ save.disabled=false; setTimeout(function(){ st.textContent=''; }, 2000); });
  });
}




// Listen for tab clicks
  document.addEventListener('click', function(e){
    var btn = e.target.closest && e.target.closest('#configTabs .btn');
    if (!btn) return;
    var sec = (btn.dataset.section||'').toLowerCase();
    if (sec === 'site'){ setTimeout(ensureSiteCards, 0); }
  });
  // Observe pane changes
  var p = paneEl();
  if (p && window.MutationObserver){
    var mo = new MutationObserver(function(){ ensureSiteCards(); });
    mo.observe(p, {childList:true, subtree:false});
  }
})();


  /* i18n language hook */
  function maybeInitLanguage(){
    if (isSiteActive()) try { ensureLanguage(paneEl()); } catch(_){}
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', maybeInitLanguage);
  else maybeInitLanguage();
  window.addEventListener('hashchange', maybeInitLanguage);
