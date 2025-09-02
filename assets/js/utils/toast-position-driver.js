(function(){
  'use strict';
  var POS_KEY = 'ui.toast_position';

  function readPos(){
    try { return localStorage.getItem(POS_KEY) || 'bottom-center'; }
    catch(e){ return 'bottom-center'; }
  }

  function qsa(sel){ return Array.prototype.slice.call(document.querySelectorAll(sel)); }

  function targetEls(){
    var cands = [
      '#toast-root','.toast-root','[data-toast-root]',
      '#notifications','.notifications','.toast-container',
      '#notify','.notify','#notices','.notices','[aria-live="polite"]'
    ];
    var out = [];
    for (var i=0;i<cands.length;i++){
      var arr = qsa(cands[i]);
      for (var j=0;j<arr.length;j++){
        if (out.indexOf(arr[j]) === -1) out.push(arr[j]);
      }
    }
    return out;
  }

  function clear(el){
    if (!el) return;
    el.style.top = ''; el.style.bottom = ''; el.style.left = ''; el.style.right = '';
    el.style.transform = ''; el.style.alignItems = '';
  }

  function applyTo(el, pos){
    if (!el) return;
    clear(el);
    switch (String(pos||'').toLowerCase()) {
      case 'top-left':
        el.style.top='14px'; el.style.left='14px'; el.style.alignItems='flex-start'; break;
      case 'top-right':
        el.style.top='14px'; el.style.right='14px'; el.style.alignItems='flex-end'; break;
      case 'top-center':
        el.style.top='14px'; el.style.left='50%'; el.style.transform='translateX(-50%)'; el.style.alignItems='center'; break;
      case 'bottom-left':
        el.style.bottom='14px'; el.style.left='14px'; el.style.alignItems='flex-start'; break;
      case 'bottom-right':
        el.style.bottom='14px'; el.style.right='14px'; el.style.alignItems='flex-end'; break;
      case 'bottom-center':
      default:
        el.style.bottom='14px'; el.style.left='50%'; el.style.transform='translateX(-50%)'; el.style.alignItems='center'; break;
    }
  }

  function applyAll(){
    var pos = readPos();
    targetEls().forEach(function(el){ applyTo(el, pos); });
  }

  function ensureLater(){
    var mo = new MutationObserver(function(muts){
      var changed = false;
      for (var i=0;i<muts.length;i++){
        if (muts[i].addedNodes && muts[i].addedNodes.length){ changed = true; break; }
      }
      if (changed) applyAll();
    });
    mo.observe(document.documentElement || document.body, { childList:true, subtree:true });
  }

  function wrapToast(){
    if (!window.toast || window.__TOAST_WRAP__) return;
    window.__TOAST_WRAP__ = true;
    ['notice','success','error'].forEach(function(k){
      var fn = window.toast[k];
      if (typeof fn === 'function'){
        window.toast[k] = function(){
          var out = fn.apply(window.toast, arguments);
          try { applyAll(); } catch(e){}
          return out;
        };
      }
    });
  }

  function boot(){
    applyAll();
    ensureLater();
    wrapToast();
  }

  if (document.readyState === 'loading'){ document.addEventListener('DOMContentLoaded', boot); }
  else { boot(); }

  window.addEventListener('storage', function(e){ if (e && e.key === POS_KEY) applyAll(); });
  window.addEventListener('ui:toast-position', applyAll);
})();