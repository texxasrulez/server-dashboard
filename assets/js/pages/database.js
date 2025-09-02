(function(){
  'use strict';

  function $(s, ctx){ return (ctx||document).querySelector(s); }
  function $all(s, ctx){ return Array.prototype.slice.call((ctx||document).querySelectorAll(s)); }

  function fmtBytes(n){
    if (n == null) return '';
    var units = ['B','KB','MB','GB','TB','PB'];
    var i=0, x = Number(n);
    while (x >= 1024 && i < units.length-1){ x/=1024; i++; }
    return (x>=10? x.toFixed(0): x.toFixed(1)) + ' ' + units[i];
  }
  function fmtInt(n){ return (n==null)? '' : n.toLocaleString(); }
  function fmtUptime(sec){
    if (!sec) return '';
    var d = Math.floor(sec/86400); sec%=86400;
    var h = Math.floor(sec/3600); sec%=3600;
    var m = Math.floor(sec/60); sec%=60;
    var out = [];
    if (d) out.push(d+'d'); if (h) out.push(h+'h'); if (m) out.push(m+'m'); if (sec) out.push(sec+'s');
    return out.join(' ');
  }

  function scanUrl(){
    var root = $('#dbRoot');
    var u = root ? root.getAttribute('data-scan') : null;
    return u || 'database.php?action=scan';
  }

  function load(){
    fetch(scanUrl(), {cache:'no-store'})
      .then(function(r){ return r.json(); })
      .then(function(res){
        if (!res.ok) throw new Error(res.error || 'Scan failed');
        renderServer(res.server);
        renderTable(res.databases||[]);
        adjustScroll();
        if (window.toast) window.toast.success('Database scan complete.');
      })
      .catch(function(err){
        if (window.toast) window.toast.error(err.message);
        renderServer(null);
        var tb = $('#dbTable tbody'); tb.innerHTML = '<tr><td colspan="9" class="muted">Error: '+ (err && err.message ? err.message : 'unknown') +'</td></tr>';
        adjustScroll();
      });
  }

  function adjustScroll(){
    var wrap = $('#dbTableWrap'); if (!wrap) return;
    var footer = document.querySelector('footer.footer');
    var footerH = footer ? footer.getBoundingClientRect().height : 0;
    var rect = wrap.getBoundingClientRect();
    var available = window.innerHeight - rect.top - footerH - 12;
    if (available > 120) {
      wrap.style.maxHeight = available + 'px';
      wrap.style.height = available + 'px';
    }
  }

  function renderServer(srv){
    var el = $('#dbServer');
    if (!el) return;
    if (!srv){ el.textContent = ''; return; }
    var txt = 'Server: ' + srv.host + ':' + srv.port + '  •  Version: ' + srv.version;
    if (srv.uptime) txt += '  •  Uptime: ' + fmtUptime(srv.uptime);
    if (srv.threads_connected!=null) txt += '  •  Threads: ' + srv.threads_connected + ' (' + (srv.threads_running||0) + ' running)';
    el.textContent = txt;
  }

  function renderTable(rows){
    var tb = $('#dbTable tbody');
    if (!tb) return;
    if (!rows.length){
      tb.innerHTML = '<tr><td colspan="9" class="muted">No databases found.</td></tr>';
      return;
    }
    var html = rows.map(function(r){
      var sys = r.system ? '<span class="badge">system</span>' : '';
      var last = r.last_update ? r.last_update.replace('T',' ').replace('.000Z','') : '';
      return '<tr>'
        + '<td>'+ escapeHtml(r.name) +'</td>'
        + '<td>'+ (r.collation||'') +'</td>'
        + '<td style="text-align:right">'+ fmtInt(r.tables) +'</td>'
        + '<td style="text-align:right">'+ fmtInt(r.rows) +'</td>'
        + '<td style="text-align:right">'+ fmtBytes(r.data_bytes) +'</td>'
        + '<td style="text-align:right">'+ fmtBytes(r.index_bytes) +'</td>'
        + '<td style="text-align:right">'+ fmtBytes(r.total_bytes) +'</td>'
        + '<td>'+ (last||'') +'</td>'
        + '<td>'+ sys +'</td>'
        + '</tr>';
    }).join('');
    tb.innerHTML = html;
  }

  function escapeHtml(s){
    if (s == null) return '';
    return String(s).replace(/[&<>"']/g, function(c){
      return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]);
    });
  }

  document.addEventListener('DOMContentLoaded', function(){
    var btn = $('#btnRefresh');
    if (btn) btn.addEventListener('click', load);
    window.addEventListener('resize', adjustScroll);
    load();
  });
})();