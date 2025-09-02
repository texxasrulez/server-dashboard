/* app.js (placeholder) */
window.APP_READY = true;

;(function(){
  try {
    var cfg = (window.__CONFIG_DATA__ || {});
    var hc = false;
    if (cfg && cfg.ui && typeof cfg.ui.high_contrast !== 'undefined') hc = !!cfg.ui.high_contrast;
    if (hc) document.documentElement.classList.add('theme-contrast');
  } catch(e){}
})();
