(function(){
  'use strict';

  
  // ---------------- info modal helpers ----------------
  function ensureInfoModal(){
    var el = document.getElementById('infoModal');
    if (el) return el;
    el = document.createElement('div');
    el.className = 'modal'; el.id = 'infoModal'; el.setAttribute('hidden','');
    el.innerHTML = ''
      + '<div class="modal-dialog">'
      + '  <div class="modal-head">'
      + '    <div class="modal-title">Troubleshooting</div>'
      + '    <button class="modal-close" aria-label="Close">×</button>'
      + '  </div>'
      + '  <div class="modal-body"><div class="modal-content"></div></div>'
      + '</div>';
    document.body.appendChild(el);
    var close = el.querySelector('.modal-close');
    if (close) close.addEventListener('click', function(){ el.setAttribute('hidden',''); });
    el.addEventListener('click', function(ev){ if (ev.target === el) el.setAttribute('hidden',''); });
    document.addEventListener('keydown', function(ev){ if (ev.key === 'Escape') el.setAttribute('hidden',''); });
    return el;
  }

  function getInfoContent(name){
    var n = String(name||'');
    var blocks = [];
    function h(t){ return '<h3 style="margin:0 0 8px 0;">'+t+'</h3>'; }
    function p(t){ return '<p style="margin:6px 0">'+t+'</p>'; }
    function li(items){ return '<ul style="margin:6px 0 0 18px;">'+items.map(function(x){return '<li>'+x+'</li>';}).join('')+'</ul>'; }

    switch(n){
      case 'NTP sync':
        blocks.push(h('NTP sync: what to check'));
        blocks.push(li([
          'Run <code>timedatectl status</code> — look for <b>System clock synchronized: yes</b>.',
          'If using ntpd: <code>systemctl is-active ntp</code> and <code>ntpq -p</code> (look for a peer marked with <code>*</code>).',
          'If using chrony: <code>systemctl is-active chronyd</code> and <code>chronyc tracking</code> (not <em>unsynchronised</em>).',
          'Confirm outbound UDP/123 allowed to your NTP servers.',
          'Virtualized guests sometimes need host time sync disabled to avoid fights.'
        ]));
        blocks.push(p('If everything looks good but the scanner still warns, your system may use journald only; that’s fine.'));
        break;

      case 'firewall status':
      case 'firewall logs':
        blocks.push(h('Firewall: what to check'));
        blocks.push(li([
          'If you use nftables: <code>apt install nftables</code> then <code>sudo nft list ruleset</code>.',
          'If you use iptables: <code>sudo iptables -S</code> (or <code>-L -n</code>) to confirm chains/policies.',
          'If you rely on a panel (Hestia/cPanel): confirm its firewall page shows active rules.',
          'Packet logging is optional; lack of <code>LOG</code> rules is not an error.',
          'On journald-only systems, firewall logs won’t appear in legacy files; use <code>journalctl -k</code>.'
        ]));
        break;

      case 'TLS cert expiry':
        blocks.push(h('TLS cert expiry'));
        blocks.push(li([
          'Check renewal: <code>sudo certbot renew --dry-run</code>',
          'Ensure <code>/.well-known/acme-challenge/</code> is reachable (for HTTP-01).',
          'If using a proxy (nginx/haproxy), pass through ACME paths or use DNS-01.'
        ]));
        break;

      case 'Security updates':
        blocks.push(h('Security updates'));
        blocks.push(li([
          'Debian/Ubuntu: <code>sudo apt-get update && sudo apt-get upgrade</code>',
          'RHEL/Fedora: <code>sudo dnf upgrade --security</code>',
          'Automate: enable unattended upgrades or a scheduled job.'
        ]));
        break;

      case 'Inodes free':
        blocks.push(h('Inodes low'));
        blocks.push(li([
          'List inode usage: <code>df -i</code>',
          'Find tiny-file storms: <code>sudo find /var -xdev -type f -size -8k | wc -l</code>',
          'Rotate/vacuum logs: <code>sudo journalctl --vacuum-time=7d</code>'
        ]));
        break;

      case 'display_errors':
        blocks.push(h('PHP display_errors'));
        blocks.push(li([
          'Disable in production: set <code>display_errors = Off</code> in php.ini.',
          'Reload PHP-FPM/Apache to apply.',
          'Use logs for diagnostics instead of on-page errors.'
        ]));
        break;

      case 'expose_php':
        blocks.push(h('PHP expose_php'));
        blocks.push(li([
          'Hide PHP version: set <code>expose_php = Off</code> in php.ini.',
          'Restart PHP-FPM/Apache.'
        ]));
        break;

      case 'session.cookie_httponly':
      case 'session.cookie_secure':
        blocks.push(h('PHP session cookie hardening'));
        blocks.push(li([
          '<code>session.cookie_httponly = 1</code> to block JS access to the cookie.',
          '<code>session.cookie_secure = 1</code> if site is HTTPS-only.',
          'Restart PHP-FPM/Apache.'
        ]));
        break;

      case 'disable_functions':
        blocks.push(h('PHP disable_functions'));
        blocks.push(li([
          'Disable risky functions you don’t need (e.g., <code>exec</code>, <code>shell_exec</code>).',
          'Place overrides in a conf.d drop-in rather than editing the main php.ini.'
        ]));
        break;

      case 'open_basedir':
        blocks.push(h('PHP open_basedir'));
        blocks.push(li([
          'Restrict file access to webroot and required dirs using <code>open_basedir</code>.',
          'Be careful with temp directories and uploaded file paths.'
        ]));
        break;

      default:
        blocks.push(h(n + ' tips'));
        blocks.push(p('Start with service status, recent logs, and config. If you tell me the stack used here, I can add targeted steps.'));
    }

    blocks.push('<hr style="margin:12px 0;opacity:.2">');
    blocks.push(p('These are starting points, not one-click magic. Systems differ; adjust to your stack.'));
    return blocks.join('');
  }
// ---------------- utilities ----------------
  function $(s, ctx){ return (ctx||document).querySelector(s); }
  function el(tag, cls){ var e=document.createElement(tag); if(cls) e.className=cls; return e; }

  function apiBase(){
    try {
      var m = (document.body && document.body.dataset && document.body.dataset.apiMetrics) ? document.body.dataset.apiMetrics : '';
      if (m) return m.replace(/\/[^\/]*$/, '/') ;  // from /api/metrics_summary.php => /api/
      var base = (window.PROJECT_URL_BASE || '');
      return (base ? base : '.') + '/api/';
    } catch(e){ return '/api/'; }
  }

  function download(filename, text){
    try{
      var blob = new Blob([text], {type: 'text/plain;charset=utf-8'});
      var a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = filename;
      document.body.appendChild(a);
      a.click();
      setTimeout(function(){ URL.revokeObjectURL(a.href); a.remove(); }, 100);
    }catch(e){
      console && console.error && console.error(e);
    }
  }

  // ---------------- chip helpers ----------------
  function chipClassForStatus(st){
    if (!st) return 'warn';
    st = String(st).toLowerCase();
    if (st === 'ok' || st === 'up' || st === 'success' || st === 'good' || st === 'pass' || st === 'info') return 'ok';
    if (st === 'warn' || st === 'warning' || st === 'medium' || st === 'notice') return 'warn';
    return 'danger'; // fail/error/down/bad/danger
  }
  function labelForStatus(st){
    var cls = chipClassForStatus(st);
    if (cls === 'ok') return 'Good';
    if (cls === 'warn') return 'Medium';
    return 'Bad';
  }
  function esc(s){
    if (s==null) return '';
    return String(s)
      .replace(/&/g,'&amp;')
      .replace(/</g,'&lt;')
      .replace(/>/g,'&gt;')
      .replace(/\"/g,'&quot;')
      .replace(/'/g,'&#39;');
  }

  // ---------------- config access ----------------
  function cfg(path, defVal){
    try{
      var obj = (window.__CONFIG_DATA__ && window.__CONFIG_DATA__.server_tests) ? window.__CONFIG_DATA__.server_tests : {};
      var key = path.replace(/^server_tests\./,'').replace(/^history\./,'').replace(/^alerts\./,'');
      var v = obj[key];
      return (typeof v === 'undefined') ? defVal : v;
    }catch(e){ return defVal; }
  }

  // ---------------- api runner ----------------

function parseTargets(text){
  if (!text) return [];
  return String(text).split(',').map(function(s){ return s.trim(); }).filter(Boolean);
}
function ensureServicesAndRun(){
  // prefer config if present
  var cfgTargets = (window.__CONFIG_DATA__ && window.__CONFIG_DATA__.server_tests && Array.isArray(window.__CONFIG_DATA__.server_tests.service_targets)) ? window.__CONFIG_DATA__.server_tests.service_targets : null;
  if (cfgTargets && cfgTargets.length){
    return run('services'); // use config
  }
  // fallback to localStorage
  var ls = localStorage.getItem('service_targets') || '';
  var arr = parseTargets(ls);
  if (!arr.length){
    var t = prompt('Enter services as host:port|label, comma-separated', '127.0.0.1:80|Nginx, 127.0.0.1:3306|MySQL');
    if (!t) { window.toast && window.toast.info && window.toast.info('No services provided'); return Promise.resolve(); }
    arr = parseTargets(t);
    localStorage.setItem('service_targets', arr.join(', '));
  }
  return run('services', { targets: arr });
}

  var lastResults = null; // remember last API block for export
  function run(kind, extra){
    var url = apiBase() + 'server_tests.php';
    var payload = Object.assign({ action: kind || 'quick' }, (extra||{}));
    return fetch(url, {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify(payload)
    }).then(function(r){
      if (!r.ok) {
        if (r.status === 401) throw new Error('Auth required');
        if (r.status === 429) throw new Error('Please wait a moment (rate limited)');
        throw new Error('HTTP '+r.status);
      }
      return r.json();
    }).then(function(res){
      if (!res || res.ok !== true) { throw new Error((res && res.error) || 'Unknown error'); }
      renderResults(res);
      if (typeof res.score === 'number') setReportCard(res.score);
      return res;
    }).catch(function(e){
      console && console.error && console.error(e);
      if (window.toast && window.toast.error) window.toast.error(String(e && e.message ? e.message : e));
      throw e;
    });
  }

  // ---------------- tooltip hints ----------------
  function tooltipFor(row){
    var n = (row && row.name) ? String(row.name) : '';
    if (n === 'TLS cert expiry'){
      var w = cfg('server_tests.tls_warn_days', 21), f = cfg('server_tests.tls_fail_days', 7);
      return 'Warn < '+ w +' days; Fail < '+ f +' days';
    }
    if (n === 'Inodes free'){
      var ok = cfg('server_tests.inode_ok_free_pct', 15), warn = cfg('server_tests.inode_warn_free_pct', 7);
      return 'OK ≥ '+ ok +'%, Warn ≥ '+ warn +'% free';
    }
    if (n === 'Security updates'){
      var wr = cfg('server_tests.updates_warn_count', 1), fl = cfg('server_tests.updates_fail_count', 10);
      return 'Warn ≥ '+ wr +', Fail ≥ '+ fl;
    }
    return '';
  }

  // ---------------- results renderer ----------------
  function renderResults(block){
    try{
      lastResults = block || null;
      var out = $('#testsOutput'); if (!out) return;
      out.innerHTML = '';
      if (!block || !block.results || !block.results.length){
        out.textContent = 'No results.';
        return;
      }
      var table = el('table', 'table');
      var thead = el('thead');
      thead.innerHTML = '<tr>'
        + '<th style="white-space:nowrap;min-width:220px;width:auto;">Name</th>'
        + '<th style="white-space:nowrap;width:1%;">Status</th>'
        + '<th>Detail</th>'
        + '</tr>';
      table.appendChild(thead);
      var tb = el('tbody');
      block.results.forEach(function(r){
        var tr = el('tr');
        var st = r.status || 'warn';
        var cls = chipClassForStatus(st);
        var tip = tooltipFor(r);
        var chip = '<span class="chip ' + cls + (cls==='ok'?' success up':(cls==='warn'?' warning':' danger error down')) + '"'
                 + ' title="status: ' + esc(st) + (tip ? ' — ' + esc(tip) : '') + '">'
                 + '<span class="dot"></span><span class="label">' + labelForStatus(st) + '</span></span>';
        var fixBtn = (cls !== 'ok')
          ? '<button type="button" class="btn secondary copy-fix" data-name="'+ esc(r.name) +'"'
            + ' style="font-size:12px;padding:2px 6px;line-height:1;display:inline-flex;align-items:center;">Info</button>'
          : '';
        tr.innerHTML =
          '<td style="white-space:nowrap;min-width:220px;">'+ esc(r.name) +'</td>'+
          '<td>'+ chip +'</td>'+
          '<td class="detail" style="white-space:normal;width:100%;">'+
            '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">'+
              '<code style="white-space:normal;word-break:break-word;overflow-wrap:anywhere;">'+ esc(r.details||'') +'</code>'+
              (fixBtn || '') +
            '</div>'+
          '</td>';
        tb.appendChild(tr);
      });
      table.appendChild(tb);
      out.appendChild(table);
    }catch(e){
      console && console.error && console.error(e);
      if (window.toast && window.toast.error) window.toast.error('Render failed');
    }
  }

  function setReportCard(score){
    var badge = $('#reportCard'); if (!badge) return;
    var meta = (function(score){
      if (score >= 0.90) return {grade:'A', color:'ok'};
      if (score >= 0.80) return {grade:'B', color:'ok'};
      if (score >= 0.70) return {grade:'C', color:'warn'};
      if (score >= 0.60) return {grade:'D', color:'warn'};
      return {grade:'F', color:'danger'};
    })(score||0);
    badge.classList.remove('ok','warn','danger');
    badge.classList.add(meta.color);
    var label = badge.querySelector('.label');
    if (label) label.textContent = meta.grade;
    badge.title = 'Overall grade: ' + meta.grade + ' (' + Math.round((score||0)*100) + '%)';
  }

  // ---------------- export helpers ----------------
  function toCSV(rows){
    var escf = function(s){ s = String(s==null?'':s); if (/[",\n]/.test(s)) return '"'+s.replace(/"/g,'""')+'"'; return s; };
    var head = ['name','status','detail'];
    var out = [head.join(',')];
    (rows||[]).forEach(function(r){
      out.push([escf(r.name), escf(r.status), escf(r.details||'')].join(','));
    });
    return out.join('\n');
  }

  // ---------------- history ----------------
  function historyControls(){
    return '<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;flex-wrap:wrap;">'
      + '<div class="muted">Range:</div>'
      + '<div class="btn-group">'
      +   '<button class="btn secondary h-range" data-days="7">7d</button>'
      +   '<button class="btn secondary h-range" data-days="30">30d</button>'
      +   '<button class="btn secondary h-range" data-days="90">90d</button>'
      +   '<button class="btn secondary h-range" data-days="-1">All</button>'
      + '</div>'
      + '<div class="muted" style="margin-left:8px;">Export:</div>'
      + '<button class="btn secondary" id="hExportJson">JSON</button>'
      + '<button class="btn secondary" id="hExportCsv">CSV</button>'
      + '</div>';
  }
  function fetchHistory(days){
    var d = (typeof days==='number' ? days : 30);
    return fetch(apiBase()+'server_tests_history.php', {
      method: 'POST',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({since_days: d})
    }).then(function(r){
      if (!r.ok) throw new Error('HTTP '+r.status);
      return r.json();
    });
  }
  function drawLineChart(canvas, series, key){
    if (!canvas || !canvas.getContext) return;
    var ctx = canvas.getContext('2d');
    var W = canvas.width = canvas.clientWidth || 340;
    var H = canvas.height = Math.max(160, canvas.clientHeight || 160);
    ctx.clearRect(0,0,W,H);
    if (!series || !series.length) { ctx.fillStyle='#888'; ctx.fillText('No data', 8, 16); return; }
    var xs = series.map(function(p){ return new Date(p.t).getTime(); });
    var ys = series.map(function(p){ return Number(p[key]||0); });
    var minX = Math.min.apply(null, xs), maxX = Math.max.apply(null, xs);
    var minY = Math.min.apply(null, ys), maxY = Math.max.apply(null, ys);
    if (minY===maxY){ maxY += 1; minY -= 1; }
    var px = function(x){ return (W-40) * (x-minX)/(maxX-minX+1e-9) + 30; };
    var py = function(y){ return (H-30) * (1 - (y-minY)/(maxY-minY+1e-9)) + 10; };
    ctx.lineWidth = 2;
    ctx.beginPath();
    for (var i=0;i<xs.length;i++){
      var x = px(xs[i]), y = py(ys[i]);
      if (i===0) ctx.moveTo(x,y); else ctx.lineTo(x,y);
    }
    ctx.strokeStyle = '#4aa3ff';
    ctx.stroke();
    ctx.strokeStyle = 'rgba(255,255,255,0.2)';
    ctx.lineWidth = 1;
    ctx.beginPath(); ctx.moveTo(28, 10); ctx.lineTo(28, H-20); ctx.lineTo(W-10, H-20); ctx.stroke();
    var last = ys[ys.length-1];
    ctx.fillStyle = '#ccc';
    ctx.fillText(String(last), W-60, 14);
  }
  function showHistory(){
    var out = $('#testsOutput'); if (!out) return;
    out.innerHTML = '<div class="card" style="padding:8px;">' + historyControls()
      + '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px;">'
      +   '<div><div class="muted" style="margin-bottom:4px;">Overall score</div><canvas id="hScore" style="width:100%;height:160px;"></canvas></div>'
      +   '<div><div class="muted" style="margin-bottom:4px;">Disk free (%)</div><canvas id="hDisk" style="width:100%;height:160px;"></canvas></div>'
      +   '<div><div class="muted" style="margin-bottom:4px;">Security updates</div><canvas id="hUpd" style="width:100%;height:160px;"></canvas></div>'
      +   '<div><div class="muted" style="margin-bottom:4px;">Load (1m)</div><canvas id="hLoad" style="width:100%;height:160px;"></canvas></div>'
      + '</div>'
      + '<div style="margin-top:12px;">Recent runs</div>'
      + '<table class="table"><thead><tr><th>When</th><th>Score</th><th>Issues</th></tr></thead><tbody id="hRecent"></tbody></table>'
      + '</div>';
    // default 30d
    var DAYS = 30;
    fetchHistory(DAYS).then(function(res){
      var pts = res.points || [];
      drawLineChart(document.getElementById('hScore'), pts, 'score');
      drawLineChart(document.getElementById('hDisk'), pts, 'disk_free_pct');
      drawLineChart(document.getElementById('hUpd'), pts, 'security_updates');
      drawLineChart(document.getElementById('hLoad'), pts, 'load_1m');
      var tbody = document.getElementById('hRecent');
      var recent = res.recent || [];
      tbody.innerHTML = recent.map(function(r){
        var bad = (r.items||[]).filter(function(x){ return (x.status||'warn')!=='ok'; });
        return '<tr><td>'+ new Date(r.t).toLocaleString() +'</td><td>'+ Math.round((r.score||0)*100) +'%</td><td>'+ bad.length +'</td></tr>';
      }).join('');

      // export and range
      var hj=document.getElementById('hExportJson'); if (hj) hj.addEventListener('click', function(){ download('history.json', JSON.stringify({points:pts}, null, 2)); });
      var hc=document.getElementById('hExportCsv');  if (hc) hc.addEventListener('click', function(){ 
        var head = ['t','score','disk_free_pct','security_updates','load_1m','tls_days'];
        var escf = function(s){ s=(s==null?'':String(s)); if (/[",\n]/.test(s)) return '"'+s.replace(/"/g,'""')+'"'; return s; };
        var lines=[head.join(',')];
        pts.forEach(function(p){ lines.push([p.t,p.score,p.disk_free_pct,p.security_updates,p.load_1m,p.tls_days].map(escf).join(',')); });
        download('history.csv', lines.join('\n'));
      });
      Array.prototype.slice.call(document.querySelectorAll('.h-range')).forEach(function(b){
        b.addEventListener('click', function(){
          var dd = parseInt(b.getAttribute('data-days'),10);
          if (isNaN(dd)) dd = 30;
          fetchHistory(dd).then(function(res2){
            var p2 = res2.points || [];
            drawLineChart(document.getElementById('hScore'), p2, 'score');
            drawLineChart(document.getElementById('hDisk'), p2, 'disk_free_pct');
            drawLineChart(document.getElementById('hUpd'), p2, 'security_updates');
            drawLineChart(document.getElementById('hLoad'), p2, 'load_1m');
            var tbody2 = document.getElementById('hRecent');
            var recent2 = res2.recent || [];
            tbody2.innerHTML = recent2.map(function(r){
              var bad = (r.items||[]).filter(function(x){ return (x.status||'warn')!=='ok'; });
              return '<tr><td>'+ new Date(r.t).toLocaleString() +'</td><td>'+ Math.round((r.score||0)*100) +'%</td><td>'+ bad.length +'</td></tr>';
            }).join('');
            pts = p2; // update for export
          }).catch(function(e){ window.toast && window.toast.error(String(e.message||e)); });
        });
      });
    }).catch(function(e){
      window.toast && window.toast.error(String(e.message||e));
    });
  }

  // ---------------- init & events ----------------
  function init(){
    var q=$('#btnQuick'), s=$('#btnSecurity'), f=$('#btnFS'), v=$('#btnSvc'), p=$('#btnPerf'), h=$('#btnHistory');
    if (q) q.addEventListener('click', function(){ run('quick').catch(function(){}); });
    if (s) q && s.addEventListener('click', function(){ run('security').catch(function(){}); });
    if (f) f.addEventListener('click', function(){ run('filesystem').catch(function(){}); });
    if (v) v.addEventListener('click', function(){ ensureServicesAndRun().catch(function(){}); });
    if (p) p.addEventListener('click', function(){ run('performance').catch(function(){}); });
    if (h) h.addEventListener('click', function(){ showHistory(); });

    var bj=$('#btnExportJson'), bc=$('#btnExportCsv');
    if (bj) bj.addEventListener('click', function(){
      if (!lastResults || !lastResults.results) { window.toast && window.toast.info && window.toast.info('Run a test first'); return; }
      download('server_tests.json', JSON.stringify(lastResults, null, 2));
    });
    if (bc) bc.addEventListener('click', function(){
      if (!lastResults || !lastResults.results) { window.toast && window.toast.info && window.toast.info('Run a test first'); return; }
      download('server_tests.csv', toCSV(lastResults.results));
    });

    // info modal (replaces copy-fix)
    
    document.addEventListener('click', function(ev){
      var btn = ev.target.closest && ev.target.closest('.copy-fix');
      if (!btn) return;
      var rowName = btn.getAttribute('data-name') || '';
      var modal = ensureInfoModal();
      modal.querySelector('.modal-title').textContent = rowName + ' — Info';
      modal.querySelector('.modal-content').innerHTML = getInfoContent(rowName);
      modal.removeAttribute('hidden');
    });
    // auto-quick on load
    run('quick').catch(function(){});
  }

  function getFixCommand(row){
    var n = String(row.name||'');
    switch(n){
      case 'display_errors':
        return "# Turn off display_errors in php.ini\nsudo sed -i 's/^display_errors\\s*=.*/display_errors = Off/' $(php --ini | awk -F': ' '/Loaded Configuration/{print $2}')\n# then reload FPM/Apache as appropriate";
      case 'expose_php':
        return "# Disable expose_php in php.ini\nsudo sed -i 's/^expose_php\\s*=.*/expose_php = Off/' $(php --ini | awk -F': ' '/Loaded Configuration/{print $2}')";
      case 'session.cookie_httponly':
        return "# Enforce HttpOnly cookie\nsudo sed -i 's/^;\\?session.cookie_httponly.*/session.cookie_httponly = On/' $(php --ini | awk -F': ' '/Loaded Configuration/{print $2}')";
      case 'session.cookie_secure':
        return "# Enforce Secure cookie (requires HTTPS)\nsudo sed -i 's/^;\\?session.cookie_secure.*/session.cookie_secure = On/' $(php --ini | awk -F': ' '/Loaded Configuration/{print $2}')";
      case 'disable_functions':
        return "# Example hardening (adjust as needed)\necho 'disable_functions = exec,system,passthru,proc_open,popen,shell_exec' | sudo tee /etc/php/conf.d/99-disable-functions.ini";
      case 'open_basedir':
        return "# Example restrictive open_basedir\necho 'open_basedir = /var/www:/home:/tmp' | sudo tee /etc/php/conf.d/99-open-basedir.ini";
      case 'TLS cert expiry':
        return "# Renew Let's Encrypt cert\nsudo certbot renew --dry-run";
      case 'Security updates':
        return "# Debian/Ubuntu\nsudo apt-get update && sudo apt-get upgrade --with-new-pkgs\n# RHEL/Fedora family\nsudo dnf upgrade --security";
      case 'Inodes free':
        return "# Inspect inode usage\nsudo df -i\n# Consider pruning cache/logs\nsudo journalctl --vacuum-time=7d";
      default:
        return "# Investigate configuration for: " + n;
    }
  }

  if (document.readyState==='complete' || document.readyState==='interactive') init();
  else document.addEventListener('DOMContentLoaded', init, {once:true});
})();
