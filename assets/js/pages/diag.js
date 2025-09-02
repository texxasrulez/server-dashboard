
(function(){
  'use strict';

  function $(s, ctx){ return (ctx||document).querySelector(s); }
  function on(el, ev, fn){ el && el.addEventListener(ev, fn, false); }
  function create(tag, cls){ var el=document.createElement(tag); if(cls) el.className=cls; return el; }

  function ensureModal(){
    var m = document.getElementById('globalModal');
    if (m) return m;
    m = create('div','modal'); m.id='globalModal'; m.hidden=true;
    m.innerHTML = [
      '<div class="modal-dialog" role="dialog" aria-modal="true" aria-label="Dialog">',
      '  <button class="modal-close" aria-label="Close">×</button>',
      '  <div class="modal-head"><div class="modal-title">Loading…</div>',
      '    <div class="modal-tools" id="modalTools"></div></div>',
      '  <div class="modal-body"><div class="modal-content"></div></div>',
      '</div>'
    ].join('');
    document.body.appendChild(m);
    on(m.querySelector('.modal-close'), 'click', close);
    on(m, 'click', function(e){ if(e.target===m) close(); });
    on(document, 'keydown', function(e){ if(e.key==='Escape') close(); });
    return m;
  }
  function open(){ ensureModal().hidden=false; }
  function close(){ var m=ensureModal(); m.hidden=true; var c=$('.modal-content',m); if(c) c.innerHTML=''; var t=$('.modal-title',m); if(t) t.textContent=''; $('#modalTools',m).innerHTML=''; }

  function setTitle(t){ var m=ensureModal(); var el=$('.modal-title',m); if(el) el.textContent=t||''; }
  function setTools(nodes){ var m=ensureModal(); var wrap=$('#modalTools',m); wrap.innerHTML=''; if(nodes && nodes.length){ nodes.forEach(function(n){wrap.appendChild(n);}); } }
  function setContent(node){ var m=ensureModal(); var c=$('.modal-content',m); c.innerHTML=''; c.appendChild(node); }

  function stripChrome(html){
    var tpl=document.createElement('template'); tpl.innerHTML=html;
    var doc=tpl.content;
    var main = doc.querySelector('main.container, main, .content, .card, body') || doc;
    var out = create('div','embedded');
    if (main){
      var kill = doc.querySelectorAll('header, nav, .app-header, footer, .usermenu, .theme-picker');
      kill.forEach(function(el){ el.parentNode && el.parentNode.removeChild(el); });
      out.appendChild(main.cloneNode(true));
    } else {
      out.innerHTML = html;
    }
    return out;
  }

  // --- Inline audit helpers ---
  function ensureAuditControls(ctx){
    if (!ctx) ctx = document;
    if (ctx.querySelector('#aa-scan')) return;
    var tools = ctx.querySelector('.audit-toolbar') || create('div','audit-toolbar');
    if (!tools.parentNode) {
      var head = ctx.querySelector('.modal-tools') || ctx;
      head.appendChild(tools);
    }
    var b1=create('button','btn'); b1.id='aa-scan'; b1.textContent='Scan now';
    var b2=create('button','btn secondary'); b2.id='aa-open'; b2.textContent='Open JSON';
    var b3=create('button','btn secondary'); b3.id='aa-copy'; b3.textContent='Copy report';
    tools.innerHTML=''; tools.appendChild(b1); tools.appendChild(b2); tools.appendChild(b3);

    tools.addEventListener('click', function(e){
      var t = e.target && e.target.closest('#aa-scan,#aa-open,#aa-copy');
      if (!t) return;
      e.preventDefault();
      try {
        if (t.id === 'aa-scan') {
          window.__lastAuditReport = {
            when: new Date().toISOString(),
            scripts: Array.from(document.scripts).map(s => s.src || '(inline)'),
            styles:  Array.from(document.querySelectorAll('link[rel=stylesheet]')).map(l => l.href),
            counts:  { scripts: document.scripts.length, styles: document.querySelectorAll('link[rel=stylesheet]').length }
          };
          if (typeof toast === 'function') window.toast.confirmation('Assets scan complete');
          var pre = ctx.querySelector('pre, .audit-body pre');
          if (pre) pre.textContent = JSON.stringify(window.__lastAuditReport, null, 2);
        } else {
          var payload = JSON.stringify(window.__lastAuditReport || {}, null, 2);
          if (t.id === 'aa-copy') {
            (async()=>{
              try { await (navigator.clipboard && navigator.clipboard.writeText(payload)); }
              catch(e){ var ta=document.createElement('textarea'); ta.value=payload; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); document.body.removeChild(ta); }
              if (typeof toast === 'function') window.toast.confirmation('Report copied');
            })();
          } else if (t.id === 'aa-open') {
            var blob = new Blob([payload], {type:'application/json'});
            var url = URL.createObjectURL(blob);
            window.open(url,'_blank','noopener'); setTimeout(()=>URL.revokeObjectURL(url), 15000);
          }
        }
      } catch(err){ console.error('assets audit action failed', err); if (typeof toast === 'function') window.toast.error('Action failed'); }
    }, false);
  }

  async function openMetrics(url){
    setTitle('metrics_summary.php');
    open();
    try{
      var r = await fetch(url, {credentials:'same-origin', headers:{'Accept':'application/json'}});
      var txt = await r.text();
      var data;
      try{ data = JSON.parse(txt); }
      catch(_){ data = { error: 'Non‑JSON response', body: txt.slice(0,400) }; }
      var wrap=create('div','json-wrap'); var pre=create('pre','json'); pre.textContent=JSON.stringify(data,null,2); wrap.appendChild(pre);
      setContent(wrap);
    }catch(e){
      var err=create('div'); err.textContent='Load failed: '+(e&&e.message||e); setContent(err);
    }
  }

  async function openAudit(url){
    setTitle('assets_audit.php');
    open();
    try{
      var r = await fetch(url, {credentials:'same-origin'});
      var txt = await r.text();
      var node = stripChrome(txt);
      setContent(node);
      ensureAuditControls(node);
    }catch(e){
      var err=create('div'); err.textContent='Load failed: '+(e&&e.message||e); setContent(err);
    }
  }

  document.addEventListener('click', function(ev){
    var a = ev.target.closest && ev.target.closest('a[data-modal]');
    if(!a) return;
    ev.preventDefault();
    var href = a.getAttribute('href') || a.href || '';
    if (/metrics_summary\.php/i.test(href)) return openMetrics(href);
    if (/assets_audit\.php/i.test(href))   return openAudit(href);
    setTitle(a.textContent.trim()||'Details'); open();
    fetch(href, {credentials:'same-origin'}).then(function(r){return r.text();}).then(function(html){
      var node = stripChrome(html);
      setContent(node);
    }).catch(function(e){ var d=create('div'); d.textContent='Load failed: '+(e&&e.message||e); setContent(d); });
  });

})();
