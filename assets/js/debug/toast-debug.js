(function(){
  'use strict';
  if (window.__toastdbg) { try { window.__toastdbg.show(true); } catch(e){} return; }

  var API = {};
  var POSITIONS = ['top-left','top-center','top-right','bottom-left','bottom-center','bottom-right'];
  var SELECTORS = [
    '#toast-root','.toast-root','[data-toast-root]',
    '.toast-container','.toastify','.iziToast','.iziToast-wrapper','.notyf','.snackbar','.alertify-notifier',
    '#notifications','.notifications','#notify','.notify','#notices','.notices',
    '[role="alert"]','[aria-live="polite"]','[aria-live="assertive"]'
  ];

  function uniq(arr){ return Array.prototype.filter.call(arr, function(x, i){ return arr.indexOf(x)===i; }); }
  function qsAll(){ return uniq(SELECTORS.flatMap(function(sel){ return Array.from(document.querySelectorAll(sel)); })); }

  function clearPos(el){
    if (!el) return;
    ['top','right','bottom','left','transform','alignItems','align-content','justify-content'].forEach(function(p){
      el.style[p] = '';
    });
  }

  function clampContainer(el){
    // climb up to a fixed/absolute positioned ancestor; otherwise use the node itself
    var cur = el, depth = 0;
    while (cur && depth < 4){
      var cs = getComputedStyle(cur);
      if (cs.position === 'fixed' || cs.position === 'absolute') return cur;
      cur = cur.parentElement; depth++;
    }
    return el;
  }

  function applyPos(el, pos){
    if (!el) return;
    var target = clampContainer(el);
    // keep layout controlled, never change width/height
    target.style.position = 'fixed';
    clearPos(target);
    switch (String(pos||'').toLowerCase()){
      case 'top-left':     target.style.top='14px';    target.style.left='14px';    target.style.alignItems='flex-start'; break;
      case 'top-center':   target.style.top='14px';    target.style.left='50%';     target.style.transform='translateX(-50%)'; break;
      case 'top-right':    target.style.top='14px';    target.style.right='14px';   target.style.alignItems='flex-end'; break;
      case 'bottom-left':  target.style.bottom='14px'; target.style.left='14px';    target.style.alignItems='flex-start'; break;
      case 'bottom-center':target.style.bottom='14px'; target.style.left='50%';     target.style.transform='translateX(-50%)'; break;
      case 'bottom-right': target.style.bottom='14px'; target.style.right='14px';   target.style.alignItems='flex-end'; break;
      default:             target.style.bottom='14px'; target.style.left='50%';     target.style.transform='translateX(-50%)'; break;
    }
  }

  function outline(el, on){
    if (!el) return;
    if (on){
      el.__old_box_shadow__ = el.style.boxShadow;
      el.style.boxShadow = '0 0 0 3px #ff0 inset, 0 0 0 3px #ff0';
    } else {
      el.style.boxShadow = el.__old_box_shadow__ || '';
    }
  }

  function listCandidates(){
    var list = qsAll();
    API.lastNodes = list;
    return list.map(function(el, i){
      var cs = getComputedStyle(el);
      return {
        idx: i,
        tag: el.tagName.toLowerCase(),
        id: el.id || '',
        className: el.className || '',
        position: cs.position,
        aria: el.getAttribute('aria-live') || '',
        role: el.getAttribute('role') || '',
        rect: el.getBoundingClientRect().toJSON ? el.getBoundingClientRect().toJSON() : (function(r){return {x:r.x,y:r.y,width:r.width,height:r.height};})(el.getBoundingClientRect())
      };
    });
  }

  function applyCurrent(pos){
    var nodes = API.lastNodes && API.lastNodes.length ? API.lastNodes : qsAll();
    nodes.forEach(function(n){ applyPos(n, pos); });
  }

  function makeOverlay(){
    var host = document.createElement('div');
    host.style.position = 'fixed';
    host.style.right = '10px';
    host.style.bottom = '10px';
    host.style.zIndex = 999999;
    host.style.fontFamily = 'ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, Cantarell, Noto Sans, Arial';
    host.style.opacity = '0.92';

    var sh = host.attachShadow ? host.attachShadow({mode:'open'}) : host;
    var wrap = document.createElement('div');
    wrap.innerHTML = [
      '<style>',
      '.card{background:rgba(30,30,30,.92);color:#eee;border:1px solid rgba(255,255,255,.15);border-radius:10px;box-shadow:0 10px 30px rgba(0,0,0,.35);padding:10px;min-width:280px;}',
      '.row{display:flex;gap:6px;align-items:center;margin:6px 0;}',
      '.btn{all:unset;background:#444;color:#fff;padding:6px 10px;border-radius:8px;cursor:pointer;}',
      '.btn:hover{background:#555}',
      '.tiny{font-size:12px;opacity:.85}',
      'select,button{font:inherit;} select{background:#111;color:#fff;border:1px solid #555;border-radius:6px;padding:4px 6px;}',
      'label{min-width:110px;display:inline-block;}',
      '.list{max-height:160px;overflow:auto;border:1px solid #444;border-radius:8px;padding:6px;margin-top:6px;background:rgba(0,0,0,.25);}',
      '.li{padding:4px 2px;border-bottom:1px dashed rgba(255,255,255,.1);} .li:last-child{border-bottom:none}',
      '.hl{color:#ffd54f}',
      '</style>',
      '<div class="card">',
      '<div class="row"><strong>Toast Debugger</strong><span class="tiny">&nbsp;&mdash; press Ctrl+Shift+D to toggle</span></div>',
      '<div class="row"><label>Position</label>',
      '<select id="pos"></select>',
      '<button class="btn" id="apply">Apply</button>',
      '<button class="btn" id="test">Test toast</button>',
      '</div>',
      '<div class="row tiny" id="status">scanning…</div>',
      '<div class="list" id="list"></div>',
      '</div>'
    ].join('');
    sh.appendChild(wrap);

    var posSel = sh.getElementById('pos');
    POSITIONS.forEach(function(p){
      var o = document.createElement('option'); o.value = p; o.textContent = p;
      posSel.appendChild(o);
    });
    posSel.value = (function(){ try { return localStorage.getItem('ui.toast_position') || 'bottom-center'; } catch(e){ return 'bottom-center'; } })();

    sh.getElementById('apply').addEventListener('click', function(){
      try { localStorage.setItem('ui.toast_position', posSel.value); } catch(e){}
      applyCurrent(posSel.value);
      status('Applied & saved: ' + posSel.value);
      try { window.dispatchEvent(new CustomEvent('ui:toast-position', { detail: { position: posSel.value } })); } catch(e){}
    });
    sh.getElementById('test').addEventListener('click', function(){
      if (window.toast && window.toast.notice) window.toast.notice('Toast debug: '+ new Date().toLocaleTimeString());
      else {
        // create a minimal toast element next to first candidate to visualize
        var nodes = API.lastNodes || qsAll();
        var anchor = nodes[0] || document.body;
        var t = document.createElement('div');
        t.textContent = 'Toast debug (no engine)';
        t.style.cssText = 'pointer-events:auto;background:rgba(30,30,30,.95);color:#fff;padding:8px 10px;border-radius:8px;margin:6px;box-shadow:0 6px 20px rgba(0,0,0,.35)';
        anchor.appendChild(t);
        setTimeout(function(){ if (t && t.parentNode) t.parentNode.removeChild(t); }, 2500);
      }
    });

    function status(txt){ sh.getElementById('status').textContent = txt; }
    function renderList(){
      var list = sh.getElementById('list');
      list.innerHTML = '';
      API.lastNodes.forEach(function(n, i){
        var cs = getComputedStyle(n);
        var div = document.createElement('div');
        div.className = 'li';
        div.innerHTML = '<span class="hl">['+i+']</span> &lt;'+n.tagName.toLowerCase()+' id="'+(n.id||'')+'" class="'+(n.className||'')+'"&gt; — pos:'+cs.position+', top:'+cs.top+', left:'+cs.left+', right:'+cs.right+', bottom:'+cs.bottom;
        div.addEventListener('mouseenter', function(){ outline(n, true); });
        div.addEventListener('mouseleave', function(){ outline(n, false); });
        list.appendChild(div);
      });
    }

    function scan(){
      API.lastNodes = qsAll();
      status('Candidates: ' + API.lastNodes.length);
      renderList();
    }

    API.scan = scan;
    API.show = function(show){
      host.style.display = show===false ? 'none' : 'block';
    };

    document.addEventListener('keydown', function(e){
      if (e.ctrlKey && e.shiftKey && (e.key === 'D' || e.key === 'd')){
        API.show(host.style.display === 'none');
      }
    });

    document.body.appendChild(host);
    scan();
    return { host: host, shadow: sh, scan: scan, posSel: posSel };
  }

  var overlay = makeOverlay();
  API.apply = applyCurrent;
  API.candidates = listCandidates;
  API.positions = POSITIONS.slice();

  window.__toastdbg = API;

  // auto-observe DOM to rescan and re-apply
  var mo = new MutationObserver(function(muts){
    var added = muts.some(function(m){ return (m.addedNodes && m.addedNodes.length); });
    if (added){ overlay.scan(); }
  });
  mo.observe(document.documentElement || document.body, { childList:true, subtree:true });

  // initial apply if a stored position exists
  try {
    var saved = localStorage.getItem('ui.toast_position');
    if (saved) API.apply(saved);
  } catch(e){}
})();