/*! metrics.js shim (correct base) → loads metrics.zoom.js */
(function(){
  try {
    var cs = document.currentScript; if (!cs) return;
    var href = cs.src || ''; var search = '';
    try { var u = new URL(href); search = u.search || ''; } catch(e){}
    var target = href.replace(/metrics\.js(\?.*)?$/, 'metrics.zoom.js' + search);
    var s = document.createElement('script'); s.defer = true; s.src = target;
    (cs.parentNode || document.head || document.documentElement).insertBefore(s, cs.nextSibling);
  } catch(e) { console.error('metrics.js shim failed', e); }
})();