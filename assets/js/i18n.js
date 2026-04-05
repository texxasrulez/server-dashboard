(function () {
  "use strict";
  var LOCALE = document.documentElement.getAttribute("lang") || "en";
  var MAP = {};
  function merge(base, extra) {
    var out = {};
    var k;
    base = base && typeof base === "object" ? base : {};
    extra = extra && typeof extra === "object" ? extra : {};
    for (k in base) {
      if (Object.prototype.hasOwnProperty.call(base, k)) out[k] = base[k];
    }
    for (k in extra) {
      if (!Object.prototype.hasOwnProperty.call(extra, k)) continue;
      if (
        out[k] &&
        typeof out[k] === "object" &&
        !Array.isArray(out[k]) &&
        extra[k] &&
        typeof extra[k] === "object" &&
        !Array.isArray(extra[k])
      ) {
        out[k] = merge(out[k], extra[k]);
      } else {
        out[k] = extra[k];
      }
    }
    return out;
  }
  function format(val, vars) {
    if (!vars || typeof vars !== "object") return val;
    return String(val).replace(/\{([^}]+)\}/g, function (m, key) {
      return Object.prototype.hasOwnProperty.call(vars, key) ? vars[key] : m;
    });
  }
  function setText(el, key) {
    var val = t(key);
    if (val == null) return;
    if (el.hasAttribute("data-i18n-attr")) {
      var attr = el.getAttribute("data-i18n-attr");
      el.setAttribute(attr, val);
    } else {
      el.textContent = val;
    }
  }
  function applyDOM() {
    var nodes = document.querySelectorAll("[data-i18n]");
    nodes.forEach(function (n) {
      setText(n, n.getAttribute("data-i18n"));
    });
  }
  function t(key, fallback, vars) {
    if (fallback && typeof fallback === "object" && vars == null) {
      vars = fallback;
      fallback = null;
    }
    var cur = MAP;
    String(key || "")
      .split(".")
      .some(function (k) {
        if (cur && typeof cur === "object" && k in cur) {
          cur = cur[k];
          return false;
        }
        cur = null;
        return true;
      });
    if (cur == null) {
      return format(fallback != null ? fallback : key, vars);
    }
    return format(String(cur), vars);
  }
  window.I18N = {
    t: t,
    apply: applyDOM,
    locale: function () {
      return LOCALE;
    },
    load: load,
  };
  function load(locale) {
    LOCALE = locale || LOCALE;
    var stamp = Date.now();
    var enUrl = "assets/i18n/en.json?_=" + stamp;
    var localeUrl = "assets/i18n/" + LOCALE + ".json?_=" + stamp;
    return fetch(enUrl, { credentials: "same-origin" })
      .then(function (r) {
        return r.ok ? r.json() : {};
      })
      .catch(function () {
        return {};
      })
      .then(function (enMap) {
        if (LOCALE === "en") {
          MAP = enMap || {};
          applyDOM();
          return MAP;
        }
        return fetch(localeUrl, { credentials: "same-origin" })
          .then(function (r) {
            return r.ok ? r.json() : {};
          })
          .catch(function () {
            return {};
          })
          .then(function (localeMap) {
            MAP = merge(enMap || {}, localeMap || {});
            applyDOM();
            return MAP;
          });
      })
      .catch(function () {
        MAP = {};
      });
  }
  if (document.readyState === "loading")
    document.addEventListener("DOMContentLoaded", function () {
      load(LOCALE);
    });
  else load(LOCALE);
})();
