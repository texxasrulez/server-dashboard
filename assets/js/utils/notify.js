/*! Unified Toast (notify.js) — zero-dependency, theme-aware
   Exposes window.toast: { show, info, success, warn, error, loading, promise }
   And window.ToastAuto.init() to wire default UX (page load, clicks, forms, fetch/xhr errors)
*/
(function () {
  if (window.toast && window.toast.__unified) return; // guard against double include

  // Utilities
  const isStr = v => typeof v === "string";
  const clamp = (n,min,max) => Math.max(min, Math.min(max, n));

  // --- toast position: drop-anywhere, configurable ---
function __readToastPosition(){
  try {
    var d = (document.body && (document.body.getAttribute('data-toast-position') || document.body.dataset.toastPosition)) || '';
    if (d) return String(d).toLowerCase();
  } catch(e){}
  try {
    var meta = document.querySelector('meta[name="toast-position"]');
    if (meta && meta.content) return String(meta.content).toLowerCase();
  } catch(e){}
  try {
    var ls = localStorage.getItem('ui.toast_position');
    if (ls) return String(ls).toLowerCase();
  } catch(e){}
  return 'bottom-center';
}
function __applyToastPosition(root){
  if (!root) return;
  var pos = __readToastPosition();
  // Clear first
  root.style.setProperty('top','auto','important');
  root.style.setProperty('right','auto','important');
  root.style.setProperty('bottom','auto','important');
  root.style.setProperty('left','auto','important');
  root.style.setProperty('transform','none','important');
  // Apply
  switch (pos) {
    case 'top-left':
      root.style.setProperty('position','fixed','important');
      root.style.setProperty('top','1rem','important');
      root.style.setProperty('left','1rem','important');
      break;
    case 'top-center':
      root.style.setProperty('position','fixed','important');
      root.style.setProperty('top','1rem','important');
      root.style.setProperty('left','50%','important');
      root.style.setProperty('transform','translateX(-50%)','important');
      break;
    case 'top-right':
      root.style.setProperty('position','fixed','important');
      root.style.setProperty('top','1rem','important');
      root.style.setProperty('right','1rem','important');
      break;
    case 'bottom-left':
      root.style.setProperty('position','fixed','important');
      root.style.setProperty('bottom','1rem','important');
      root.style.setProperty('left','1rem','important');
      break;
    case 'bottom-right':
      root.style.setProperty('position','fixed','important');
      root.style.setProperty('bottom','1rem','important');
      root.style.setProperty('right','1rem','important');
      break;
    case 'bottom-center':
    default:
      root.style.setProperty('position','fixed','important');
      root.style.setProperty('bottom','1rem','important');
      root.style.setProperty('left','50%','important');
      root.style.setProperty('transform','translateX(-50%)','important');
      break;
  }
}

  // Root container
  function ensureRoot() {
    let root = document.getElementById("toast-root");
    if (!root) {
      root = document.createElement("div");
      root.id = "toast-root";
      document.body.appendChild(root);
    }
    
    try { __applyToastPosition(root); } catch(e) {}
    return root;
  }

  const DEFAULT = {
    duration: 3200,
    important: false,
    ariaLive: "polite",
    icon: null, // we render colored dot/spinner by default
    progressBar: true,
    onClick: null,
    position: null
  };

  // Apply position
  function applyPosition(root, pos) {
  // Always fixed and override any stylesheet positioning
  root.style.setProperty('position','fixed','important');
  // Clear previous anchors
  root.style.setProperty('top','auto','important');
  root.style.setProperty('right','auto','important');
  root.style.setProperty('bottom','auto','important');
  root.style.setProperty('left','auto','important');
  // Reset transform unless specific position wants it
  root.style.setProperty('transform','none','important');

  // Full center of screen (both axes)
  if (pos === 'center') {
    root.style.setProperty('top','50%','important');
    root.style.setProperty('left','50%','important');
    root.style.setProperty('transform','translate(-50%, -50%)','important');
    return;
  }

  // Horizontal centers (top/bottom with X-center)
  if (pos === 'top-center') {
    root.style.setProperty('top','16px','important');
    root.style.setProperty('left','50%','important');
    root.style.setProperty('transform','translateX(-50%)','important');
    return;
  }
  if (pos === 'bottom-center') {
    root.style.setProperty('bottom','16px','important');
    root.style.setProperty('left','50%','important');
    root.style.setProperty('transform','translateX(-50%)','important');
    return;
  }

  // Corners
  switch (pos) {
    case 'top-left':
      root.style.setProperty('top','16px','important');
      root.style.setProperty('left','16px','important');
      break;
    case 'top-right':
      root.style.setProperty('top','16px','important');
      root.style.setProperty('right','16px','important');
      break;
    case 'bottom-left':
      root.style.setProperty('bottom','16px','important');
      root.style.setProperty('left','16px','important');
      break;
    case 'bottom-right':
    default:
      root.style.setProperty('bottom','16px','important');
      root.style.setProperty('right','16px','important');
      break;
  }
}

  function el(tag, cls) {
    const e = document.createElement(tag);
    if (cls) e.className = cls;
    return e;
  }

  function removeToast(node) {
    if (!node || !node.parentNode) return;
    node.style.animation = "toast-out 160ms ease-in forwards";
    setTimeout(() => node.remove(), 160);
  }

  function show(message, type="info", opts={}) {
    if (!document.body) return; // before DOM ready
    const o = Object.assign({}, DEFAULT, opts);
    const root = ensureRoot();
    applyPosition(root, o.position || __readToastPosition());

    const t = el("div", "toast toast--" + type + (o.loading ? " toast--loading" : ""));
    t.setAttribute("role", "status");
    t.setAttribute("aria-live", o.ariaLive || "polite");
    t.tabIndex = 0;

    const icon = el("i", "toast__icon");
    const contentWrap = el("div");
    const close = el("button", "toast__close");
    close.setAttribute("aria-label", "Close");
    close.innerHTML = "&times;";

    const title = el("p", "toast__title");
    title.textContent = isStr(o.title) ? o.title : (type === "error" ? "Error" : type === "success" ? "Success" : type === "warn" ? "Warning" : "Notice");

    const desc = el("p", "toast__desc");
    desc.textContent = isStr(message) ? message : JSON.stringify(message);

    contentWrap.appendChild(title);
    contentWrap.appendChild(desc);

    t.appendChild(icon);
    t.appendChild(contentWrap);
    t.appendChild(close);

    let bar, barInner;
    let timeoutId;
    if (o.progressBar && !o.loading) {
      bar = el("div", "toast__bar");
      barInner = el("i");
      bar.appendChild(barInner);
      t.appendChild(bar);
      // animate progress
      const dur = clamp(Number(o.duration) || DEFAULT.duration, 800, 20000);
      // initial full width; then shrink
      requestAnimationFrame(() => {
        barInner.style.width = "100%";
        setTimeout(() => {
          barInner.style.transitionDuration = dur + "ms";
          barInner.style.width = "0%";
        }, 30);
      });
      timeoutId = setTimeout(() => removeToast(t), dur + 60);
    }

    close.addEventListener("click", () => removeToast(t));
    t.addEventListener("click", (ev) => {
      if (o.onClick) try { o.onClick(ev); } catch {}
    });

    // Accessibility: move focus briefly so screen readers announce
    setTimeout(() => { try { t.focus({preventScroll:true}); } catch {} }, 60);

    if (o.important) root.prepend(t); else root.appendChild(t);
    return t;
  }

  function info(msg, opts) { return show(msg, "info", opts); }
  function success(msg, opts) { return show(msg, "success", opts); }
  function warn(msg, opts) { return show(msg, "warn", opts); }
  function error(msg, opts) { return show(msg, "error", Object.assign({ariaLive:"assertive", important:true}, opts||{})); }
  function loading(msg, opts) { return show(msg, "info", Object.assign({loading:true, progressBar:false, duration: 9999999}, opts||{})); }

  function promise(p, {loading:loadingMsg="Working…", success:succ="Done", error:err="Failed"}={}, opts={}) {
    const n = loading(loadingMsg, opts);
    return Promise.resolve(p)
      .then((v) => { removeToast(n); success(isStr(succ) ? succ : (succ && succ(v)) || "Done", opts); return v; })
      .catch((e) => { removeToast(n); error(isStr(err) ? err : (err && err(e)) || (e && e.message) || "Error", opts); throw e; });
  }

  window.toast = { show, info, success, warn, error, loading, promise, __unified: true };

  // --- Auto wiring (opt-in) ---
  const ToastAuto = {
    _initd: false,
    init() {
      if (this._initd) return;
      this._initd = true;
      // Page ready
      if (!document.body?.hasAttribute("data-no-ready-toast")) {
        document.addEventListener("DOMContentLoaded", () => {
          info(document.title ? (document.title + " ready") : "Ready", { duration: 1800 });
        }, { once: true });
      }

      // Buttons & actionable links
      const clickSel = "button, [type=button], [type=submit], a.btn, .btn, a[role=button], [data-toast]";
      document.addEventListener("click", (ev) => {
        const t = ev.target.closest(clickSel);
        if (!t) return;
        // Skip if explicitly disabled
        if (t.matches("[data-toast-skip]")) return;
        const label = t.getAttribute("aria-label") || t.textContent?.trim() || "Working…";
        info(label, { duration: 1200 });
      });

      // Forms
      document.addEventListener("submit", (ev) => {
        const f = ev.target;
        if (!f || f.hasAttribute("data-toast-skip")) return;
        info(f.getAttribute("data-toast-msg") || "Submitting…", { duration: 1500 });
      }, true);

      // Global errors
      window.addEventListener("error", (e) => {
        error(e?.message || "Script error");
      });
      window.addEventListener("unhandledrejection", (e) => {
        const msg = (e && e.reason && (e.reason.message || String(e.reason))) || "Unhandled promise rejection";
        error(msg);
      });

      // fetch / XHR — show ERRORs only (to avoid noise)
      if (window.fetch && !window.fetch.__wrapped_for_toast) {
        const _fetch = window.fetch.bind(window);
        const wrapped = function(...args) {
          return _fetch(...args).then(res => {
            if (!res.ok) {
              error(`Request failed: ${res.status} ${res.statusText || ""}`.trim());
            }
            return res;
          }).catch(err => {
            error(err && err.message ? err.message : "Network error");
            throw err;
          });
        };
        wrapped.__wrapped_for_toast = true;
        window.fetch = wrapped;
      }

      if (window.XMLHttpRequest && !window.XMLHttpRequest.prototype.__wrapped_for_toast) {
        const X = window.XMLHttpRequest;
        const sendOrig = X.prototype.send;
        X.prototype.send = function(...args) {
          this.addEventListener("load", function() {
            try {
              const st = this.status || 0;
              if (st >= 400) error(`Request failed: ${st} ${this.statusText || ""}`.trim());
            } catch {}
          });
          this.addEventListener("error", function() { error("Network error"); });
          return sendOrig.apply(this, args);
        };
        window.XMLHttpRequest.prototype.__wrapped_for_toast = true;
      }
    }
  };
  window.ToastAuto = ToastAuto;

  // Auto-init after DOM ready
  if (document.readyState === "complete" || document.readyState === "interactive") {
    setTimeout(() => ToastAuto.init(), 0);
  } else {
    document.addEventListener("DOMContentLoaded", () => ToastAuto.init(), { once: true });
  }
})();
