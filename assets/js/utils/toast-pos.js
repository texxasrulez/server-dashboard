(function(){
  'use strict';
  if (window.__TOAST_POS_INIT__) return; // singleton
  window.__TOAST_POS_INIT__ = true;

  function $(s,ctx){ return (ctx||document).querySelector(s); }

  function readPos(){
    try { return localStorage.getItem('ui.toast_position') || 'bottom-center'; }
    catch(e){ return 'bottom-center'; }
  }

  function getRoot(){
    return document.getElementById('toast-root') || $('.toast-root') || $('[data-toast-root]');
  }

  function clearPos(root){
    if (!root) return;
    root.style.top = ''; root.style.bottom=''; root.style.left=''; root.style.right=''; root.style.transform=''; root.style.alignItems='';
  }

  function applyPos(root, pos){
    if (!root) return;
    clearPos(root);
    switch (String(pos||'').toLowerCase()){
      case 'top-left':
        root.style.top='14px'; root.style.left='14px'; root.style.alignItems='flex-start'; break;
      case 'top-right':
        root.style.top='14px'; root.style.right='14px'; root.style.alignItems='flex-end'; break;
      case 'top-center':
        root.style.top='14px'; root.style.left='50%'; root.style.transform='translateX(-50%)'; root.style.alignItems='center'; break;
      case 'bottom-left':
        root.style.bottom='14px'; root.style.left='14px'; root.style.alignItems='flex-start'; break;
      case 'bottom-right':
        root.style.bottom='14px'; root.style.right='14px'; root.style.alignItems='flex-end'; break;
      case 'bottom-center':
      default:
        root.style.bottom='14px'; root.style.left='50%'; root.style.transform='translateX(-50%)'; root.style.alignItems='center'; break;
    }
  }

  function ensureApplied(){
    var root = getRoot();
    if (root){ applyPos(root, readPos()); return true; }
    return false;
  }

  // Observe for container creation if needed
  if (!ensureApplied()){
    var mo = new MutationObserver(function(){
      if (ensureApplied()){ mo.disconnect(); }
    });
    mo.observe(document.documentElement || document.body, { childList:true, subtree:true });
    // time fallback
    var tries = 0;
    var iv = setInterval(function(){
      tries++; if (ensureApplied()){ clearInterval(iv); mo.disconnect(); }
      if (tries > 50){ clearInterval(iv); mo.disconnect(); }
    }, 100);
  }

  // Add setPosition API safely (no breakage if existing toast object)
  function setPosition(pos){
    try { localStorage.setItem('ui.toast_position', pos); } catch(e){}
    var root = getRoot();
    if (root) applyPos(root, pos);
  }

  if (!window.toast) window.toast = {};
  if (!window.toast.setPosition) window.toast.setPosition = setPosition;

  // Respond to cross-tab changes
  window.addEventListener('storage', function(e){
    if (e && e.key === 'ui.toast_position') ensureApplied();
  });
})();