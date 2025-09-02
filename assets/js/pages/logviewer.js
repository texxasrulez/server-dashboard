
(function(){
  function el(t, cls){ var x=document.createElement(t); if(cls) x.className=cls; return x; }
  function $(s){ return document.querySelector(s); }
  function esc(s){ return String(s).replace(/[&<>]/g, m=>({'&':'&amp;','<':'&lt;','>':'&gt;'}[m])); }
  var app = document.getElementById('logapp');
  if (!app) return;

  var LS_KEY = 'logviewer.state.v1';
  var PRESETS_KEY = 'logviewer.presets.v1';

  var state = { items:[], sel:-1, q:'', re:'', ci:false, n:null, max:null, follow:true, timer:null, poll:2000,
                colorize:true, join:false, lines:[], meta:{} };

  function chip(text, cls){ var c=el('span','chip '+(cls||'')); c.textContent=text; return c; }

  function buildUI(){
    var card = el('div','card'); card.style.maxWidth='100%';
    var h = el('h3'); h.textContent = 'Log Viewer (Tail & Filters)'; card.appendChild(h);

    var controls = el('div'); controls.style.display='grid';
    controls.style.gridTemplateColumns='minmax(240px,1fr) minmax(160px,auto) minmax(180px,auto) minmax(140px,auto) minmax(140px,auto) auto auto auto auto auto';
    controls.style.gap='8px'; controls.style.alignItems='center';

    var sel = el('select'); sel.id='lv-sel';
    controls.appendChild(sel);

    var q = el('input'); q.placeholder='contains…'; q.id='lv-q';
    controls.appendChild(q);

    var reI = el('input'); reI.placeholder='regex /.../'; reI.id='lv-re';
    controls.appendChild(reI);

    var ci = el('label'); var cb = el('input'); cb.type='checkbox'; cb.id='lv-ci'; ci.appendChild(cb); ci.appendChild(document.createTextNode(' case‑insensitive'));
    controls.appendChild(ci);

    var n = el('select'); n.id='lv-n';
    [16,32,64,128,256,512,1024].forEach(function(kb){ var o=el('option'); o.value=kb*1024; o.textContent='Tail '+kb+' KB'; n.appendChild(o); });
    controls.appendChild(n);

    var max = el('select'); max.id='lv-max';
    [200,500,1000,2000].forEach(function(m){ var o=el('option'); o.value=m; o.textContent='Show '+m+' lines'; max.appendChild(o); });
    controls.appendChild(max);

    var follow = el('label'); var fcb = el('input'); fcb.type='checkbox'; fcb.checked=true; fcb.id='lv-follow'; follow.appendChild(fcb); follow.appendChild(document.createTextNode(' follow')); controls.appendChild(follow);

    var colorize = el('label'); var ccb = el('input'); ccb.type='checkbox'; ccb.checked=true; ccb.id='lv-colorize'; colorize.appendChild(ccb); colorize.appendChild(document.createTextNode(' colorize')); controls.appendChild(colorize);

    var join = el('label'); var jcb = el('input'); jcb.type='checkbox'; jcb.id='lv-join'; join.appendChild(jcb); join.appendChild(document.createTextNode(' join stacktraces')); controls.appendChild(join);

    var wrapL = el('label'); var wcb = el('input'); wcb.type='checkbox'; wcb.id='lv-wrap'; wcb.checked=true; wrapL.appendChild(wcb); wrapL.appendChild(document.createTextNode(' wrap')); controls.appendChild(wrapL);

    var btn = el('button','btn'); btn.textContent='Refresh'; controls.appendChild(btn);

    // right-side utility buttons
    var bCopy = el('button','btn'); bCopy.textContent='Copy'; controls.appendChild(bCopy);
    var bTxt = el('button','btn'); bTxt.textContent='Download TXT'; controls.appendChild(bTxt);
    var bJson = el('button','btn'); bJson.textContent='Download JSON'; controls.appendChild(bJson);

    card.appendChild(controls);

    // preset bar
    var pres = el('div'); pres.style.display='flex'; pres.style.gap='8px'; pres.style.alignItems='center'; pres.style.margin='6px 0';
    var pSel = el('select'); pSel.id='lv-preset'; pres.appendChild(pSel);
    var pName = el('input'); pName.placeholder='preset name'; pName.style.minWidth='160px'; pres.appendChild(pName);
    var bSave = el('button','btn'); bSave.textContent='Save preset'; pres.appendChild(bSave);
    var bLoad = el('button','btn'); bLoad.textContent='Load'; pres.appendChild(bLoad);
    var bDel = el('button','btn'); bDel.textContent='Delete'; pres.appendChild(bDel);
    var bBm = el('button','btn'); bBm.textContent='Bookmark view'; pres.appendChild(bBm);
    card.appendChild(pres);

    var meta = el('div'); meta.style.margin='6px 0'; card.appendChild(meta);

    var preWrap = el('div'); preWrap.style.maxHeight='60vh'; preWrap.style.overflowY='auto'; preWrap.style.overflowX='hidden'; preWrap.style.width='100%'; preWrap.id='lv-wrap';
    var pre = el('pre'); pre.id='lv-pre'; pre.style.whiteSpace='pre-wrap'; pre.style.overflowWrap='anywhere'; pre.style.wordBreak='break-word'; pre.style.fontSize='12px'; pre.style.lineHeight='1.35'; pre.style.margin='0'; pre.style.minWidth='0';
    preWrap.appendChild(pre);
    card.appendChild(preWrap);

    app.appendChild(card);

    // wire
    btn.addEventListener('click', fetchTail);
    sel.addEventListener('change', function(){ state.sel = parseInt(this.value,10)||-1; fetchTail(true); saveState(); });
    q.addEventListener('input', function(){ state.q=this.value; saveState(); });
    q.addEventListener('change', function(){ fetchTail(true); });
    reI.addEventListener('input', function(){ state.re=this.value.replace(/^\/|\/[a-z]*$/g,''); saveState(); });
    reI.addEventListener('change', function(){ fetchTail(true); });
    cb.addEventListener('change', function(){ state.ci=this.checked; fetchTail(true); saveState(); });
    n.addEventListener('change', function(){ state.n=parseInt(this.value,10)||null; fetchTail(true); saveState(); });
    max.addEventListener('change', function(){ state.max=parseInt(this.value,10)||null; fetchTail(true); saveState(); });
    fcb.addEventListener('change', function(){ state.follow=this.checked; saveState(); });
    ccb.addEventListener('change', function(){ state.colorize=this.checked; renderLines(); saveState(); });
    jcb.addEventListener('change', function(){ state.join=this.checked; renderLines(); saveState(); });
    wcb.addEventListener('change', function(){ state.wrap=this.checked; applyWrap(); saveState(); });

    bCopy.addEventListener('click', function(){
      var text = state.lines.join('\n')+'\n';
      navigator.clipboard && navigator.clipboard.writeText(text).then(()=>toast && toast.info && toast.info('Copied'), ()=>{});
    });
    function download(filename, content, type){
      var blob = new Blob([content], {type:type||'text/plain'});
      var a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = filename;
      document.body.appendChild(a); a.click(); setTimeout(()=>{ URL.revokeObjectURL(a.href); a.remove(); }, 100);
    }
    bTxt.addEventListener('click', function(){
      var name = (state.meta && state.meta.label) || 'log'; download(name+'.txt', state.lines.join('\\n')+'\\n', 'text/plain');
    });
    bJson.addEventListener('click', function(){
      var payload = { when: new Date().toISOString(), filters: {q:state.q, re:state.re, ci:state.ci, n:state.n, max:state.max}, meta: state.meta, lines: state.lines };
      download(((state.meta && state.meta.label)||'log')+'.json', JSON.stringify(payload,null,2), 'application/json');
    });

    // presets
    function loadPresets(){
      var arr = []; try { arr = JSON.parse(localStorage.getItem(PRESETS_KEY)||'[]')||[]; } catch(e){}
      pSel.innerHTML=''; arr.forEach(function(p, i){ var o=document.createElement('option'); o.value=i; o.textContent=p.name; pSel.appendChild(o); });
      return arr;
    }
    function currentSnapshot(){
      return { name: pName.value || 'preset', sel: state.sel, q: state.q, re: state.re, ci: state.ci, n: state.n, max: state.max, follow: state.follow, colorize: state.colorize, join: state.join, wrap: state.wrap };
    }
    function applySnapshot(p){
      if (typeof p.sel === 'number') { state.sel=p.sel; sel.value=String(p.sel); }
      state.q=p.q||''; q.value=state.q;
      state.re=p.re||''; reI.value=state.re;
      state.ci=!!p.ci; cb.checked=state.ci;
      if (p.n) { state.n=p.n; n.value=String(p.n); }
      if (p.max) { state.max=p.max; max.value=String(p.max); }
      state.follow = !!p.follow; fcb.checked=state.follow;
      state.colorize = (p.colorize!==false); ccb.checked=state.colorize;
      state.join = !!p.join; jcb.checked=state.join;
      state.wrap = (p.wrap!==false); state.dom.wcb.checked = state.wrap; applyWrap();
      fetchTail(true);
    }
    bSave.addEventListener('click', function(){
      var arr = loadPresets(); arr.push(currentSnapshot()); localStorage.setItem(PRESETS_KEY, JSON.stringify(arr)); loadPresets();
    });
    bLoad.addEventListener('click', function(){
      var arr = loadPresets(); var i = parseInt(pSel.value,10)||0; if (arr[i]) applySnapshot(arr[i]);
    });
    bDel.addEventListener('click', function(){
      var arr = loadPresets(); var i = parseInt(pSel.value,10)||0; if (arr[i]) { arr.splice(i,1); localStorage.setItem(PRESETS_KEY, JSON.stringify(arr)); loadPresets(); }
    });
    bBm.addEventListener('click', function(){
      var snap = currentSnapshot(); var hash = encodeURIComponent(JSON.stringify(snap)); location.hash = 'lv=' + hash;
    });
    loadPresets();

    // init values from config defaults
    state.poll = (window.__CONFIG_DATA__ && window.__CONFIG_DATA__.logs && window.__CONFIG_DATA__.logs.poll_ms) || 2000;
    var defN = (window.__CONFIG_DATA__ && window.__CONFIG_DATA__.logs && window.__CONFIG_DATA__.logs.tail_bytes) || 65536;
    n.value = String(defN);
    state.n = defN;
    var defMax = (window.__CONFIG_DATA__ && window.__CONFIG_DATA__.logs && window.__CONFIG_DATA__.logs.grep_max_lines) || 1000;
    max.value = String(defMax);
    state.max = defMax;

    // keyboard: / focus, r refresh, f follow toggle
    document.addEventListener('keydown', function(ev){
      if (ev.key === '/' && !ev.ctrlKey && !ev.metaKey) { ev.preventDefault(); q.focus(); q.select(); }
      if (ev.key === 'r' && !ev.ctrlKey && !ev.metaKey) { fetchTail(true); }
      if (ev.key === 'f' && !ev.ctrlKey && !ev.metaKey) { fcb.checked=!fcb.checked; state.follow=fcb.checked; saveState(); }
    });

    // auto follow
    if (state.timer) clearInterval(state.timer);
    state.timer = setInterval(function(){ if (state.follow) fetchTail(); }, state.poll);

    // public handles
    state.dom = { sel, q, reI, pre, meta, wrap: preWrap, wcb };
  }

  function applyWrap(){
    if (!state.dom || !state.dom.pre) return;
    if (state.wrap){
      state.dom.pre.style.whiteSpace='pre-wrap';
      state.dom.pre.style.overflowWrap='anywhere';
      state.dom.pre.style.wordBreak='break-word';
      state.dom.wrap.style.overflowX='hidden';
    } else {
      state.dom.pre.style.whiteSpace='pre';
      state.dom.pre.style.overflowWrap='normal';
      state.dom.pre.style.wordBreak='normal';
      state.dom.wrap.style.overflowX='auto';
    }
  }

  function loadList(){
    fetch('api/logs_list.php').then(r=>r.json()).then(function(j){
      if (!j || !j.ok) throw new Error('list failed');
      state.items = j.items||[];
      var sel = state.dom.sel;
      sel.innerHTML = '';
      state.items.forEach(function(it, idx){
        var o = document.createElement('option'); o.value=idx; o.textContent = it.label + (it.exists?'':' (missing)');
        sel.appendChild(o);
      });
      // restore from hash or LS
      var snap = null;
      if (location.hash.startsWith('#lv=')) {
        try { snap = JSON.parse(decodeURIComponent(location.hash.slice(4))); } catch(e){}
      }
      if (!snap) {
        try { snap = JSON.parse(localStorage.getItem(LS_KEY)||'{}'); } catch(e){}
      }
      if (snap && typeof snap.sel === 'number' && snap.sel < state.items.length) { state.sel = snap.sel; sel.value = String(state.sel); }
      else if (state.sel < 0 && state.items.length) { state.sel = 0; sel.value = "0"; }
      if (snap) applySnapshot(snap); else fetchTail(true);
    }).catch(function(e){
      state.dom.pre.textContent = 'Log list error: ' + e.message;
    });
  }

  function renderLines(){
    var pre = state.dom.pre;
    var lines = state.lines.slice();
    if (state.join){
      var joined = []; var cur = '';
      lines.forEach(function(ln){
        if (/^\\s+/.test(ln)) { cur += (cur? '\\n' : '') + ln; }
        else { if (cur) { joined.push(cur); cur=''; } joined.push(ln); }
      });
      if (cur) joined.push(cur);
      lines = joined;
    }
    // colorize
    var html = '';
    for (var i=0;i<lines.length;i++){
      var s = esc(lines[i]);
      if (state.colorize){
        if (/(?:\\bERROR\\b|\\bfail(?:ed)?\\b|\\bcrit(?:ical)?\\b)/i.test(s)) s = '<span class="chip chip-bad" style="padding:0 6px; margin-right:6px;">ERR</span>'+s;
        else if (/(?:\\bwarn(?:ing)?\\b)/i.test(s)) s = '<span class="chip chip-med" style="padding:0 6px; margin-right:6px;">WARN</span>'+s;
        else if (/(?:\\bok\\b|\\bready\\b|\\bstarted\\b)/i.test(s)) s = '<span class="chip chip-good" style="padding:0 6px; margin-right:6px;">OK</span>'+s;
      }
      html += s + '\\n';
    }
    pre.innerHTML = html;
    if (state.follow) { state.dom.wrap.scrollTop = state.dom.wrap.scrollHeight; }
  }

  function saveState(){
    var snap = { sel: state.sel, q: state.q, re: state.re, ci: state.ci, n: state.n, max: state.max, follow: state.follow, colorize: state.colorize, join: state.join, wrap: state.wrap };
    try { localStorage.setItem(LS_KEY, JSON.stringify(snap)); } catch(e){}
  }

  function fetchTail(force){
    if (state.sel < 0) return;
    var it = state.items[state.sel]; if (!it) return;
    var url = new URL('api/logs_tail.php', location.href);
    url.searchParams.set('id', state.sel);
    if (state.q) url.searchParams.set('q', state.q);
    if (state.re) url.searchParams.set('re', state.re);
    if (state.ci) url.searchParams.set('ci', '1');
    if (state.n) url.searchParams.set('n', state.n);
    if (state.max) url.searchParams.set('max', state.max);

    fetch(url.toString()).then(r=>r.json()).then(function(j){
      if (!j || !j.ok) throw new Error(j && j.error || 'tail failed');
      state.meta = {label:j.label, path:j.path, size:j.size, returned:j.returned};
      state.dom.meta.innerHTML = '';
      state.dom.meta.appendChild(chip('size: '+(j.size||0)+' bytes','muted'));
      state.dom.meta.appendChild(chip('returned: '+(j.returned||0),'muted'));
      state.dom.meta.appendChild(chip('file: '+j.path,'muted'));
      state.lines = (j.lines||[]);
      renderLines();
      saveState();
    }).catch(function(e){
      state.dom.pre.textContent = 'Tail error: ' + e.message;
    });
  }

  document.addEventListener('DOMContentLoaded', function(){
    buildUI();
    loadList();
  });
})();
