/* app.js (placeholder) */
window.APP_READY = true;

(function () {
  // Add CSRF header automatically for same-origin mutating requests.
  try {
    var origFetch = window.fetch;
    var csrf =
      (document.querySelector('meta[name="csrf-token"]') || {}).content || "";
    if (origFetch && csrf) {
      window.fetch = function (input, init) {
        var opts = init ? Object.assign({}, init) : {};
        var method = String(opts.method || "GET").toUpperCase();
        var isMutating =
          method !== "GET" && method !== "HEAD" && method !== "OPTIONS";
        if (isMutating) {
          var headers = new Headers(opts.headers || {});
          if (!headers.has("X-CSRF-Token")) headers.set("X-CSRF-Token", csrf);
          opts.headers = headers;
        }
        return origFetch(input, opts);
      };
    }
  } catch (e) {}
})();

(function () {
  try {
    function applyHighContrastMode(enabled) {
      var on = !!enabled;
      if (document.documentElement) {
        document.documentElement.classList.toggle("theme-contrast", on);
      }
      if (document.body) {
        document.body.classList.toggle("theme-contrast", on);
      }
    }

    var cfg = window.__CONFIG_DATA__ || {};
    var hc = false;
    if (document.body && document.body.dataset && document.body.dataset.highContrast) {
      hc = document.body.dataset.highContrast === "1";
    }
    if (cfg && cfg.ui && typeof cfg.ui.high_contrast !== "undefined")
      hc = !!cfg.ui.high_contrast;
    applyHighContrastMode(hc);
    window.__setHighContrastMode = applyHighContrastMode;
  } catch (e) {}
})();
