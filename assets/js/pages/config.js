/*! Combined config.js — unified UI renderer + Email accounts UI (drop-in)
   * This file replaces separate config.boot.js and config.email.js.
   * Keep only this script for config.php.
*/
(function(){
  'use strict';

  function $(s, ctx){ return (ctx||document).querySelector(s); }
  function $all(s, ctx){ return Array.prototype.slice.call((ctx||document).querySelectorAll(s)); }
  function el(tag, cls){ var e=document.createElement(tag); if(cls) e.className=cls; return e; }

  var schema = window.__CONFIG_SCHEMA__ || {};
  var data   = window.__CONFIG_DATA__   || {};

  // persist current tab between saves/reloads
  var ACTIVE_KEY = 'config.active_section';
  function getActive(){ try { return localStorage.getItem(ACTIVE_KEY) || ''; } catch(e){ return ''; } }
  function setActive(s){ try { localStorage.setItem(ACTIVE_KEY, s || ''); } catch(e){} }


  function build(){
    // tabs
    var tabs = $('#configTabs'); if (!tabs) return; tabs.innerHTML='';
    tabs.classList.add('row'); // align like your header button rows
    var pane = $('#configPane'); if (pane) pane.innerHTML='';

    var sections = Object.keys(schema).filter(function(k){ return k[0] !== '_' && k !== 'cron'; });
    sections.forEach(function(section, idx){
      var sec = schema[section] || {};
      // Use themed buttons for tabs (secondary by default; primary when active)
      var btn = el('button', 'btn secondary small');
      btn.type = 'button';
      btn.textContent = sec._label || section;
      btn.dataset.section = section;
      btn.style.marginRight = '6px';

      if (idx === 0) {
        btn.classList.remove('secondary');
        btn.classList.add('primary');
      }

      btn.addEventListener('click', function(){
        // reset all to secondary
        $all('#configTabs .btn').forEach(function(b){
          b.classList.remove('primary');
          b.classList.add('secondary');
        });
        // set this one to primary and render
        this.classList.remove('secondary');
        this.classList.add('primary');
        setActive(section);
        render(section);
      });

      tabs.appendChild(btn);
    });

    if (sections.length){
    var initial = getActive();
    var start = sections.indexOf(initial) >= 0 ? initial : sections[0];
    // update tab button visual state to match start
    $all('#configTabs .btn').forEach(function(b){
      if (b.dataset && b.dataset.section === start){
        b.classList.remove('secondary'); b.classList.add('primary');
      } else {
        b.classList.remove('primary'); b.classList.add('secondary');
      }
    });
    render(start);
  }
  }

  function isHiddenField(rule){ try { if (rule && (rule.hidden === true)) return true; var l=(rule&&rule.label)||''; return /(managed by ui)/i.test(l); } catch(e){ return false; } }

  function render(section){
    var pane = $('#configPane'); if (!pane) return;
    pane.innerHTML='';
    var sec = schema[section]; if (!sec) return;

    Object.keys(sec).forEach(function(key){
      if (key==='_label') return;
      var rule = sec[key];
      if (isHiddenField(rule)) return;

      if (rule && !rule.type){ // nested object -> fieldset
        var fieldset = el('fieldset'); var lg = el('legend');
        lg.textContent = rule._label || key; fieldset.appendChild(lg);
        Object.keys(rule).forEach(function(sub){
          if (sub==='_label') return;
          if (isHiddenField(rule[sub])) return;
          fieldset.appendChild(renderField([section,key,sub], rule[sub]));
        });
        pane.appendChild(fieldset);
      } else {
        pane.appendChild(renderField([section,key], rule));
      }
    });
  
// ---- Extras injected: Alerts + SMTP test ----
if (section === 'alerts') {
  var btnRow = el('div'); btnRow.className='field';
  var testBtn = el('button', 'btn secondary'); testBtn.textContent = 'Send test email';
  btnRow.appendChild(testBtn);
  pane.appendChild(btnRow);
  testBtn.addEventListener('click', function(){
    var payload = { _csrf: window.__CONFIG_CSRF__ };
    fetch('api/alerts_test.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    }).then(async function(r){
      let j=null; try{ j = await r.json(); } catch(_){}
      if(!r.ok) throw new Error((j && j.error) || ('HTTP '+r.status));
      return j;
    }).then(function(res){
      if (res && res.ok) { window.toast && window.toast.success('Test alert sent'); }
      else { throw new Error((res && res.error) || 'Send failed'); }
    }).catch(function(e){
      window.toast && window.toast.error('Test send failed: ' + e.message);
    });
  });
}

if (section === 'integrations') {
  var card = el('div', 'card');
  var row = el('div'); row.style.display='flex'; row.style.flexWrap='wrap'; row.style.alignItems='center'; row.style.gap='8px';
  var lab = el('label'); lab.textContent = 'Test recipient:'; lab.style.minWidth='110px';
  var inp = el('input'); inp.type='email'; inp.placeholder='you@example.com'; inp.id='smtpTestTo'; inp.style.minWidth='240px';
  var btn = el('button', 'btn secondary'); btn.id='btnSmtpTest'; btn.textContent='Send SMTP test';
  row.appendChild(lab); row.appendChild(inp); row.appendChild(btn);
  card.appendChild(row);
  pane.appendChild(card);

  btn.addEventListener('click', function(){
    var payload = { _csrf: window.__CONFIG_CSRF__, to: (inp.value||'').trim() };
    fetch('api/smtp_test.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    }).then(async function(r){
      let j=null; try{ j = await r.json(); } catch(_){}
      if(!r.ok) throw new Error((j && j.error) || ('HTTP '+r.status));
      return j;
    }).then(function(res){
      if (res && res.ok) { window.toast && window.toast.success('SMTP test sent'); }
      else { throw new Error((res && res.error) || 'Send failed'); }
    }).catch(function(e){
      window.toast && window.toast.error('SMTP test failed: ' + e.message);
    });
  });
}

if (section === 'mail') {
  // Extra tools for Mail section: send test alert
  var card = el('div', 'card');
  var row = el('div'); row.style.display='flex'; row.style.flexWrap='wrap'; row.style.alignItems='center'; row.style.gap='8px';
  var lab = el('label'); lab.textContent = 'Test recipient:'; lab.style.minWidth='110px';
  var inp = el('input'); inp.type='email'; inp.placeholder='you@example.com'; inp.id='mailTestTo'; inp.style.minWidth='240px';
  var btn = el('button', 'btn secondary'); btn.id='btnMailTest'; btn.textContent='Send alert test';
  row.appendChild(lab); row.appendChild(inp); row.appendChild(btn);
  card.appendChild(row);
  pane.appendChild(card);

  btn.addEventListener('click', function(){
    var to = (inp.value||'').trim();
    if (!to) { window.toast && window.toast.error('Enter a test recipient'); return; }
    var payload = { _csrf: window.__CONFIG_CSRF__, to: to };
    fetch('api/alerts_test.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    }).then(async function(r){
      let j=null; try{ j = await r.json(); } catch(_){}
      if(!r.ok) throw new Error((j && j.error) || ('HTTP '+r.status));
      return j;
    }).then(function(res){
      if (res && res.ok) { window.toast && window.toast.success('Alert test sent'); }
      else { throw new Error((res && res.error) || 'Send failed'); }
    }).catch(function(e){
      window.toast && window.toast.error('Alert test failed: ' + e.message);
    });
  });
}

// ---- end extras ----

// --- Backup/Restore UI (Import/Export) ---
if (section === 'site') {
  try {
    var paneNode = (typeof pane!=='undefined' && pane) ? pane : (document.getElementById('configPane') || (document.getElementById('configPane') || document.getElementById('pane')));
    var card = el('div', 'card');
    var h = el('h3'); h.textContent = 'Backup & Restore'; card.appendChild(h);

    var row = el('div');
    row.style.display='flex'; row.style.flexWrap='wrap'; row.style.alignItems='center'; row.style.gap='12px';

    var exportBtn = el('button', 'btn'); exportBtn.textContent = 'Export Config';
    exportBtn.addEventListener('click', function(){
      var url = 'api/config_export.php?_csrf=' + encodeURIComponent(window.__CONFIG_CSRF__ || '');
      window.location.href = url;
    });
    row.appendChild(exportBtn);

    var file = el('input'); file.type='file'; file.accept='application/json';
    var importBtn = el('button', 'btn secondary'); importBtn.textContent = 'Import Config';
    importBtn.addEventListener('click', function(){
      if (!file.files || !file.files[0]) { window.toast && window.toast.warn('Choose a backup file first'); return; }
      var fd = new FormData();
      fd.append('_csrf', window.__CONFIG_CSRF__||'');
      fd.append('file', file.files[0]);
      fetch('api/config_import.php', { method: 'POST', body: fd })
      .then(async function(r){ let j=null; try{ j=await r.json(); }catch(_){}
        if(!r.ok) throw new Error((j&&j.error)||('HTTP '+r.status));
        return j;
      })
      .then(function(res){
        if(res && res.ok){ window.toast && window.toast.success('Imported. Reloading…'); setTimeout(function(){ location.reload(); }, 800); }
        else { throw new Error('Import failed'); }
      })
      .catch(function(e){ window.toast && window.toast.error('Import failed: '+e.message); });
    });

    row.appendChild(file);
    row.appendChild(importBtn);
    card.appendChild(row);
    paneNode && paneNode.appendChild(card);
  } catch(e) { try { console.error('backup/restore ui error', e); } catch(_) {} }
}
// --- end Import/Export ---

// --- Backups — Retention (persisted) v2.2 ---
if (section === 'site') {
  try {
    var paneNode = (typeof pane!=='undefined' && pane) ? pane : (document.getElementById('configPane') || (document.getElementById('configPane') || document.getElementById('pane')));
    var card2 = el('div','card');
    var h2 = el('h3'); h2.textContent = 'Backups — Retention'; card2.appendChild(h2);

    var row2 = el('div'); row2.style.display='flex'; row2.style.flexWrap='wrap'; row2.style.alignItems='center'; row2.style.gap='12px';

    var keepDefault = 20;
    try { if (window.__CONFIG_DATA__ && window.__CONFIG_DATA__.site && typeof window.__CONFIG_DATA__.site.backup_keep === 'number') keepDefault = window.__CONFIG_DATA__.site.backup_keep; } catch(_){}

    var keepLbl = el('label'); keepLbl.textContent = 'Keep last'; keepLbl.style.opacity = .8;
    var keep = el('input'); keep.type='number'; keep.min='5'; keep.max='200'; keep.value= String(keepDefault); keep.title='How many backups to keep';
    keep.name = 'site.backup_keep'; 
    var keepLbl2 = el('span'); keepLbl2.textContent = 'files'; keepLbl2.style.opacity=.8;

    var stats = el('span'); stats.className='muted'; stats.textContent='(loading stats…)';
    function refreshStats(){
      fetch('api/config_backup.php?stats=1', {credentials:'same-origin'})
        .then(function(r){ return r.json().catch(function(){ return null; }); })
        .then(function(j){
          if (!j || !j.ok) throw new Error();
          var cnt = (j.count!=null? j.count : '?');
          var sz  = (j.total_size_human || j.total_size || '');
          stats.textContent = '(current: '+cnt+' file'+(cnt==1?'':'s')+' / '+sz+')';
        }).catch(function(){ stats.textContent=''; });
    }
    refreshStats();

    var pruneBtn = el('button','btn'); pruneBtn.textContent = 'Prune now';
    pruneBtn.addEventListener('click', function(){
      var v = Math.max(5, Math.min(200, parseInt(keep.value||'20',10)));
      var url = 'api/config_backup.php?prune=1&keep=' + encodeURIComponent(v);
      fetch(url, {method:'POST'})
        .then(function(r){ return r.json().catch(function(){ return null; }); })
        .then(function(j){
          if (j && j.ok) { window.toast && window.toast.success('Pruned — kept '+(j.keep||'?')+', deleted '+(j.deleted||0)); refreshStats(); }
          else { throw new Error((j && j.error) || 'Prune failed'); }
        })
        .catch(function(e){ window.toast && window.toast.error(e.message || 'Prune failed'); });
    });

    var mkBtn = el('button','btn'); mkBtn.textContent = 'Create backup now';
    mkBtn.addEventListener('click', function(){
      fetch('api/config_backup.php', {method:'POST'})
        .then(function(r){ return r.json().catch(function(){ return null; }); })
        .then(function(j){ if(j && j.ok){ window.toast && window.toast.success('Backup saved: '+(j.path||'')); refreshStats(); } else { throw new Error((j && j.error)||'Backup failed'); } })
        .catch(function(e){ window.toast && window.toast.error(e.message||'Backup failed'); });
    });

    var dlBtn = el('button','btn'); dlBtn.textContent = 'Download latest backup';
    dlBtn.addEventListener('click', function(){
      fetch('api/config_backup.php?latest=1', {credentials:'same-origin'})
        .then(function(r){ return r.json().catch(function(){ return null; }); })
        .then(function(j){
          if (j && j.ok && j.exists){ window.location.href = 'api/config_backup.php?download_latest=1'; }
          else { throw new Error('No backups found'); }
        })
        .catch(function(e){ window.toast && window.toast.error(e.message || 'No backups found'); });
    });

    row2.appendChild(keepLbl); row2.appendChild(keep); row2.appendChild(keepLbl2); row2.appendChild(stats);
    row2.appendChild(pruneBtn); row2.appendChild(mkBtn); row2.appendChild(dlBtn);
    card2.appendChild(row2);
    paneNode && paneNode.appendChild(card2);
  } catch(e) { try { console.error('retention ui error', e); } catch(_){} }
}
// --- end Backups — Retention ---



// --- Cron Health card in History tab ---
if (section === 'history') {
  try {
    var paneNode = (typeof pane!=='undefined' && pane) ? pane : (document.getElementById('configPane') || (document.getElementById('configPane') || document.getElementById('pane')));
    var card = el('div','card');
    var h = el('h3'); h.textContent = 'Cron Health'; card.appendChild(h);
    var wrap = el('div'); card.appendChild(wrap);

    function chip(st){
      var c = el('span','chip'); c.textContent = (st||'').toUpperCase();
      if (st==='ok') c.classList.add('ok'); else if (st==='warn') c.classList.add('warn'); else if (st==='fail') c.classList.add('fail'); else c.classList.add('muted');
      c.style.marginLeft='8px'; return c;
    }
    function row(label, value, st){
      var r = el('div'); r.style.display='flex'; r.style.alignItems='center'; r.style.gap='8px'; r.style.margin='6px 0';
      var b = el('b'); b.textContent = label + ':'; r.appendChild(b);
      var s = el('span'); s.textContent = value; r.appendChild(s);
      if (st) r.appendChild(chip(st));
      wrap.appendChild(r);
    }

    fetch('api/cron_health.php').then(function(r){ return r.json(); }).then(function(j){
      if (!j || !j.ok) throw new Error('bad response');
      var fmt = function(ts){ if(!ts) return '—'; var d=new Date(ts*1000); return d.toLocaleString(); };
      row('Alerts last run', fmt(j.alerts.last), j.alerts.status);
      row('Alerts next due', fmt(j.alerts.next_due || 0));
      row('History last append', fmt(j.history.last), j.history.status);
      row('History next due', fmt(j.history.next_due || 0));
    }).catch(function(e){
      var p = el('p'); p.textContent = 'Cron health unavailable: ' + e.message; wrap.appendChild(p);
    });

    paneNode && paneNode.appendChild(card);
  } catch(e){ try{ console.error('cron health ui', e); }catch(_){} }
}
// --- end Cron Health card ---
}

  function renderField(path, rule){
    if (isHiddenField(rule)) { var frag=document.createDocumentFragment(); return frag; }
    var wrap = el('div','field');
    var label = el('label'); label.textContent = rule.label || path[path.length-1];
    label.htmlFor = path.join('__');
    wrap.appendChild(label);
    var input;

    var val = pathGet(data, path);
    var id = path.join('__');

    switch (rule.type){
      case 'bool':
        input = el('input'); input.type='checkbox'; input.checked = !!val;
        break;
      case 'int':
        input = el('input'); input.type='number';
        if(rule.min!=null) input.min=rule.min; if(rule.max!=null) input.max=rule.max;
        input.value = (val!=null? val : '');
        break;
      case 'enum':
        input = el('select');
        (rule.values || []).forEach(function(opt){
          var o = el('option'); o.value = opt; o.textContent = opt;
          if (val === opt) o.selected = true; input.appendChild(o);
        });
        break;
      case 'list':
        input = el('input'); input.type='text';
        input.placeholder='comma,separated,values';
        input.value = Array.isArray(val) ? val.join(', ') : (val||'');
        break;
      case 'secret':
        input = el('input'); input.type='password';
        input.value = (val || '');
        break;
      default: // string, url, email, timezone, etc.
        input = el('input'); input.type='text'; input.value = (val!=null? val : '');
    }

    input.id = id;
    input.dataset.path = JSON.stringify(path);
    wrap.appendChild(input);
    return wrap;
  }

  function pathGet(obj, path){
    return path.reduce(function(n,k){ return (n && n[k]!=null)? n[k] : undefined; }, obj);
  }

  function collect(){
    var out = JSON.parse(JSON.stringify(data || {}));
    $all('#configPane [data-path]').forEach(function(inp){
      var path = JSON.parse(inp.dataset.path);
      var v;
      if (inp.type === 'checkbox') v = inp.checked;
      else if (inp.tagName === 'SELECT') v = inp.value;
      else v = inp.value;

      // Normalize list inputs
      var rule = path.reduce((n,k)=>n && n[k]!=null ? n[k] : null, schema);
      if (rule && rule.type === 'list' && typeof v === 'string'){
        v = v.split(',').map(function(s){ return s.trim(); }).filter(Boolean);
      }

      var t = out;
      for (var i=0;i<path.length;i++){
        var k = path[i];
        if (i===path.length-1) t[k] = v;
        else { if (!t[k]) t[k] = {}; t = t[k]; }
      }
    });
    return out;
  }

  // Wire buttons
  document.addEventListener('DOMContentLoaded', function(){
    build();
    var save = $('#btnSave'), reset = $('#btnReset');
    if (save) save.addEventListener('click', function(){
      var payload = { _csrf: window.__CONFIG_CSRF__, settings: collect() };
      fetch('config.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      }).then(function(r){ return r.json(); }).then(function(res){
        if (res.ok){ window.toast && window.toast.success('Configuration saved.'); }
        else { window.toast && window.toast.error(res.error || 'Save failed'); }
      }).catch(function(e){
        window.toast && window.toast.error('Network error: ' + e.message);
      });
    });
    if (reset) reset.addEventListener('click', function(){
      build();
      window.toast && window.toast.info('Form reset');
    });
  });
})();

/* === BEGIN embedded email accounts UI (from config.email.js) === */
// assets/js/pages/config.email.js — v12 (light watcher + theme border; survives tab swaps)
(function(){
  "use strict";
  var VER = "v12-watch";
  try { console.debug("[email-ui] %s boot", VER); } catch(e){}

  if (!/config\.php(\?|$)/.test(location.pathname)) return;

  function $(sel, scope){ return (scope||document).querySelector(sel); }
  function $all(sel, scope){ return Array.prototype.slice.call((scope||document).querySelectorAll(sel)); }
  function on(el, ev, fn){ if(el) el.addEventListener(ev, fn, false); }
  function escapeHTML(s){
    var map={'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'};
    return String(s||'').replace(/[&<>"']/g, function(m){ return map[m]; });
  }

  var pane = document.getElementById('configPane') || document.body;
  var tabs = document.getElementById('configTabs');
  var containerId = 'emailAccountsList';

  function paneHasEmailSignals(){
    var hints = [
      /Enable Email polling/i,
      /Indicator Mode/i,
      /Google Client ID/i,
      /Microsoft Client ID/i,
      /Yahoo Client ID/i
    ];
    var labels = $all('label', pane);
    for (var i=0;i<labels.length;i++){
      var t = (labels[i].textContent||'').trim();
      for (var j=0;j<hints.length;j++){
        if (hints[j].test(t)) return true;
      }
    }
    return false;
  }

  var hidden = null, hiddenDot = null;
  function ensureHiddenFields(){
    if (!hidden){
      hidden = $('[name="email[accounts]"]', pane) || $('[name="email.accounts"]', pane);
      if (!hidden){
        hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'email[accounts]';
        pane.appendChild(hidden);
      } else { try{hidden.type='hidden';}catch(e){} }
      try{hidden.setAttribute('data-path', JSON.stringify(['email','accounts']));}catch(e){}
      hiddenDot = $('[name="email.accounts"]', pane);
      if (!hiddenDot){
        hiddenDot = document.createElement('input');
        hiddenDot.type = 'hidden';
        hiddenDot.name = 'email.accounts';
        hidden.parentNode.insertBefore(hiddenDot, hidden.nextSibling);
      }
      // Hide any visible "(managed by UI)" rows
      $all('.form-row', pane).forEach(function(row){
        var lbl = row.querySelector && row.querySelector('label');
        if (lbl && /\(managed by ui\)/i.test(lbl.textContent||'')){
          row.style.display='none';
          var f = row.querySelector('input,textarea,select');
          if (f) try{f.type='hidden';}catch(e){}
        }
      });
    }
  }

  var accounts = null;
  function parseList(s){ try{var j=JSON.parse(s||'[]'); return Array.isArray(j)?j:[];}catch(_){return [];} }
  function getBoot(){ var e=window.__CONFIG_DATA__&&window.__CONFIG_DATA__.email; return (e && typeof e.accounts==='string')? e.accounts : ''; }
  function providerFor(addr){
    var m=String(addr||'').split('@')[1]||''; m=m.toLowerCase();
    if (m==='gmail.com'||m==='googlemail.com') return 'google';
    if (/(^|\.)yahoo\./.test(m)) return 'yahoo';
    if (/(outlook|live|hotmail|msn)\.com$/.test(m)) return 'microsoft';
    return 'other';
  }
  function syncHidden(){
    if (hidden && (!hidden.dataset || !hidden.dataset.path)) { try{hidden.setAttribute('data-path', JSON.stringify(['email','accounts']));}catch(e){} }
    ensureHiddenFields();
    var json = JSON.stringify(accounts||[]);
    hidden.value=json; if (hiddenDot) hiddenDot.value=json;
  }
  function ensureContainer(){
    var c=document.getElementById(containerId);
    if(!c){
      c=document.createElement('div');
      c.id=containerId;
      c.style.marginTop='1rem';
      // theme-aware divider: prefer project CSS variables if present
      c.style.borderTop='1px solid var(--panel-border, var(--card-border, var(--divider, var(--muted, rgba(127,127,127,.25))))) )';
      c.style.paddingTop='.75rem';
      pane.appendChild(c);
    }
    return c;
  }

  function draw(){
    ensureHiddenFields();
    if (!accounts){
      accounts = parseList(hidden.value || getBoot());
      // default enabled:true if missing
      try{accounts = (accounts||[]).map(function(a){ if(a && typeof a==='object'){ if(!('enabled' in a)) a.enabled = true; } return a; }); }catch(_){ }
    }
    var c = ensureContainer();

    var visible = (window.__FORCE_EMAIL_UI__ === true) || paneHasEmailSignals();
    c.style.display = visible ? '' : 'none';
    if (!visible) return;

    var html=[];
    html.push(
      '<div class="row" style="align-items:center;gap:.5rem;margin-bottom:.5rem"><strong>Saved Email Accounts</strong></div>',
      '<div class="add-row" style="display:grid;grid-template-columns:1.6fr 1.2fr auto;gap:.5rem;align-items:center;margin:.5rem 0 .75rem 0">',
      '<input id="accNewEmail" type="email" placeholder="address@domain" />',
      '<input id="accNewPass" type="password" placeholder="Password / App password" />',
      '<button type="button" class="btn btn-sm" id="accAddBtn">Add</button>',
      '</div>'
    );
    if (!accounts.length){
      html.push('<em style="opacity:.65">No email accounts saved yet.</em>');
    } else {
      html.push('<div class="acc-list">');
      accounts.forEach(function(a,i){
        var addr = escapeHTML(a.address||'');
        var prov = a.provider || 'auto';
        var poll = parseInt(a.poll_seconds||300,10);
        var label = (function(x){return x==='google'?'Connect Google':(x==='microsoft'?'Connect Microsoft':(x==='yahoo'?'Connect Yahoo':'Connect'));})(prov==='auto'?providerFor(a.address):prov);
        html.push(
          '<div class="acc-row" data-idx="'+i+'" style="display:grid;grid-template-columns:auto 1.4fr .9fr .6fr auto auto;gap:.5rem;align-items:center;margin:.35rem 0">',
          '<label class="chkwrap" title="Include in new‑mail check"><input type="checkbox" class="chk" '+((a.enabled!==false)?'checked':'')+' /></label>',          '<input class="addr" type="email" value="'+addr+'" />',
          '<select class="prov">',
            '<option value="auto"'+(prov==='auto'?' selected':'')+'>Auto</option>',
            '<option value="google"'+(prov==='google'?' selected':'')+'>Google</option>',
            '<option value="microsoft"'+(prov==='microsoft'?' selected':'')+'>Microsoft</option>',
            '<option value="yahoo"'+(prov==='yahoo'?' selected':'')+'>Yahoo</option>',
            '<option value="other"'+(prov==='other'?' selected':'')+'>Other</option>',
          '</select>',
          '<input class="ps" type="number" min="60" max="86400" step="60" value="'+poll+'" title="Poll every (seconds)" />',
          '<button type="button" class="btn btn-sm oauth">'+label+'</button>',
          '<button type="button" class="btn danger">Delete</button>',
          '</div>'
        );
      });
      html.push('</div>');
    }
    c.innerHTML = html.join('');

    var addBtn = $('#accAddBtn', c);
    if (addBtn) on(addBtn,'click', function(){
      var e=$('#accNewEmail',c), p=$('#accNewPass',c);
      var addr=(e&&e.value||'').trim(), pass=(p&&p.value||'').trim();
      if(!addr) return;
      accounts.push({address:addr,password:pass,provider:'auto',poll_seconds:300});
      if(e) e.value=''; if(p) p.value='';
      syncHidden(); draw();
    });

    var saveBtn = document.getElementById('btnSave');
    if (saveBtn) on(saveBtn,'click', function(){ syncHidden(); });

    $all('.acc-row', c).forEach(function(row){
      var i = +row.getAttribute('data-idx');
      var addr = $('.addr',row), sel=$('.prov',row), ps=$('.ps',row), del=$('.del',row), oauth=$('.oauth',row); var chk=$('.chk',row);
      if (addr) on(addr,'change', function(){ accounts[i].address=addr.value.trim(); syncHidden(); });
      if (chk) on(chk,'change', function(){ accounts[i].enabled=!!chk.checked; syncHidden(); });
      if (sel)  on(sel, 'change', function(){ accounts[i].provider=sel.value; syncHidden(); });
      if (ps)   on(ps,  'change', function(){ var v=parseInt(ps.value,10)||300; accounts[i].poll_seconds=Math.max(60,Math.min(86400,v)); ps.value = accounts[i].poll_seconds; syncHidden(); });
      if (del)  on(del, 'click',  function(){ accounts.splice(i,1); syncHidden(); draw(); });
      if (oauth) on(oauth,'click', function(){
        var a=accounts[i], prov=(a.provider==='auto'?providerFor(a.address):a.provider), addr=(a.address||'').trim();
        if(!addr){ alert('Set email address first.'); return; }
        if(prov==='google'||prov==='microsoft'||prov==='yahoo'){
          var url='api/email_oauth_start.php?provider='+encodeURIComponent(prov)+'&address='+encodeURIComponent(addr);
          window.open(url,'_blank'); // new tab
        } else { alert('OAuth not available for "'+prov+'".'); }
      });
    });
  }

  // Draw once
  setTimeout(draw, 0);

  // Light watcher: redraw when config pane gets replaced (tab switch) — debounced
  var redrawTimer = null;
  var mo = new MutationObserver(function(muts){
    var major = muts.some(function(m){ return m.type==='childList' && m.target === pane; });
    if (!major) return;
    clearTimeout(redrawTimer);
    redrawTimer = setTimeout(draw, 50);
  });
  mo.observe(pane, {childList:true});

  // Also hook tab clicks
  if (tabs) on(tabs, 'click', function(){ setTimeout(draw, 0); });

})();

/* === END embedded email accounts UI === */
