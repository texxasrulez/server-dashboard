(function(){
  'use strict';
  if (window.__TOAST_SELECTOR_DRIVER__) return;
  window.__TOAST_SELECTOR_DRIVER__ = true;

  var POS_KEY = 'ui.toast_position';
  var SEL_KEY = 'ui.toast_selector';

  function readPos(){ try { return localStorage.getItem(POS_KEY) || 'bottom-center'; } catch(e){ return 'bottom-center'; } }
  function readSel(){ try { return localStorage.getItem(SEL_KEY) || ''; } catch(e){ return ''; } }

  function $(sel){ try { return sel ? document.querySelector(sel) : null; } catch(e){ return null; } }

  function clearPos(el){
    if (!el) return;
    el.style.top=''; el.style.right=''; el.style.bottom=''; el.style.left=''; el.style.transform='';
    // do not change width/height/pointer-events
  }

  function applyPos(el, pos){
    if (!el) return;
    var style = el.style;
    // Force fixed so it sits relative to viewport
    style.position = 'fixed';
    clearPos(el);
    switch (String(pos||'').toLowerCase()){
      case 'top-left':      style.top='14px';    style.left='14px';    break;
      case 'top-center':    style.top='14px';    style.left='50%';     style.transform='translateX(-50%)'; break;
      case 'top-right':     style.top='14px';    style.right='14px';   break;
      case 'bottom-left':   style.bottom='14px'; style.left='14px';    break;
      case 'bottom-right':  style.bottom='14px'; style.right='14px';   break;
      case 'bottom-center':
      default:              style.bottom='14px'; style.left='50%';     style.transform='translateX(-50%)'; break;
    }
  }

  function apply(){
    var sel = readSel();
    if (!sel) return;
    var el = $(sel);
    if (!el) return;
    applyPos(el, readPos());
  }

  function boot(){
    apply();
    // re-apply on position changes broadcast
    window.addEventListener('ui:toast-position', apply);
    // re-apply periodically in case library repositions
    var tries = 0;
    var iv = setInterval(function(){
      tries++; apply();
      if (tries > 40) clearInterval(iv);
    }, 200);
    // also on new nodes
    var mo = new MutationObserver(function(){ apply(); });
    mo.observe(document.documentElement || document.body, {childList:true, subtree:true});
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();