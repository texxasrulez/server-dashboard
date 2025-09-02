
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
    var card = el('div','card');
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
    var card2 = el('div','card');
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
  }
  // Run on load, after a tick
  document.addEventListener('DOMContentLoaded', function(){ setTimeout(ensureSiteCards, 50); });
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