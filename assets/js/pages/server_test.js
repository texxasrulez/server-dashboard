(function(){
  'use strict';
  function $(s, ctx){ return (ctx||document).querySelector(s); }
  function $all(s, ctx){ return Array.prototype.slice.call((ctx||document).querySelectorAll(s)); }
  function on(el, ev, fn, opts){ if(el) el.addEventListener(ev, fn, opts||false); }
  function esc(s){ return String(s||'').replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'}[c])); }

  var root = $('#servertest-root');
  if (!root) return;

  var cards = {
    php:      $('#card-php .body'),
    ini:      $('#card-ini .body'),
    ext:      $('#card-ext .body'),
    opcache:  $('#card-opcache .body'),
    fs:       $('#card-fs .body'),
    net:      $('#card-net .body'),
    env:      $('#card-env .body'),
  };

  function row(k,v){ return `<div class="row"><span class="k">${esc(k)}</span><span class="v">${esc(v)}</span></div>`; }
  function badge(ok){ return `<span class="chip ${ok?'ok':'bad'}">${ok?'OK':'FAIL'}</span>`; }

  function renderPHP(d){
    cards.php.innerHTML = row('Version', d.php.version) + row('SAPI', d.php.sapi);
  }
  function renderINI(d){
    var html = '';
    Object.keys(d.ini).forEach(k=> html += row(k, d.ini[k]));
    cards.ini.innerHTML = html;
  }
  function renderEXT(d){
    var html = '';
    Object.keys(d.extensions).forEach(function(k){
      html += row(k, badge(!!d.extensions[k]));
    });
    cards.ext.innerHTML = html;
  }
  function renderOPC(d){
    if (!d.opcache){ cards.opcache.innerHTML = '<div class="muted">OPcache not available</div>'; return; }
    var m = d.opcache.memory||{};
    var s = d.opcache.stats||{};
    var mem = m.used_memory!=null && m.free_memory!=null ? `${(m.used_memory/1048576).toFixed(1)} MB used / ${(m.free_memory/1048576).toFixed(1)} MB free` : 'n/a';
    var rate = s.opcache_hit_rate!=null ? s.opcache_hit_rate.toFixed(2)+'%' : 'n/a';
    cards.opcache.innerHTML =
      row('Enabled', badge(!!d.opcache.enabled)) +
      row('Memory', mem) +
      row('Cached Scripts', s.num_cached_scripts!=null ? s.num_cached_scripts : 'n/a') +
      row('Hit rate', rate);
  }
  function renderFS(d){
    var html = '';
    var p = d.filesystem.paths||{};
    Object.keys(p).forEach(function(name){
      var x = p[name];
      html += `<div class="row"><span class="k">${esc(name)}</span><span class="v">
         ${x.exists ? '<span class="chip ok">exists</span>' : '<span class="chip bad">missing</span>'}
         ${x.is_dir ? '<span class="chip ok">dir</span>' : '<span class="chip bad">file</span>'}
         ${x.is_readable ? '<span class="chip ok">readable</span>' : '<span class="chip bad">!read</span>'}
         ${x.is_writable ? '<span class="chip ok">writable</span>' : '<span class="chip bad">!write</span>'}
      </span></div>`;
    });
    var disk = d.filesystem.disk||{};
    html += row('Disk free', disk.free!=null ? (disk.free/1073741824).toFixed(2)+' GB' : 'n/a');
    html += row('Disk total', disk.total!=null ? (disk.total/1073741824).toFixed(2)+' GB' : 'n/a');
    cards.fs.innerHTML = html;
  }
  function renderNET(d){
    var html = '';
    html += row('DNS resolve', badge(!!d.network.dns_ok));
    html += row('HTTPS (get_headers)', badge(!!d.network.https_ok));
    html += row('cURL available', badge(!!d.network.curl_ok));
    cards.net.innerHTML = html;
  }
  function renderENV(d){
    var html = '';
    Object.keys(d.env).forEach(k=> html += row(k, d.env[k]));
    cards.env.innerHTML = html;
  }

  function renderAll(d){
    renderPHP(d); renderINI(d); renderEXT(d); renderOPC(d); renderFS(d); renderNET(d); renderENV(d);
  }

  function run(){
    var url = 'api/server_test.php?ts=' + Date.now();
    toast && toast.info && toast.info('Running diagnostics…');
    fetch(url, {credentials:'same-origin'})
      .then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); })
      .then(function(data){
        renderAll(data);
        window.__SERVER_TEST_LAST = data;
        toast && toast.success && toast.success('Diagnostics complete');
      })
      .catch(function(err){
        console.error(err);
        toast && toast.error && toast.error('Diagnostics failed: '+err.message);
      });
  }

  // Export / copy
  function copyJSON(){
    try{
      var txt = JSON.stringify(window.__SERVER_TEST_LAST || {}, null, 2);
      navigator.clipboard.writeText(txt).then(function(){ toast && toast.success && toast.success('JSON copied'); });
    }catch(e){ toast && toast.error && toast.error('Copy failed'); }
  }
  function exportJSON(){
    var blob = new Blob([JSON.stringify(window.__SERVER_TEST_LAST || {}, null, 2)], {type:'application/json'});
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'server_diagnostics.json';
    a.click();
    URL.revokeObjectURL(a.href);
  }

  on($('#stRunAll'), 'click', run);
  on($('#stCopy'), 'click', copyJSON);
  on($('#stExport'), 'click', exportJSON);

  // auto run on first load
  run();
})();