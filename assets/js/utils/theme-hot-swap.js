(function () {
  "use strict";
  function $(s, ctx) {
    return (ctx || document).querySelector(s);
  }
  function $all(s, ctx) {
    return Array.prototype.slice.call((ctx || document).querySelectorAll(s));
  }

  function ensureConfigCss() {
    if (!document.getElementById("config-css")) {
      var link = document.createElement("link");
      link.id = "config-css";
      link.rel = "stylesheet";
      link.href = "assets/css/pages/config.css";
      document.head.appendChild(link);
    }
  }

  function findThemeLinks() {
    return $all('link[rel="stylesheet"]').filter(function (link) {
      var href = link.getAttribute("href") || "";
      return /\/assets\/css\/themes\//i.test(href);
    });
  }

  function currentThemeFromBody() {
    var b = document.body;
    if (!b) return null;
    var m = (b.className || "").match(/\btheme-([a-z0-9_\-]+)\b/i);
    return m ? m[1] : null;
  }

  function applyTheme(theme) {
    if (!theme) return;
    var links = findThemeLinks();
    var old = currentThemeFromBody();
    links.forEach(function (link) {
      var href = link.getAttribute("href") || "";
      var parts = href.split("?");
      var base = parts[0];
      var suffix = parts[1] ? "?" + parts[1] : "";
      base = base.replace(
        /(\/themes\/)([^\/]+?)(\.mobile)?\.css$/i,
        function (_, prefix, _name, mobile) {
          return prefix + theme + (mobile || "") + ".css";
        },
      );
      link.setAttribute("href", base + "?ts=" + Date.now());
    });
    if (old) {
      document.body.classList.remove("theme-" + old);
    }
    document.body.classList.add("theme-" + theme);
    if (window.toast && window.toast.notice) {
      window.toast.notice("Theme applied: " + theme);
    }
  }

  function getConfigThemeSelect() {
    return (
      document.querySelector('[name="site.theme"]') ||
      document.querySelector('[data-key="site.theme"] select') ||
      document.querySelector("#cfgTheme") ||
      document.querySelector('[data-setting="site.theme"] select')
    );
  }

  function postThemePersist(theme) {
    if (!theme) return Promise.resolve();
    var body = "theme=" + encodeURIComponent(theme) + "&persist=1";
    return fetch("theme_set.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: body,
      credentials: "same-origin",
    });
  }

  function bindConfigPicker() {
    var sel = getConfigThemeSelect();
    if (!sel) return;
    sel.addEventListener("change", function () {
      applyTheme(sel.value);
    });
  }

  // Reload on config save success
  (function wrapFetchForConfigSave() {
    if (!window.fetch) return;
    var orig = window.fetch;
    var reConfig = /config\.php(?:\?|$)/;
    window.fetch = function (input, init) {
      var url = typeof input === "string" ? input : (input && input.url) || "";
      var method = (init && init.method) || (input && input.method) || "GET";
      var p = orig(input, init);
      try {
        if (
          reConfig.test(String(url)) &&
          String(method).toUpperCase() === "POST"
        ) {
          p.then(function (res) {
            try {
              res
                .clone()
                .json()
                .then(function (j) {
                  if (j && j.ok && !window.__THEME_RELOADED_AFTER_SAVE__) {
                    window.__THEME_RELOADED_AFTER_SAVE__ = true;
                    var sel = getConfigThemeSelect();
                    var theme = sel && sel.value;
                    postThemePersist(theme).finally(function () {
                      location.reload();
                    });
                  }
                })
                .catch(function () {
                  /* non-JSON, ignore */
                });
            } catch (e) {
              /* ignore */
            }
          });
        }
      } catch (e) {
        /* ignore */
      }
      return p;
    };
  })();

  (function bindSaveButtonFallback() {
    var btn = document.getElementById("btnSave");
    if (!btn) return;
    btn.addEventListener("click", function () {
      if (window.__THEME_RELOADED_AFTER_SAVE__) return;
      setTimeout(function () {
        if (window.__THEME_RELOADED_AFTER_SAVE__) return;
        var sel = getConfigThemeSelect();
        var theme = sel && sel.value;
        postThemePersist(theme).finally(function () {
          location.reload();
        });
      }, 1500);
    });
  })();

  function boot() {
    if (document.getElementById("configPane")) {
      ensureConfigCss();
      bindConfigPicker();
    }
  }
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
  } else {
    boot();
  }
})();
