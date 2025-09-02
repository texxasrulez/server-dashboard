/*! domtoast_shim.js — maps legacy domToast() calls to unified window.toast, non-destructively. */
(function(){
  function patch(){
    if (typeof window === "undefined") return;
    // If a page defines domToast, override it to call unified toast if present.
    if (typeof window.domToast === "function") {
      var _orig = window.domToast;
      window.domToast = function(type, text){
        try {
          if (window.toast && typeof window.toast.info === "function") {
            if (type === "error")   return window.toast.error(text);
            if (type === "warn" || type === "warning") return window.toast.warn(text);
            if (type === "success") return window.toast.success(text);
            return window.toast.info(text);
          }
        } catch(e){ /* ignore and fall back */ }
        try { return _orig.apply(this, arguments); } catch(e) { /* swallow */ }
      };
    }
  }
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", patch, {once:true});
  } else { patch(); }
  // Also patch after a tick, in case history.js loads late
  setTimeout(patch, 250);
  setTimeout(patch, 1000);
})();