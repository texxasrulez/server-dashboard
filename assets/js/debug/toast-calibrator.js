(function(){
  'use strict';
  if (window.__TOAST_CAL__) { try { window.__TOAST_CAL__.show(true); } catch(e){} return; }

  var SEL_KEY = 'ui.toast_selector';
  var POS_KEY = 'ui.toast_position';

  function save(k,v){ try { localStorage.setItem(k, v); } catch(e){} }
  function read(k,d){ try { return localStorage.getItem(k) || d; } catch(e){ return d; } }

  function cssPath(el){
    if (!(el instanceof Element)) return '';
    var path = [];
    while (el && el.nodeType === 1 && el !== document.body){
      var sel = el.nodeName.toLowerCase();
      if (el.id){ sel += '#' + el.id; path.unshift(sel); break; }
      var cls = (el.className || '').toString().trim().split(/\s+/).filter(Boolean);
      if (cls.length){ sel += '.' + cls.slice(0,3).join('.'); }
      var sib = el, idx = 1;
      while ((sib = sib.previousElementSibling) && idx < 6){ if (sib.nodeName === el.nodeName) idx++; }
      sel += ':nth-of-type(' + idx + ')';
      path.unshift(sel);
      el = el.parentElement;
    }
    return path.join(' > ');
  }

  function outline(el, on){
    if (!el) return;
    if (on){
      el.__old__boxShadow = el.style.boxShadow;
      el.style.boxShadow = '0 0 0 3px #4fc3f7 inset, 0 0 0 3px #4fc3f7';
    } else {
      el.style.boxShadow = el.__old__boxShadow || '';
    }
  }

  function candidateList(){
    var nodes = Array.from(document.querySelectorAll('*')).filter(function(el){
      var cs = getComputedStyle(el);
      if (cs.position !== 'fixed' && cs.position !== 'absolute') return false;
      var rect = el.getBoundingClientRect();
      if (rect.width < 160 || rect.width > window.innerWidth) return false;
      if (rect.height < 28 || rect.height > 260) return false;
      var zi = parseInt(cs.zIndex, 10) || 0;
      if (zi < 100) return false;
      return true;
    });
    // prefer those that contain words commonly used in toasts
    nodes.sort(function(a,b){
      function score(n){
        var t = (n.textContent || '').toLowerCase();
        var s = 0;
        ['saved','error','success','updated','copied','failed','warning','info'].forEach(function(w){ if (t.indexOf(w)>=0) s+=2; });
        return s + (parseInt(getComputedStyle(n).zIndex,10)||0)/1000;
      }
      return score(b)-score(a);
    });
    return nodes.slice(0,50);
  }

  function makeUI(){
    var host = document.createElement('div');
    host.style.position = 'fixed';
    host.style.left = '10px';
    host.style.bottom = '10px';
    host.style.zIndex = 999999;
    var sh = host.attachShadow ? host.attachShadow({mode:'open'}) : host;
    var wrap = document.createElement('div');
    wrap.innerHTML = [
      '<style>',
      '.card{font:13px/1.4 system-ui, -apple-system, Segoe UI, Roboto, Arial; background:rgba(20,20,20,.92); color:#eee; border:1px solid rgba(255,255,255,.15); border-radius:10px; box-shadow:0 10px 30px rgba(0,0,0,.35); padding:10px; min-width:340px;}',
      '.row{display:flex; gap:6px; align-items:center; margin:6px 0;}',
      'button,select{font:inherit;} button{all:unset; background:#444; color:#fff; padding:6px 10px; border-radius:8px; cursor:pointer;} button:hover{background:#555}',
      '.tiny{opacity:.8}',
      '.list{max-height:220px; overflow:auto; border:1px solid #444; border-radius:8px; padding:6px; margin-top:6px; background:rgba(0,0,0,.25);}',
      '.li{padding:4px 2px; border-bottom:1px dashed rgba(255,255,255,.12); cursor:pointer;} .li:last-child{border-bottom:none}',
      '.sel{color:#80d8ff}',
      'code{background:#111; padding:2px 4px; border-radius:4px; border:1px solid #333}',
      '</style>',
      '<div class="card">',
      '  <div class="row"><strong>Toast Target Calibrator</strong><span class="tiny"> — click a candidate to pin</span></div>',
      '  <div class="row tiny">Saved selector: <code id="saved"></code></div>',
      '  <div class="row"><button id="scan">Scan</button><button id="apply">Apply Position</button><button id="hide">Hide</button></div>',
      '  <div class="list" id="list"></div>',
      '</div>'
    ].join('');
    sh.appendChild(wrap);

    var saved = read(SEL_KEY, '');
    sh.getElementById('saved').textContent = saved || '(none)';

    function render(){
      var list = sh.getElementById('list');
      list.innerHTML = '';
      candidateList().forEach(function(n){
        var item = document.createElement('div');
        item.className = 'li';
        var path = cssPath(n);
        var txt = (n.tagName.toLowerCase() + (n.id ? '#'+n.id : '') + (n.className ? '.'+String(n.className).trim().split(/\s+/).slice(0,3).join('.') : ''));
        item.innerHTML = '<span class="sel">'+txt+'</span> <span class="tiny">→</span> <code>'+path+'</code>';
        item.addEventListener('mouseenter', function(){ outline(n, true); });
        item.addEventListener('mouseleave', function(){ outline(n, false); });
        item.addEventListener('click', function(){
          save(SEL_KEY, path);
          sh.getElementById('saved').textContent = path;
          // broadcast so the driver (or anyone) can re-apply
          try { window.dispatchEvent(new CustomEvent('ui:toast-position')); } catch(e){}
        });
        list.appendChild(item);
      });
    }

    sh.getElementById('scan').addEventListener('click', render);
    sh.getElementById('apply').addEventListener('click', function(){
      try { window.dispatchEvent(new CustomEvent('ui:toast-position')); } catch(e){}
    });
    sh.getElementById('hide').addEventListener('click', function(){ host.style.display = 'none'; });

    document.body.appendChild(host);
    render();

    return {
      show: function(v){ host.style.display = (v===false ? 'none' : 'block'); },
      rescan: render
    };
  }

  var ui = makeUI();
  window.__TOAST_CAL__ = ui;

})();