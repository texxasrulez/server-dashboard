// assets/js/force-newtab.js — overlay that forces new-tab for email indicator clicks
// Version: overlay-1
(function(){
  "use strict";
  // Expose version for debugging
  Object.defineProperty(window, "__FORCE_NEWTAB_V__", {value:"overlay-1", writable:false});

  function openNewTab(url){
    try {
      var w = window.open('about:blank', '_blank');
      if (w) { w.opener = null; try { w.location.replace(url); } catch(_) { w.location.href = url; } return; }
    } catch(_) {}
    var a = document.createElement('a');
    a.href = url; a.target = '_blank'; a.rel = 'noopener';
    document.body.appendChild(a); a.click(); a.remove();
  }

  function handler(ev){
    var t = ev.target;
    if (!t) return;
    var scope = document.getElementById('emailIndicatorBar');
    if (!scope) return;
    if (!scope.contains(t)) return;

    // find url from either data-link (button) or href (anchor)
    var link = null;
    var btn = t.closest && t.closest('#emailIndicatorBar .email-indicator-btn');
    if (btn) link = btn.getAttribute('data-link');
    if (!link) {
      var a = t.closest && t.closest('#emailIndicatorBar a[href]');
      if (a) link = a.getAttribute('href');
    }
    if (!link || link === 'javascript:void(0)') {
      var dl = (t.closest && t.closest('#emailIndicatorBar [data-link]')) || null;
      if (dl) link = dl.getAttribute('data-link');
    }
    if (!link) return;

    // kill everything and open a new tab
    ev.preventDefault();
    if (typeof ev.stopImmediatePropagation === 'function') ev.stopImmediatePropagation();
    ev.stopPropagation();
    openNewTab(link);
  }

  // Capture both pointerdown and click at window level (early)
  window.addEventListener('pointerdown', handler, true);
  window.addEventListener('click', handler, true);
})();