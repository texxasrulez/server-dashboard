(function(){
  'use strict';
  if (window.__TOAST_POS_AUTO__) return; window.__TOAST_POS_AUTO__ = true;
  var POS_KEY = 'ui.toast_position';

  function readPos(){
    try { return localStorage.getItem(POS_KEY) || 'bottom-center'; }
    catch(e){ return 'bottom-center'; }
  }
  function setLS(pos){ try { localStorage.setItem(POS_KEY, pos); } catch(e){} }

  function isToastLike(el){
    if (!el || el.nodeType !== 1) return false;
    var role = el.getAttribute('role') || '';
    var al = (el.getAttribute('aria-live') || '').toLowerCase();
    var cls = (el.className || '').toString().toLowerCase();
    if (role === 'alert' || role === 'status' || al === 'polite' || al === 'assertive') return true;
    return /(toast|notify|notyf|izi|snackbar|snack|alertify|toastr|toastify)/.test(cls);
  }

  function clampContainer(el){
    // walk up a few levels to find a stable positioning container
    var cur = el, depth = 0;
    while (cur && depth < 4){
      var s = window.getComputedStyle(cur);
      // prefer a container with position fixed/absolute and no inline width/height constraints
      if (s.position === 'fixed' || s.position === 'absolute') return cur;
      cur = cur.parentElement; depth++;
    }
    return el;
  }

  function clearPos(el){
    if (!el) return;
    el.style.setProperty('top', 'auto', 'important');
    el.style.setProperty('right', 'auto', 'important');
    el.style.setProperty('bottom', 'auto', 'important');
    el.style.setProperty('left', 'auto', 'important');
    el.style.setProperty('transform', 'none', 'important');
  }

  function applyPos(el, pos){
    if (!el) return;
    var target = clampContainer(el);
    // ensure it's fixed so we can move it universally
    target.style.setProperty('position', 'fixed', 'important');
    // keep pointer-events behavior untouched
    clearPos(target);
    switch (String(pos||'').toLowerCase()){
      case 'top-left':
        target.style.setProperty('top','14px','important');
        target.style.setProperty('left','14px','important');
        break;
      case 'top-right':
        target.style.setProperty('top','14px','important');
        target.style.setProperty('right','14px','important');
        break;
      case 'top-center':
        target.style.setProperty('top','14px','important');
        target.style.setProperty('left','50%','important');
        target.style.setProperty('transform','translateX(-50%)','important');
        break;
      case 'bottom-left':
        target.style.setProperty('bottom','14px','important');
        target.style.setProperty('left','14px','important');
        break;
      case 'bottom-right':
        target.style.setProperty('bottom','14px','important');
        target.style.setProperty('right','14px','important');
        break;
      case 'bottom-center':
      default:
        target.style.setProperty('bottom','14px','important');
        target.style.setProperty('left','50%','important');
        target.style.setProperty('transform','translateX(-50%)','important');
        break;
    }
  }

  function applyAll(){
    var pos = readPos();
    var nodes = document.querySelectorAll('[role="alert"],[aria-live],.toast,.toast-container,.toastify,.iziToast,.iziToast-wrapper,.notyf,.snackbar,.alertify-notifier,#toast-root,.toast-root,[data-toast-root]');
    for (var i=0;i<nodes.length;i++){
      applyPos(nodes[i], pos);
    }
  }

  function observe(){
    var mo = new MutationObserver(function(muts){
      var changed = false;
      for (var i=0;i<muts.length;i++){
        var m = muts[i];
        if (m.addedNodes && m.addedNodes.length){
          for (var j=0;j<m.addedNodes.length;j++){
            var n = m.addedNodes[j];
            if (isToastLike(n) || (n.querySelector && n.querySelector('.toast, [role="alert"], [aria-live]'))) { changed = true; break; }
          }
        }
        if (changed) break;
      }
      if (changed) applyAll();
    });
    mo.observe(document.documentElement || document.body, {childList:true, subtree:true});
  }

  function boot(){
    // initial pass, then a few retries (in case engine adds late)
    applyAll();
    var tries = 0;
    var iv = setInterval(function(){
      tries++; applyAll();
      if (tries > 20) clearInterval(iv);
    }, 150);
    observe();
  }

  // Allow external controllers (config page) to broadcast the new position
  window.addEventListener('ui:toast-position', function(e){
    var pos = (e && e.detail && e.detail.position) || readPos();
    if (e && e.detail && e.detail.position) setLS(e.detail.position);
    applyAll();
  });

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();