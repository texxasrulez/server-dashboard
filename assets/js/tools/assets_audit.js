
// Assets Audit tooling (works when injected in modal)
(function(){
  'use strict';
  function $(s,ctx){return (ctx||document).querySelector(s);}
  function create(tag, cls){ var el=document.createElement(tag); if(cls) el.className=cls; return el; }

  // Build toolbar if not present
  function ensureToolbar(){
    if (document.getElementById('aa-scan')) return;
    var tools = document.querySelector('.audit-toolbar') || create('div','audit-toolbar');
    if (!tools.parentNode) {
      var head = document.querySelector('.modal-tools,.tools,.card-tools') || document.body;
      head.appendChild(tools);
    }
    tools.innerHTML = '';
    var b1=create('button','btn'); b1.id='aa-scan'; b1.textContent='Scan now';
    var b2=create('button','btn secondary'); b2.id='aa-open'; b2.textContent='Open JSON';
    var b3=create('button','btn secondary'); b3.id='aa-copy'; b3.textContent='Copy report';
    tools.append(b1,b2,b3);
  }

  // Simple scanner
  function scan(){
    window.__lastAuditReport = {
      when: new Date().toISOString(),
      scripts: Array.from(document.scripts).map(s => s.src || '(inline)'),
      styles:  Array.from(document.querySelectorAll('link[rel=stylesheet]')).map(l => l.href),
      counts:  { scripts: document.scripts.length, styles: document.querySelectorAll('link[rel=stylesheet]').length }
    };
    if (typeof toast === 'function') toast('success', 'Assets scan complete');
    console.log('[assets_audit] report:', window.__lastAuditReport);
    return window.__lastAuditReport;
  }
  window.scan = scan;

  document.addEventListener('click', function(e){
    var t = e.target && e.target.closest && e.target.closest('#aa-scan,#aa-open,#aa-copy');
    if (!t) return;
    e.preventDefault();
    ensureToolbar(); // in case buttons are missing
    try {
      if (t.id === 'aa-scan') {
        scan();
      } else {
        var payload = JSON.stringify(window.__lastAuditReport || scan(), null, 2);
        if (t.id === 'aa-copy') {
          (async () => {
            try { await (navigator.clipboard && navigator.clipboard.writeText(payload)); }
            catch(e){
              var ta=document.createElement('textarea'); ta.value=payload; document.body.appendChild(ta); ta.select();
              document.execCommand('copy'); document.body.removeChild(ta);
            }
            if (typeof toast === 'function') toast('success','Report copied');
          })();
        } else if (t.id === 'aa-open') {
          var blob = new Blob([payload], {type:'application/json'});
          var url = URL.createObjectURL(blob);
          window.open(url, '_blank', 'noopener'); setTimeout(()=>URL.revokeObjectURL(url), 15000);
        }
      }
    } catch(err){
      console.error('assets audit action failed', err);
      if (typeof toast === 'function') toast('error','Action failed: '+err.message);
    }
  }, true);

  // Ensure toolbar exists when this script loads
  ensureToolbar();
})();
