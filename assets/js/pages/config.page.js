(function () {
  "use strict";

  function $(s, ctx) {
    return (ctx || document).querySelector(s);
  }
  function $all(s, ctx) {
    return Array.prototype.slice.call((ctx || document).querySelectorAll(s));
  }
  function el(tag, cls) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    return e;
  }
  function isPlainObject(x) {
    return x && typeof x === "object" && !Array.isArray(x);
  }
  function toInt(x) {
    var n = +x;
    return isFinite(n) ? n : 0;
  }
  function tr(key, fallback) {
    try {
      if (window.I18N && typeof window.I18N.t === "function")
        return window.I18N.t(key, fallback);
    } catch (_) {}
    return fallback != null ? fallback : key;
  }
  function prettyLabel(raw) {
    var s = String(raw == null ? "" : raw);
    if (!s) return "";
    var part = s.split(".").pop();
    part = part.replace(/[_-]+/g, " ").replace(/\s+/g, " ").trim();
    return part.replace(/\b[a-z]/g, function (ch) {
      return ch.toUpperCase();
    });
  }
  function labelFor(def, key) {
    var fb =
      def && typeof def.label === "string" && def.label.trim() !== ""
        ? def.label
        : prettyLabel(key);
    return tr("config.fields." + key + ".label", fb);
  }
  function sectionLabel(name, fallback) {
    return tr(
      "config.sections." + String(name || "").toLowerCase(),
      fallback || prettyLabel(name),
    );
  }
  function helpFor(def, key) {
    if (!def || !def.help) return "";
    return tr("config.fields." + key + ".help", def.help);
  }
  function placeholderFor(def, key) {
    if (!def || !def.placeholder) return "";
    return tr("config.fields." + key + ".placeholder", def.placeholder);
  }
  function withCsrf(url) {
    var token = window.__CONFIG_CSRF__ || "";
    var sep = url.indexOf("?") === -1 ? "?" : "&";
    return url + sep + "_csrf=" + encodeURIComponent(token);
  }

  var schema = window.__CONFIG_SCHEMA__ || {};
  var data = window.__CONFIG_DATA__ || {};
  var CSRF = window.__CONFIG_CSRF__ || "";

  // --------- CSS tweaks ---------
  (function () {
    var css = [
      "#configPane.inline-fields .field{display:flex;align-items:center;gap:.75rem;flex-wrap:nowrap}",
      "#configPane.inline-fields .field .label{flex:0 0 260px;min-width:220px}",
      "#configPane.inline-fields .field input[type=text],#configPane.inline-fields .field input[type=password],#configPane.inline-fields .field input[type=number],#configPane.inline-fields .field select,#configPane.inline-fields .field textarea{flex:1 1 auto;min-width:280px}",
      "#configPane .block{border:1px solid rgba(255,255,255,.08);border-radius:.6rem;padding:1rem;margin-top:1rem}",
      "#configPane .block h3{margin:0 0 .5rem 0;font-size:1.05rem;opacity:.9}",
      "#configPane .row.gap-s{gap:.5rem}",
      "#configPane .unit{opacity:.7}",
      "#configPane .pill{display:inline-block;padding:.1rem .5rem;border-radius:999px;font-size:.8em}",
      "#configPane .pill.ok{background:rgba(0,200,0,.15)}",
      "#configPane .pill.fail{background:rgba(200,0,0,.15)}",
      "#configPane .muted{opacity:.7}",
      "#configPane code.inline{padding:.1rem .4rem;border-radius:.35rem;background:rgba(255,255,255,.08)}",
      "#configPane .field.long-text textarea{min-height:80px}",
      "#configPane .acc-row{display:grid;grid-template-columns:auto 1fr 140px 120px auto auto auto;gap:.5rem;align-items:center}",
      "#configPane .acc-row .email{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}",
      "#sendmailWarn{display:none;margin-top:.5rem;padding:.5rem .75rem;border-radius:.5rem;background:rgba(255,180,0,.15);border:1px solid rgba(255,180,0,.35)}",
      "#sendmailWarn strong{margin-right:.5rem}",
      ".status-dot{width:10px;height:10px;border-radius:50%;display:inline-block;margin-right:6px;vertical-align:middle}",
      ".status-ok{background:#1ec41e}.status-fail{background:#d33}.status-unk{background:#999}",
    ].join("\n");
    var s = document.createElement("style");
    s.textContent = css;
    document.head.appendChild(s);
  })();

  var field = {
    string: function (k, def, val) {
      var w = el("div", "field");
      var labelText = labelFor(def, k);
      var lab = el("label", "label");
      lab.textContent = labelText;
      lab.setAttribute("for", k);
      if (/service\s*targets.*extra/i.test(labelText)) {
        w.classList.add("long-text");
        var ta = el("textarea");
        ta.name = k;
        ta.id = k;
        ta.rows = 3;
        ta.value = val == null ? "" : String(val);
        w.append(lab, ta, help(def, k));
        return w;
      }
      var inp = el("input");
      inp.type = "text";
      inp.name = k;
      inp.id = k;
      inp.value = val == null ? "" : String(val);
      var ph = placeholderFor(def, k);
      if (ph) inp.placeholder = ph;
      if (def.max) inp.maxLength = +def.max;
      w.append(lab, inp, help(def, k));
      return w;
    },
    text: function (k, def, val) {
      var w = el("div", "field");
      var lab = el("label", "label");
      lab.textContent = labelFor(def, k);
      lab.setAttribute("for", k);
      var ta = el("textarea");
      ta.name = k;
      ta.id = k;
      ta.rows = def.rows || 4;
      ta.value = val == null ? "" : String(val);
      var tph = placeholderFor(def, k);
      if (tph) ta.placeholder = tph;
      w.append(lab, ta, help(def, k));
      return w;
    },
    url: function (k, def, val) {
      return field.string(k, def, val);
    },
    email: function (k, def, val) {
      return field.string(k, def, val);
    },
    secret: function (k, def, val) {
      var w = el("div", "field");
      var lab = el("label", "label");
      lab.textContent = labelFor(def, k);
      lab.setAttribute("for", k);
      var inp = el("input");
      inp.type = "text";
      inp.name = k;
      inp.id = k;
      inp.value = val == null ? "" : String(val);
      w.append(lab, inp, help(def, k));
      return w;
    },
    int: function (k, def, val) {
      var w = el("div", "field");
      var lab = el("label", "label");
      lab.textContent = labelFor(def, k);
      lab.setAttribute("for", k);
      var box = el("div", "row middle gap-s");
      var inp = el("input");
      inp.type = "number";
      inp.name = k;
      inp.id = k;
      inp.step = String(def.step || 1);
      if (typeof def.min === "number") inp.min = def.min;
      if (typeof def.max === "number") inp.max = def.max;
      if (val === 0 || typeof val === "number") inp.value = String(val);
      var unit = def.unit ? el("span", "unit") : null;
      if (unit) unit.textContent = def.unit;
      box.append(inp);
      if (unit) box.append(unit);
      w.append(lab, box, help(def, k));
      return w;
    },
    number: function (k, def, val) {
      var d = Object.assign({}, def);
      d.step = d.step || "any";
      return field.int(k, d, val);
    },
    bool: function (k, def, val) {
      var w = el("div", "field row middle gap-s");
      var inp = el("input");
      inp.type = "checkbox";
      inp.name = k;
      inp.id = k;
      inp.checked = !!val;
      var lab = el("label", "label");
      lab.textContent = labelFor(def, k);
      lab.setAttribute("for", k);
      w.append(inp, lab, help(def, k));
      return w;
    },
    enum: function (k, def, val) {
      var w = el("div", "field");
      var lab = el("label", "label");
      lab.textContent = labelFor(def, k);
      lab.setAttribute("for", k);
      var sel = el("select");
      sel.name = k;
      sel.id = k;
      (def.values || []).forEach(function (v) {
        var text = (def.value_labels && def.value_labels[v]) || v;
        var opt = el("option");
        opt.value = v;
        opt.textContent = text;
        if (v === val) opt.selected = true;
        sel.appendChild(opt);
      });
      w.append(lab, sel, help(def, k));
      return w;
    },
  };
  function help(def, key) {
    var msg = helpFor(def, key);
    if (!msg) return el("span", "help none");
    var s = el("div", "help muted");
    s.textContent = msg;
    return s;
  }

  var ACTIVE_KEY = "config.active_section";
  var tabsEl =
    document.getElementById("configTabs") ||
    (function () {
      var main = document.querySelector("main") || document.body;
      var wrap = el("div", "config-wrap");
      var t = el("div", "tabs");
      t.id = "configTabs";
      var p = el("div", "panel");
      p.id = "configPane";
      wrap.append(t, p);
      main.append(wrap);
      return t;
    })();
  var paneEl = document.getElementById("configPane") || $("#configPane");

  function sections() {
    var a = Object.keys(schema).filter(function (k) {
      return k[0] !== "_" && isPlainObject(schema[k]);
    });
    Object.keys(data).forEach(function (k) {
      if (a.indexOf(k) < 0 && isPlainObject(data[k])) a.push(k);
    });
    a = a.filter(function (k) {
      return k.toLowerCase() !== "cron";
    }); // hide Cron tab
    return a;
  }

  function renderTabs() {
    tabsEl.innerHTML = "";
    tabsEl.classList.add("row", "wrap", "gap");
    var list = sections();
    var remembered = String(
      localStorage.getItem(ACTIVE_KEY) || "",
    ).toLowerCase();
    var first =
      remembered && list.indexOf(remembered) >= 0 ? remembered : list[0] || "";
    list.forEach(function (name) {
      var label = sectionLabel(
        name,
        (schema[name] && schema[name]._label) || prettyLabel(name),
      );
      var btn = el("button", "btn secondary");
      btn.dataset.section = name;
      btn.textContent = label;
      btn.addEventListener("click", function () {
        activate(name);
      });
      tabsEl.appendChild(btn);
    });
    activate(first);
  }

  function activate(name) {
    $all("button[data-section]", tabsEl).forEach(function (b) {
      var on = b.dataset.section === name;
      b.classList.toggle("primary", on);
      b.classList.toggle("secondary", !on);
      b.setAttribute("aria-pressed", on ? "true" : "false");
    });
    localStorage.setItem(ACTIVE_KEY, name);
    renderSection(name);
  }

  function renderSection(name) {
    paneEl.innerHTML = "";
    var inline = /^(security|server_tests|logs)$/i.test(name);
    paneEl.classList.toggle("inline-fields", inline);
    var secSchema = schema[name] || {};
    var secData =
      data[name] && typeof data[name] === "object" ? data[name] : {};
    renderObject(
      name,
      secSchema,
      secData,
      paneEl,
      sectionLabel(name, secSchema._label || prettyLabel(name)),
      inline,
    );
    builtinEnhancers(name, paneEl);
  }

  function renderObject(prefix, objSchema, objData, container, legend, inline) {
    var keys = Object.keys(objSchema).filter(function (k) {
      return k !== "_label";
    });
    Object.keys(objData || {}).forEach(function (k) {
      if (keys.indexOf(k) < 0) keys.push(k);
    });
    var fs = el("fieldset", "fieldset block");
    if (legend) {
      var lg = el("h3");
      lg.textContent = legend;
      fs.appendChild(lg);
    }
    container.appendChild(fs);

    keys.forEach(function (k) {
      var def = objSchema[k];
      var val = objData ? objData[k] : undefined;
      var path = prefix + "." + k;
      if (def == null) {
        def = guessDefFromValue(k, val);
      }
      if (isPlainObject(def) && def.hidden === true) {
        return;
      }
      if (isPlainObject(def) && !def.type) {
        var label = sectionLabel(path, def._label || prettyLabel(k));
        renderObject(
          path,
          def,
          isPlainObject(val) ? val : {},
          fs,
          label,
          inline,
        );
        return;
      }
      var type = String((def && def.type) || "string").toLowerCase();
      var renderer = field[type] || field.string;
      var node;
      try {
        node = renderer(path, def || {}, val);
      } catch (e) {
        console.error("Render error for", path, e);
        node = field.string(
          path,
          { label: (def && def.label) || k + " (fallback)" },
          val == null ? "" : String(val),
        );
      }
      fs.appendChild(node);
    });
  }

  function guessDefFromValue(k, val) {
    if (typeof val === "boolean")
      return { type: "bool", label: prettyLabel(k) };
    if (typeof val === "number") return { type: "int", label: prettyLabel(k) };
    if (Array.isArray(val)) return { type: "list", label: prettyLabel(k) };
    if (isPlainObject(val)) return { type: null, _label: prettyLabel(k) };
    return { type: "string", label: prettyLabel(k) };
  }

  function pathGet(path) {
    var parts = String(path || "").split(".");
    var t = data;
    for (var i = 0; i < parts.length; i++) {
      var p = parts[i];
      if (!t || typeof t !== "object") return undefined;
      t = t[p];
    }
    return t;
  }

  function collectCurrent() {
    var out = {};
    $all("[name]", paneEl).forEach(function (inp) {
      var name = inp.name;
      if (!name) return;
      var parts = name.split(".");
      var t = out;
      for (var i = 0; i < parts.length; i++) {
        var k = parts[i];
        if (i === parts.length - 1) {
          var v;
          if (inp.type === "checkbox") v = !!inp.checked;
          else if (inp.type === "hidden") {
            if (inp.dataset.keepString === "1") v = inp.value;
            else if (
              inp.value &&
              (inp.value[0] === "[" || inp.value[0] === "{")
            ) {
              try {
                v = JSON.parse(inp.value);
              } catch (_) {
                v = [];
              }
            } else v = inp.value;
          } else if (inp.type === "number")
            v = inp.value === "" ? null : +inp.value;
          else v = inp.value;
          t[k] = v;
        } else {
          if (!t[k] || typeof t[k] !== "object") t[k] = {};
          t = t[k];
        }
      }
    });
    return out;
  }

  function wireButtons() {
    var save = $("#btnSave");
    var reset = $("#btnReset");
    if (save)
      save.addEventListener("click", function () {
        var partial = collectCurrent();
        var payload = { _csrf: CSRF, settings: {} };
        Object.keys(partial).forEach(function (top) {
          payload.settings[top] = partial[top];
        });
        fetch("config.php", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(payload),
        })
          .then(function (r) {
            return r.json().catch(function () {
              return { ok: false, error: "Bad JSON" };
            });
          })
          .then(function (res) {
            if (res && res.ok) {
              window.toast?.success?.("Configuration saved.");
              Object.keys(payload.settings).forEach(function (top) {
                data[top] = Object.assign(
                  {},
                  data[top] || {},
                  payload.settings[top],
                );
              });
              // trigger notifier reloads
              if (payload.settings.email || payload.settings.mail) {
                try {
                  window.dispatchEvent(
                    new CustomEvent("email-accounts-changed"),
                  );
                } catch (_) {}
                try {
                  window.EmailIndicator &&
                    window.EmailIndicator.reload &&
                    window.EmailIndicator.reload();
                } catch (_) {}
                try {
                  window.EMAIL_REFRESH && window.EMAIL_REFRESH();
                } catch (_) {}
              }
              // background backup disabled: backups are created only when the user clicks the button
            } else {
              window.toast?.error?.(res?.error || "Save failed");
            }
          })
          .catch(function (e) {
            window.toast?.error?.("Network error: " + e.message);
          });
      });
    if (reset)
      reset.addEventListener("click", function () {
        renderTabs();
        window.toast?.info?.("Form reset");
      });
  }

  document.addEventListener("DOMContentLoaded", function () {
    renderTabs();
    wireButtons();
  });

  // ---------- Built-in enhancers ----------
  function builtinEnhancers(section, pane) {
    // Alerts tab: show hints for quick mute presets + latency defaults
    if (String(section || "").toLowerCase() === "alerts" && pane) {
      try {
        var presetInput = pane.querySelector('[name="alerts.mute_presets"]');
        if (presetInput) {
          var host = presetInput.closest(".field") || presetInput.parentElement;
          if (host && !host.querySelector('[data-role="preset-preview"]')) {
            var preview = el("div", "muted small");
            preview.dataset.role = "preset-preview";
            var raw =
              presetInput.value ||
              (data.alerts && data.alerts.mute_presets) ||
              "";
            var list = String(raw || "")
              .split(/[\s,]+/)
              .map(function (n) {
                return parseInt(n, 10) || 0;
              })
              .filter(function (n) {
                return n > 0;
              });
            preview.textContent = list.length
              ? "Quick mute buttons will offer: " +
                list
                  .map(function (n) {
                    return n + "m";
                  })
                  .join(", ")
              : "Add minutes separated by commas to enable quick mute buttons on Alerts.";
            host.appendChild(preview);
          }
        }
        ["latency_warn_ms", "latency_fail_ms"].forEach(function (key) {
          var input = pane.querySelector(
            '[name="alerts.service_defaults.' + key + '"]',
          );
          if (!input || input.dataset.hinted) return;
          var wrap = input.closest(".field") || input.parentElement;
          if (!wrap) return;
          var note = el("div", "muted small");
          note.dataset.role = "latency-default-note";
          note.textContent =
            "Used to prefill latency alert thresholds and helper text.";
          wrap.appendChild(note);
          input.dataset.hinted = "1";
        });
      } catch (_) {}
    }
    // --- Site: Backup & Restore + Retention (in-core, unified) ---
    try {
      if (String(section || "").toLowerCase() === "site" && pane) {
        // Avoid dupes across re-renders
        if (!pane.querySelector('[data-block="import-export"]')) {
          var blk = el("div", "block");
          blk.dataset.block = "import-export";
          var h = el("h3");
          h.textContent = tr("config.ui.site_backup.title", "Backup & Restore");
          blk.appendChild(h);
          var row = el("div", "row gap-s");
          var exportBtn = el("button", "btn");
          exportBtn.type = "button";
          exportBtn.textContent = tr(
            "config.ui.site_backup.export_config",
            "Export Config",
          );
          var redactLabel = el("label", "muted");
          redactLabel.style.display = "inline-flex";
          redactLabel.style.alignItems = "center";
          redactLabel.style.gap = ".35rem";
          var redactChk = el("input");
          redactChk.type = "checkbox";
          redactChk.checked = true;
          redactChk.setAttribute(
            "aria-label",
            tr("config.ui.site_backup.redact_secrets", "Redact secrets"),
          );
          redactLabel.appendChild(redactChk);
          redactLabel.appendChild(
            document.createTextNode(
              tr("config.ui.site_backup.redact_secrets", "Redact secrets"),
            ),
          );
          exportBtn.addEventListener("click", function () {
            var url =
              "api/config_export.php?_csrf=" + encodeURIComponent(CSRF || "");
            if (redactChk.checked) url += "&redact=1";
            window.location.href = url;
          });
          var file = el("input");
          file.type = "file";
          file.accept = "application/json,.json";
          var importBtn = el("button", "btn secondary");
          importBtn.type = "button";
          importBtn.textContent = tr(
            "config.ui.site_backup.import_config",
            "Import Config",
          );
          importBtn.addEventListener("click", function () {
            if (!file.files || !file.files[0]) {
              (window.toast && toast.warn) || alert;
              toast &&
                toast.warn &&
                toast.warn(
                  tr(
                    "config.ui.site_backup.choose_file_first",
                    "Choose a backup file first",
                  ),
                );
              return;
            }
            var fd = new FormData();
            fd.append("file", file.files[0]);
            fd.append("_csrf", CSRF || "");
            fetch("api/config_import.php", { method: "POST", body: fd })
              .then(function (r) {
                return r.json().catch(function () {
                  return null;
                });
              })
              .then(function (j) {
                if (j && j.ok) {
                  window.toast &&
                    toast.success &&
                    toast.success(
                      tr(
                        "config.ui.site_backup.imported_reloading",
                        "Imported. Reloading...",
                      ),
                    );
                  setTimeout(function () {
                    location.reload();
                  }, 700);
                } else {
                  throw new Error(
                    (j && j.error) ||
                      tr(
                        "config.ui.site_backup.import_failed",
                        "Import failed",
                      ),
                  );
                }
              })
              .catch(function (e) {
                window.toast &&
                  toast.error &&
                  toast.error(
                    tr(
                      "config.ui.site_backup.import_failed_prefix",
                      "Import failed: ",
                    ) +
                      ((e && e.message) || tr("config.ui.unknown", "unknown")),
                  );
              });
          });
          row.append(exportBtn, redactLabel, file, importBtn);
          blk.appendChild(row);
          pane.appendChild(blk);
        }
        if (!pane.querySelector('[data-block="retention"]')) {
          var blk2 = el("div", "block");
          blk2.dataset.block = "retention";
          var h2 = el("h3");
          h2.textContent = tr(
            "config.ui.site_backup.retention_title",
            "Backups - Retention",
          );
          blk2.appendChild(h2);
          var row2 = el("div", "row gap-s");

          // persisted keep
          var keepDefault = 20;
          try {
            if (data && data.site && typeof data.site.backup_keep === "number")
              keepDefault = data.site.backup_keep;
          } catch (_) {}
          var keepLbl = el("label");
          keepLbl.textContent = tr(
            "config.ui.site_backup.keep_last",
            "Keep last",
          );
          keepLbl.className = "muted";
          var keep = el("input");
          keep.type = "number";
          keep.min = "5";
          keep.max = "200";
          keep.value = String(keepDefault);
          keep.name = "site.backup_keep";
          var keepLbl2 = el("span");
          keepLbl2.textContent = tr("config.ui.site_backup.files", "files");
          keepLbl2.className = "muted";

          var stats = el("span");
          stats.className = "muted";
          stats.textContent = tr(
            "config.ui.site_backup.loading_stats",
            "(loading stats...)",
          );
          var latestInfo = el("div", "muted");
          latestInfo.style.marginTop = ".4rem";
          latestInfo.textContent = tr(
            "config.ui.site_backup.last_backup_loading",
            "Last backup: (loading...)",
          );

          function humanSize(bytes) {
            if (!bytes && bytes !== 0) return "";
            var units = ["B", "KB", "MB", "GB", "TB"];
            var n = Math.abs(bytes);
            var u = 0;
            while (n >= 1024 && u < units.length - 1) {
              n /= 1024;
              u++;
            }
            return (
              (bytes === 0 ? "0" : n.toFixed(n >= 10 || u === 0 ? 0 : 1)) +
              " " +
              units[u]
            );
          }
          function refreshStats() {
            fetch(withCsrf("api/config_backup.php?stats=1"), {
              credentials: "same-origin",
            })
              .then(function (r) {
                return r.json().catch(function () {
                  return null;
                });
              })
              .then(function (j) {
                if (!j || !j.ok) throw new Error("stats failed");
                var cnt = j.count != null ? j.count : "?";
                var sz = j.total_size_human || j.total_size || "";
                var dir = j.backup_dir || "config/backups";
                stats.textContent =
                  "(" +
                  dir +
                  " · " +
                  cnt +
                  " file" +
                  (cnt == 1 ? "" : "s") +
                  " / " +
                  sz +
                  ")";
              })
              .catch(function () {
                stats.textContent = "";
              });
          }
          function refreshLatest() {
            fetch(withCsrf("api/config_backup.php?latest=1"), {
              credentials: "same-origin",
            })
              .then(function (r) {
                return r.json().catch(function () {
                  return null;
                });
              })
              .then(function (j) {
                if (!j || !j.ok) {
                  throw new Error("latest failed");
                }
                if (!j.exists) {
                  latestInfo.textContent = tr(
                    "config.ui.site_backup.last_backup_none",
                    "Last backup: none yet",
                  );
                  return;
                }
                var whenStr = j.mtime
                  ? new Date(j.mtime * 1000).toLocaleString()
                  : "";
                var sizeStr = j.size != null ? humanSize(j.size) : "";
                var text =
                  tr(
                    "config.ui.site_backup.last_backup_prefix",
                    "Last backup: ",
                  ) + (j.file || tr("config.ui.unknown_paren", "(unknown)"));
                if (sizeStr) text += " · " + sizeStr;
                if (whenStr) text += " · " + whenStr;
                latestInfo.textContent = text;
              })
              .catch(function () {
                latestInfo.textContent = tr(
                  "config.ui.site_backup.last_backup_unknown",
                  "Last backup: unknown",
                );
              });
          }
          refreshStats();
          refreshLatest();

          var pruneBtn = el("button", "btn");
          pruneBtn.type = "button";
          pruneBtn.textContent = tr(
            "config.ui.site_backup.prune_now",
            "Prune now",
          );
          pruneBtn.addEventListener("click", function () {
            var v = Math.max(
              5,
              Math.min(200, parseInt(keep.value || "20", 10)),
            );
            fetch(
              withCsrf(
                "api/config_backup.php?prune=1&keep=" + encodeURIComponent(v),
              ),
              { method: "POST" },
            )
              .then(function (r) {
                return r.json().catch(function () {
                  return null;
                });
              })
              .then(function (j) {
                if (j && j.ok) {
                  window.toast &&
                    toast.success &&
                    toast.success(
                      tr(
                        "config.ui.site_backup.pruned_kept_deleted",
                        "Pruned - kept ",
                      ) +
                        (j.keep || "?") +
                        ", " +
                        tr("config.ui.site_backup.deleted_prefix", "deleted ") +
                        (j.deleted || 0),
                    );
                  refreshStats();
                } else {
                  throw new Error(
                    (j && j.error) ||
                      tr("config.ui.site_backup.prune_failed", "Prune failed"),
                  );
                }
              })
              .catch(function (e) {
                window.toast &&
                  toast.error &&
                  toast.error(
                    tr(
                      "config.ui.site_backup.prune_failed_prefix",
                      "Prune failed: ",
                    ) +
                      ((e && e.message) || tr("config.ui.unknown", "unknown")),
                  );
              });
          });

          var mkBtn = el("button", "btn");
          mkBtn.type = "button";
          mkBtn.textContent = tr(
            "config.ui.site_backup.create_backup_now",
            "Create backup now",
          );
          mkBtn.addEventListener("click", function () {
            fetch(withCsrf("api/config_backup.php"), { method: "POST" })
              .then(function (r) {
                return r.json().catch(function () {
                  return null;
                });
              })
              .then(function (j) {
                if (j && j.ok) {
                  window.toast &&
                    toast.success &&
                    toast.success(
                      tr(
                        "config.ui.site_backup.backup_saved_prefix",
                        "Backup saved: ",
                      ) + (j.path || ""),
                    );
                  refreshStats();
                } else {
                  throw new Error(
                    (j && j.error) ||
                      tr(
                        "config.ui.site_backup.backup_failed",
                        "Backup failed",
                      ),
                  );
                }
              })
              .catch(function (e) {
                window.toast &&
                  toast.error &&
                  toast.error(
                    tr(
                      "config.ui.site_backup.backup_failed_prefix",
                      "Backup failed: ",
                    ) +
                      ((e && e.message) || tr("config.ui.unknown", "unknown")),
                  );
              });
          });

          var dlBtn = el("button", "btn");
          dlBtn.type = "button";
          dlBtn.textContent = tr(
            "config.ui.site_backup.download_latest_backup",
            "Download latest backup",
          );
          dlBtn.addEventListener("click", function () {
            fetch(withCsrf("api/config_backup.php?latest=1"), {
              credentials: "same-origin",
            })
              .then(function (r) {
                return r.json().catch(function () {
                  return null;
                });
              })
              .then(function (j) {
                if (j && j.ok && j.exists) {
                  window.location.href = withCsrf(
                    "api/config_backup.php?download_latest=1",
                  );
                } else {
                  throw new Error(
                    tr(
                      "config.ui.site_backup.no_backups_found",
                      "No backups found",
                    ),
                  );
                }
              })
              .catch(function (e) {
                window.toast &&
                  toast.error &&
                  toast.error(
                    tr(
                      "config.ui.site_backup.download_failed_prefix",
                      "Download failed: ",
                    ) +
                      ((e && e.message) || tr("config.ui.unknown", "unknown")),
                  );
              });
          });

          row2.append(keepLbl, keep, keepLbl2, stats, pruneBtn, mkBtn, dlBtn);
          blk2.appendChild(row2);
          blk2.appendChild(latestInfo);
          pane.appendChild(blk2);
        }
      }
    } catch (_) {}
    // --- end Site: Backup & Restore + Retention ---

    if (
      String(section || "").toLowerCase() === "email" ||
      String(section || "").toLowerCase() === "mail"
    ) {
      // Remove schema-rendered "(managed by UI) accounts" field
      var dead =
        $('[name="email.accounts"]') ||
        $('[name="mail.accounts"]') ||
        $('[name="email[accounts]"]');
      if (dead && dead.closest) {
        var host = dead.closest(".field");
        if (host && host.parentNode) host.parentNode.removeChild(host);
      }

      // Status bar (pulls from email_status.php?verbose=1)
      var banner = el("div", "block");
      banner.innerHTML =
        '<h3>Notifier Status</h3><div id="acctStatus" class="col"></div>';
      pane.appendChild(banner);

      function statusDot(kind) {
        var d = el(
          "span",
          "status-dot " +
            (kind === "ok"
              ? "status-ok"
              : kind === "fail"
                ? "status-fail"
                : "status-unk"),
        );
        return d;
      }
      function classify(ac) {
        if (ac && (ac.ok === false || ac.unseen == null)) return "fail";
        if (ac && typeof ac.unseen === "number") return "ok";
        return "unk";
      }
      function loadStatus() {
        fetch("api/email_status.php?verbose=1&_=" + Date.now(), {
          credentials: "same-origin",
          cache: "no-store",
        })
          .then(function (r) {
            return r.json().catch(function () {
              return null;
            });
          })
          .then(function (j) {
            var h = $("#acctStatus");
            if (!h) return;
            h.innerHTML = "";
            var accounts = (j && j.accounts) || [];
            accounts.forEach(function (ac) {
              var row = el("div", "row middle gap-s");
              row.append(statusDot(classify(ac)));
              var t = el("span");
              t.textContent =
                (ac.address || "account") +
                " — " +
                (ac.unseen == null ? "unknown" : String(ac.unseen) + " unseen");
              row.append(t);
              if (
                String(ac.provider || "").toLowerCase() === "google" ||
                String(ac.provider || "").toLowerCase() === "gmail"
              ) {
                var rc = el("button", "btn secondary");
                rc.type = "button";
                rc.textContent = "Reconnect Google";
                rc.addEventListener("click", function () {
                  startConnect(ac.address || "", "google");
                });
                row.append(rc);
                // Token checker (Google-only) — green/red dot + last modified
                var tk = el("span", "muted");
                tk.style.marginLeft = "8px";
                tk.textContent = "token: …";
                var dot = el("span");
                dot.className =
                  (dot.className || "") + " status-dot status-unk";
                dot.style.marginLeft = "6px";
                row.append(dot);
                row.append(tk);
                (function (address, provider) {
                  if (!address) return;
                  var url =
                    "api/email_token_info.php?provider=" +
                    encodeURIComponent(String(provider || "google")) +
                    "&address=" +
                    encodeURIComponent(address);
                  fetch(url, { credentials: "same-origin" })
                    .then(function (r) {
                      return r.json().catch(function () {
                        return null;
                      });
                    })
                    .then(function (j) {
                      if (!j) return;
                      if (j.exists) {
                        dot.classList.remove("status-unk", "status-fail");
                        dot.classList.add("status-ok");
                        var when = j.mtime_iso
                          ? j.mtime_iso.replace("T", " ").replace("Z", " UTC")
                          : j.mtime
                            ? String(j.mtime)
                            : "";
                        tk.textContent =
                          "token: " +
                          when +
                          (j.size
                            ? " · " + (Math.round(j.size / 1024) || 1) + " KB"
                            : "");
                      } else {
                        dot.classList.remove("status-unk", "status-ok");
                        dot.classList.add("status-fail");
                        tk.textContent = "token: missing";
                      }
                    })
                    .catch(function () {
                      /* ignore */
                    });
                })(ac.address || "", ac.provider || "google");
              }
              h.appendChild(row);
            });
          })
          .catch(function () {
            /* ignore */
          });
      }
      loadStatus();

      // Send Test
      var blk2 = el("div", "block");
      var h2 = el("h3");
      h2.textContent = "Send Test Email";
      var row2 = el("div", "row gap-s");
      var inpTo = el("input");
      inpTo.type = "text";
      inpTo.placeholder = "recipient@example.com";
      var prefill =
        data.mail && (data.mail.mail_from || data.mail.mail_replyto)
          ? data.mail.mail_from || data.mail.mail_replyto
          : pathGet("email.email") || pathGet("site.email") || "";
      if (prefill) inpTo.value = prefill;
      var btn = el("button", "btn primary");
      btn.type = "button";
      btn.textContent = "Send Test";
      btn.addEventListener("click", function () {
        var to = inpTo.value.trim();
        if (!to) return alert("Enter recipient address");
        var transportSel =
          $('[name="email.transport"]') ||
          $('[name="mail.transport"]') ||
          $('[name$=".transport"]');
        var transport = transportSel
          ? (transportSel.value || "").toLowerCase()
          : "";
        if (transport === "sendmail") {
          window.toast?.error?.(
            'Send Test disabled: "sendmail" transport requires popen(). Use SMTP or phpmail.',
          );
          return;
        }
        sendTestEmail(to, { verbose: true });
      });
      row2.append(inpTo, btn);
      blk2.append(h2, row2);
      pane.appendChild(blk2);

      // Accounts editor (forced email.accounts)
      buildEmailAccountsEditor(section, pane);

      // ---- SMTP Diagnose: INLINE ONLY (right of name="mail.smtp_host") ----
      // Cleanup: remove any legacy "SMTP Diagnose" block/card that may have been injected previously
      try {
        if (pane) {
          var killers = Array.prototype.slice.call(
            pane.querySelectorAll(".card, .block"),
          );
          killers.forEach(function (k) {
            var h = k.querySelector("h3,h4");
            if (h && /smtp\s*diagnose/i.test(h.textContent || "")) {
              k.remove();
            }
          });
          // also remove any fallback we may have tagged
          pane
            .querySelectorAll('[data-block="smtp-diagnose"]')
            .forEach(function (n) {
              n.remove();
            });
        }
      } catch (_) {}

      // Create single inline Diagnose button next to the SMTP host field
      try {
        // Avoid duplicates across re-renders
        var existingBtn = document.querySelector('[data-role="smtp-diagnose"]');
        if (!existingBtn) {
          var hostInput = document.querySelector('[name="mail.smtp_host"]');
          if (hostInput) {
            var btnDiag = el("button", "btn secondary");
            btnDiag.type = "button";
            btnDiag.textContent = "Diagnose";
            btnDiag.setAttribute("data-role", "smtp-diagnose");
            btnDiag.style.marginLeft = "8px";
            btnDiag.style.verticalAlign = "middle";

            btnDiag.addEventListener("click", function () {
              function getByName(n) {
                return document.querySelector('[name="' + n + '"]');
              }
              function val(n) {
                return n ? n.value || "" : "";
              }

              var host =
                val(getByName("mail.smtp_host")) ||
                val(document.querySelector('[name*="smtp_host"]'));
              var port =
                val(getByName("mail.smtp_port")) ||
                val(document.querySelector('[name*="smtp_port"]'));
              var secure =
                val(getByName("mail.smtp_secure")) ||
                val(document.querySelector('[name*="smtp_secure"]'));
              var user =
                val(getByName("mail.smtp_user")) ||
                val(document.querySelector('[name*="smtp_user"]'));
              var pass =
                val(getByName("mail.smtp_password")) ||
                val(document.querySelector('[name*="smtp_password"]'));
              var transport =
                val(getByName("mail.transport")) ||
                val(document.querySelector('[name*="transport"]'));

              if (!host) {
                window.toast &&
                  window.toast.warn &&
                  window.toast.warn("SMTP Diagnose: host is empty");
                return;
              }

              var payload = { _csrf: CSRF, host: host };
              if (port) payload.port = port;
              if (secure) payload.secure = secure;
              if (transport) payload.transport = transport;
              if (user) payload.user = user;
              if (pass) payload.pass = pass;

              fetch("api/smtp_probe.php", {
                method: "POST",
                credentials: "same-origin",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify(payload),
              })
                .then(function (r) {
                  return r.json().catch(function () {
                    return null;
                  });
                })
                .then(function (j) {
                  if (!j) {
                    window.toast &&
                      window.toast.error &&
                      window.toast.error("SMTP Diagnose: network/parse error");
                    return;
                  }
                  var ok = j.ok;
                  var bits = [];
                  if (j.connect) {
                    bits.push(
                      "connect: " +
                        (j.connect.ok ? "ok" : "fail") +
                        (j.connect.code ? " [" + j.connect.code + "]" : "") +
                        (j.connect.error ? " — " + j.connect.error : ""),
                    );
                  }
                  if (j.ehlo) {
                    bits.push(
                      "ehlo: " +
                        (j.ehlo.ok ? "ok" : "fail") +
                        (j.ehlo.code ? " [" + j.ehlo.code + "]" : "") +
                        (j.ehlo.error ? " — " + j.ehlo.error : ""),
                    );
                  }
                  if (j.starttls) {
                    bits.push(
                      "starttls: " +
                        (j.starttls.ok ? "ok" : "fail") +
                        (j.starttls.code ? " [" + j.starttls.code + "]" : "") +
                        (j.starttls.error ? " — " + j.starttls.error : ""),
                    );
                  }
                  if (j.tls) {
                    bits.push(
                      "tls: " +
                        (j.tls.ok ? "ok" : "fail") +
                        (j.tls.error ? " — " + j.tls.error : ""),
                    );
                  }
                  if (j.ehlo_post_tls) {
                    bits.push(
                      "ehlo(tls): " +
                        (j.ehlo_post_tls.ok ? "ok" : "fail") +
                        (j.ehlo_post_tls.code
                          ? " [" + j.ehlo_post_tls.code + "]"
                          : "") +
                        (j.ehlo_post_tls.error
                          ? " — " + j.ehlo_post_tls.error
                          : ""),
                    );
                  }
                  if (j.auth) {
                    bits.push(
                      "auth: " +
                        (j.auth.ok ? "ok" : "fail") +
                        (j.auth.code ? " [" + j.auth.code + "]" : "") +
                        (j.auth.error ? " — " + j.auth.error : ""),
                    );
                  }
                  if (j.recommendation) {
                    bits.push(j.recommendation);
                  }
                  var msg = "SMTP Diagnose — " + bits.join(" | ");
                  if (ok) {
                    window.toast &&
                      window.toast.success &&
                      window.toast.success(msg);
                  } else {
                    window.toast &&
                      window.toast.error &&
                      window.toast.error(msg);
                  }
                })
                .catch(function (e) {
                  window.toast &&
                    window.toast.error &&
                    window.toast.error("SMTP Diagnose: " + e.message);
                });
            });

            hostInput.insertAdjacentElement("afterend", btnDiag);
          }
          // No fallback block/card by design.
        }
      } catch (_) {}
      // ---- END SMTP Diagnose INLINE ONLY ----
    }

    if (section.toLowerCase() === "history") {
      // Cron Health (Refresh only) with cooldown
      var blk4 = el("div", "block");
      var h4 = el("h3");
      h4.textContent = "Cron Health";
      blk4.appendChild(h4);
      var wrap = el("div");
      blk4.appendChild(wrap);

      function pathGetLocal(path) {
        var parts = String(path || "").split(".");
        var t = data;
        for (var i = 0; i < parts.length; i++) {
          var p = parts[i];
          if (!t || typeof t !== "object") return undefined;
          t = t[p];
        }
        return t;
      }
      function findToken() {
        var v = pathGetLocal("alerts.cron_token");
        if (typeof v === "string" && v) return v;
        var c = [
          "site.cron_token",
          "cron.token",
          "security.cron_token",
          "alerts.token",
          "history.token",
          "api.cron_token",
        ];
        for (var i = 0; i < c.length; i++) {
          var x = pathGetLocal(c[i]);
          if (typeof x === "string" && x) return x;
        }
        return null;
      }
      function when(v) {
        if (v == null) return null;
        if (typeof v === "number") return v * (v > 1e12 ? 1 : 1000);
        var t = Date.parse(v);
        return isFinite(t) ? t : null;
      }
      function pill(ok) {
        var s = el("span", "pill " + (ok ? "ok" : "fail"));
        s.textContent = ok ? "OK" : "FAIL";
        return s;
      }

      var now = Date.now();
      function pick(objName, keys) {
        for (var i = 0; i < keys.length; i++) {
          var k = objName + "." + keys[i];
          var v = pathGetLocal(k);
          if (v != null) return v;
        }
        return null;
      }
      var alertsLast = when(
        pick("alerts", [
          "last_run",
          "last_run_at",
          "last_mark",
          "last_epoch",
          "last_ms",
          "ts",
        ]),
      );
      var alertsEvery = toInt(
        pick("alerts", [
          "every_min",
          "interval_min",
          "scan_interval_min",
          "expected_every_min",
        ]),
      );
      var histLast = when(
        pick("history", [
          "last_append",
          "last_run",
          "last_run_at",
          "last_epoch",
          "last_ms",
          "ts",
        ]),
      );
      var histEvery = toInt(
        pick("history", [
          "append_every_min",
          "every_min",
          "expected_every_min",
        ]),
      );
      var token = findToken();

      function makeUrl(what, extra) {
        try {
          var u = new URL("api/cron_mark.php", window.location.href);
        } catch (e) {
          var base =
            location.origin || location.protocol + "//" + location.host;
          var dir = location.pathname.replace(/\/[^\/]*$/, "/");
          var u = new URL(base + dir + "api/cron_mark.php");
        }
        u.searchParams.set("what", what);
        if (token) u.searchParams.set("token", token);
        if (extra) {
          String(extra)
            .split("&")
            .forEach(function (kv) {
              if (!kv) return;
              var i = kv.indexOf("=");
              var k = i >= 0 ? kv.slice(0, i) : kv;
              var v = i >= 0 ? kv.slice(i + 1) : "";
              try {
                u.searchParams.set(
                  decodeURIComponent(k),
                  decodeURIComponent(v),
                );
              } catch (_) {
                u.searchParams.set(k, v);
              }
            });
        }
        return u.toString();
      }

      function renderRow(label, last, everyMin, what) {
        var row = el("div", "row middle gap-s");
        var a = document.createElement("span");
        a.textContent = label + ": ";
        row.append(a);
        var url = makeUrl(what, "");
        var snippet = url;

        function recompute(lts) {
          last = lts != null ? lts : last;
          var next =
            isFinite(everyMin) && everyMin > 0 && last != null
              ? last + everyMin * 60000
              : null;
          var ok =
            next == null ? !!last : now <= next + everyMin * 60000 + 15000;
          return { next, ok };
        }
        function fmt(ts) {
          return ts ? new Date(ts).toLocaleString() : "—";
        }

        var res = recompute(last);
        var lastSpan = document.createElement("span");
        lastSpan.textContent = "last " + fmt(last);
        var nextSpan = document.createElement("span");
        nextSpan.textContent = " next due " + fmt(res.next);
        var pillEl = pill(res.ok);

        var copy = el("button", "btn secondary");
        copy.type = "button";
        copy.textContent = "Copy cURL";
        var cmd = 'curl -fsS "' + url + '"';
        copy.addEventListener("click", function () {
          try {
            navigator.clipboard?.writeText?.(cmd);
            window.toast?.info?.("Copied");
          } catch (_) {
            alert(cmd);
          }
        });

        var refresh = el("button", "btn");
        refresh.type = "button";
        refresh.textContent = "Refresh";
        var cooldown = false;
        refresh.addEventListener("click", function () {
          if (cooldown) {
            window.toast?.info?.("Give it a sec…");
            return;
          }
          cooldown = true;
          refresh.disabled = true;
          setTimeout(function () {
            cooldown = false;
            refresh.disabled = false;
          }, 5000);
          var variants = [
            "peek=1",
            "check=1",
            "status=1",
            "noop=1",
            "dryrun=1",
          ];
          (function tryNext(i) {
            if (i >= variants.length) {
              window.toast?.info?.("Tried refresh; if no change, run cron");
              return;
            }
            fetch(makeUrl(what, variants[i]), { method: "GET" })
              .then(function (r) {
                return r.json().catch(function () {
                  return null;
                });
              })
              .then(function (j) {
                if (j && (j.ts || j.last || j.last_ts)) {
                  var ts = j.ts || j.last || j.last_ts;
                  var ms =
                    typeof ts === "number"
                      ? ts * (ts > 1e12 ? 1 : 1000)
                      : Date.parse(ts);
                  if (isFinite(ms)) {
                    var out = recompute(ms);
                    lastSpan.textContent = "last " + fmt(ms);
                    nextSpan.textContent = " next due " + fmt(out.next);
                    pillEl.className = "pill " + (out.ok ? "ok" : "fail");
                    pillEl.textContent = out.ok ? "OK" : "FAIL";
                  }
                } else {
                  tryNext(i + 1);
                }
              })
              .catch(function () {
                tryNext(i + 1);
              });
          })(0);
        });

        row.append(
          lastSpan,
          nextSpan,
          pillEl,
          copy,
          refresh,
          (function () {
            var code = document.createElement("code");
            code.className = "inline muted";
            code.textContent = 'curl -fsS "' + snippet + '"';
            return code;
          })(),
        );
        return row;
      }

      wrap.append(
        renderRow("Alerts", alertsLast, alertsEvery, "alerts"),
        renderRow("History", histLast, histEvery, "history"),
      );
      pane.appendChild(blk4);
      // If token is missing in config data, try security settings and update the cURL snippets/copy buttons
      try {
        if (!token) {
          fetch("api/security_get.php", { credentials: "same-origin" })
            .then(function (r) {
              return r.json().catch(function () {
                return null;
              });
            })
            .then(function (j) {
              var t =
                j &&
                j.ok &&
                j.settings &&
                (j.settings.CRON_TOKEN || j.settings.cron_token)
                  ? j.settings.CRON_TOKEN || j.settings.cron_token
                  : "";
              if (t) {
                token = t;
                var codes = blk4.querySelectorAll("code.inline");
                // Update snippets: index 0 => Alerts, index 1 => History (structure defined above)
                if (codes[0]) {
                  var uA = makeUrl("alerts", "");
                  codes[0].textContent = 'curl -fsS "' + uA + '"';
                }
                if (codes[1]) {
                  var uH = makeUrl("history", "");
                  codes[1].textContent = 'curl -fsS "' + uH + '"';
                }
                // Update copy handlers to use the new URLs
                var copyBtns = Array.prototype.slice
                  .call(blk4.querySelectorAll("button"))
                  .filter(function (b) {
                    return (
                      (b.textContent || "").trim().toLowerCase() === "copy curl"
                    );
                  });
                if (copyBtns[0]) {
                  copyBtns[0].onclick = function () {
                    try {
                      navigator.clipboard?.writeText?.(
                        'curl -fsS "' + makeUrl("alerts", "") + '"',
                      );
                      window.toast?.info?.("Copied");
                    } catch (_) {
                      alert('curl -fsS "' + makeUrl("alerts", "") + '"');
                    }
                  };
                }
                if (copyBtns[1]) {
                  copyBtns[1].onclick = function () {
                    try {
                      navigator.clipboard?.writeText?.(
                        'curl -fsS "' + makeUrl("history", "") + '"',
                      );
                      window.toast?.info?.("Copied");
                    } catch (_) {
                      alert('curl -fsS "' + makeUrl("history", "") + '"');
                    }
                  };
                }
              }
            })
            .catch(function () {});
        }
      } catch (_) {}
    }
  }

  // ----- Email helpers -----
  function startConnect(address, provider) {
    if (!address) {
      window.toast?.error?.("Missing email address");
      return;
    }
    var map = {
      auto: "google",
      gmail: "google",
      google: "google",
      outlook: "outlook",
      microsoft: "outlook",
      yahoo: "yahoo",
      imap: "imap",
    };
    var prov = map[String(provider || "").toLowerCase()] || "google";
    if (prov === "imap") {
      window.toast?.info?.("IMAP uses password; no OAuth needed");
      return;
    }
    var url =
      "api/email_oauth_start.php?provider=" +
      encodeURIComponent(prov) +
      "&address=" +
      encodeURIComponent(address);
    window.open(url, "_blank", "noopener,noreferrer");
  }

  function sendTestEmail(to, opts) {
    var verbose = opts && opts.verbose;
    var token =
      (data.alerts && data.alerts.cron_token) ||
      (data.site && data.site.cron_token) ||
      "";

    function asJsonResponse(r) {
      return r.text().then(function (t) {
        var j = null;
        try {
          j = JSON.parse(t);
        } catch (_) {}
        return { status: r.status, ok: r.ok, json: j, text: t };
      });
    }
    function extractNote(res) {
      if (!res) return "";
      var j = res.json || {};
      var t =
        (j &&
          (j.error ||
            (j.result && (j.result.error || j.result.message)) ||
            j.message)) ||
        "";
      if (!t && res.text) t = String(res.text).slice(0, 200);
      var code = res.status ? " [" + res.status + "]" : "";
      var trans =
        j && (j.transport || (j.result && j.result.transport))
          ? " (" + (j.transport || (j.result && j.result.transport)) + ")"
          : "";
      return trans + code + (t ? " — " + t : "");
    }
    function toastVerbose(tag, res) {
      if (!verbose) return;
      var note = extractNote(res);
      if (window.toast && window.toast.warn)
        window.toast.warn(tag + (note || ""));
    }
    var messages = [];
    function pushMsg(tag, res) {
      var note = extractNote(res);
      messages.push(tag + (note ? " " + note : ""));
    }

    // 1) api/mail_test.php (POST)
    var form = new URLSearchParams();
    form.set("to", to);
    if (token) form.set("token", token);
    fetch("api/mail_test.php", {
      method: "POST",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8",
      },
      body: String(form),
    })
      .then(asJsonResponse)
      .then(function (res) {
        if (
          res.ok &&
          res.json &&
          (res.json.ok === true || res.json.ok === "true" || res.json.ok === 1)
        ) {
          var t = res.json.transport ? " (" + res.json.transport + ")" : "";
          window.toast &&
            window.toast.success &&
            window.toast.success("Test email sent via mail_test.php" + t);
          return;
        }
        toastVerbose("mail_test.php", res);
        pushMsg("mail_test.php", res);
        // 2) api/alerts_test.php (GET)
        var url =
          "api/alerts_test.php?to=" +
          encodeURIComponent(to) +
          (token ? "&token=" + encodeURIComponent(token) : "");
        return fetch(url, { method: "GET" })
          .then(asJsonResponse)
          .then(function (r2) {
            if (
              r2.ok &&
              r2.json &&
              (r2.json.ok === true || r2.json.ok === "true" || r2.json.ok === 1)
            ) {
              var t2 = r2.json.transport ? " (" + r2.json.transport + ")" : "";
              window.toast &&
                window.toast.success &&
                window.toast.success(
                  "Test email sent via alerts_test.php" + t2,
                );
              return;
            }
            toastVerbose("alerts_test.php", r2);
            pushMsg("alerts_test.php", r2);
            // 3) config.php action
            return fetch("config.php", {
              method: "POST",
              headers: { "Content-Type": "application/json;charset=UTF-8" },
              body: JSON.stringify({
                _csrf: CSRF,
                action: "send_test_email",
                to: to,
              }),
            })
              .then(asJsonResponse)
              .then(function (r3) {
                if (
                  r3.ok &&
                  r3.json &&
                  (r3.json.ok === true ||
                    r3.json.ok === "true" ||
                    r3.json.ok === 1)
                ) {
                  var t3 = r3.json.transport
                    ? " (" + r3.json.transport + ")"
                    : "";
                  window.toast &&
                    window.toast.success &&
                    window.toast.success("Test email sent via config.php" + t3);
                  return;
                }
                toastVerbose("config.php", r3);
                pushMsg("config.php", r3);
                // 4) legacy
                var leg = ["api/email_test.php", "api/email_send_test.php"];
                (function next(i) {
                  if (i >= leg.length) {
                    var err =
                      messages.join(" | ") || "All test endpoints failed";
                    window.toast &&
                      window.toast.error &&
                      window.toast.error("Test email failed: " + err);
                    return;
                  }
                  var u = leg[i] + "?to=" + encodeURIComponent(to);
                  fetch(u)
                    .then(asJsonResponse)
                    .then(function (r4) {
                      if (
                        r4.ok &&
                        r4.json &&
                        (r4.json.ok === true ||
                          r4.json.ok === "true" ||
                          r4.json.ok === 1)
                      ) {
                        var tl = r4.json.transport
                          ? " (" + r4.json.transport + ")"
                          : "";
                        window.toast &&
                          window.toast.success &&
                          window.toast.success(
                            "Test email sent via " + leg[i] + tl,
                          );
                      } else {
                        toastVerbose(leg[i], r4);
                        pushMsg(leg[i], r4);
                        next(i + 1);
                      }
                    })
                    .catch(function (e) {
                      messages.push(leg[i] + " — " + e.message);
                      next(i + 1);
                    });
                })(0);
              });
          });
      })
      .catch(function (e) {
        window.toast &&
          window.toast.error &&
          window.toast.error("Send test failed: " + e.message);
      });
  }

  // Email accounts editor (forced email.accounts; normalize provider + lowercase)
  function buildEmailAccountsEditor(section, pane) {
    var rawEmail = pathGet("email.accounts");
    var rawMail = pathGet("mail.accounts");
    var existing = rawEmail != null ? rawEmail : rawMail;
    var list = normalizeAccounts(existing);

    var blk = el("div", "block");
    blk.innerHTML = "<h3>Saved Email Accounts</h3>";
    if (rawMail != null && rawEmail == null) {
      var note = el("div", "muted");
      note.textContent = '(migrated from "mail.accounts" → "email.accounts")';
      blk.appendChild(note);
    }

    var addRow = el("div", "row gap-s");
    var addr = el("input");
    addr.type = "text";
    addr.placeholder = "address@domain";
    var pass = el("input");
    pass.type = "password";
    pass.placeholder = "Password / App password";
    var provider = el("select");
    ["auto", "gmail", "google", "outlook", "yahoo", "imap"].forEach(
      function (p) {
        var o = document.createElement("option");
        o.value = p;
        o.textContent = p;
        provider.appendChild(o);
      },
    );
    var poll = el("input");
    poll.type = "number";
    poll.min = "60";
    poll.step = "60";
    poll.value = "300";
    poll.title = "poll_seconds";
    var add = el("button", "btn secondary");
    add.type = "button";
    add.textContent = "Add";

    var listBox = el("div");
    listBox.className = "listbox";
    listBox.style.marginTop = ".5rem";

    function renderList() {
      listBox.innerHTML = "";
      if (!list.length) {
        var p = document.createElement("div");
        p.className = "muted";
        p.textContent = "No email accounts saved yet.";
        listBox.appendChild(p);
        return;
      }
      list.forEach(function (item, idx) {
        var row = el("div", "acc-row");
        var chk = el("input");
        chk.type = "checkbox";
        chk.title = "Enable notifier for this account";
        chk.checked = item.enabled !== false;
        chk.addEventListener("change", function () {
          item.enabled = !!chk.checked;
          item.notify = !!chk.checked;
          syncHidden();
        });
        var email = el("div", "email");
        email.textContent = item.address || "(no address)";
        var sel = el("select");
        ["auto", "gmail", "google", "outlook", "yahoo", "imap"].forEach(
          function (p) {
            var o = document.createElement("option");
            o.value = p;
            o.textContent = p;
            if (
              (item.provider || "auto") === p ||
              ((item.provider || "auto") === "google" && p === "gmail")
            )
              o.selected = true;
            sel.appendChild(o);
          },
        );
        sel.addEventListener("change", function () {
          var v = sel.value;
          item.provider = v === "gmail" ? "google" : v;
          syncHidden();
        });

        var ps = el("input");
        ps.type = "number";
        ps.min = "60";
        ps.step = "60";
        ps.value = String(item.poll_seconds == null ? 300 : item.poll_seconds);
        ps.addEventListener("input", function () {
          var n = +ps.value || 300;
          item.poll_seconds = n;
          syncHidden();
        });

        var connect = el("button", "btn");
        connect.type = "button";
        var labelProvider = item.provider || "auto";
        connect.textContent =
          "Connect " +
          (labelProvider === "auto"
            ? "Account"
            : labelProvider.charAt(0).toUpperCase() + labelProvider.slice(1));
        connect.addEventListener("click", function () {
          startConnect(item.address || "", item.provider || "auto");
        });

        var reconn = null;
        if (
          String(item.provider || "").toLowerCase() === "google" ||
          String(item.provider || "").toLowerCase() === "gmail"
        ) {
          reconn = el("button", "btn secondary");
          reconn.type = "button";
          reconn.textContent = "Reconnect Google";
          reconn.addEventListener("click", function () {
            startConnect(item.address || "", "google");
          });
        }

        var del = el("button", "btn danger");
        del.type = "button";
        del.textContent = "Delete";
        del.addEventListener("click", function () {
          list.splice(idx, 1);
          syncHidden();
          renderList();
        });

        row.append(chk, email, sel, ps, connect);
        if (reconn) row.append(reconn);
        row.append(del);
        listBox.appendChild(row);
      });
    }
    function normalizeAccounts(v) {
      if (!v) return [];
      try {
        if (
          typeof v === "string" &&
          (v.trim().startsWith("[") || v.trim().startsWith("{"))
        )
          v = JSON.parse(v);
      } catch (_) {}
      if (Array.isArray(v)) {
        return v.map(function (o) {
          if (typeof o === "string")
            return {
              address: o.toLowerCase(),
              password: "",
              provider: "auto",
              poll_seconds: 300,
              enabled: true,
              notify: true,
            };
          var prov = o.provider || "auto";
          prov =
            String(prov).toLowerCase() === "gmail"
              ? "google"
              : String(prov).toLowerCase();
          return {
            address: (o.address || o.email || "").toLowerCase(),
            password: o.password || o.pass || "",
            provider: prov,
            poll_seconds: o.poll_seconds == null ? 300 : +o.poll_seconds,
            enabled: o.enabled !== false,
            notify: o.notify !== false,
          };
        });
      }
      if (isPlainObject(v)) {
        return Object.keys(v).map(function (k) {
          return {
            address: String(k).toLowerCase(),
            password: String(v[k] || ""),
            provider: "auto",
            poll_seconds: 300,
            enabled: true,
            notify: true,
          };
        });
      }
      return [];
    }
    function toJSONString(list) {
      var clean = list.map(function (o) {
        return {
          address: (o.address || "").toLowerCase(),
          password: o.password || "",
          provider:
            (o.provider || "auto").toLowerCase() === "gmail"
              ? "google"
              : o.provider || "auto",
          poll_seconds: o.poll_seconds == null ? 300 : +o.poll_seconds,
          enabled: o.enabled !== false,
          notify: o.notify !== false,
        };
      });
      return JSON.stringify(clean);
    }
    function syncHidden() {
      var hidden = $("#__email_accounts_hidden");
      if (!hidden) {
        hidden = el("input");
        hidden.type = "hidden";
        hidden.name = "email.accounts";
        hidden.id = "__email_accounts_hidden";
        hidden.dataset.keepString = "1";
        pane.appendChild(hidden);
      }
      hidden.value = toJSONString(list);
    }

    add.addEventListener("click", function () {
      var a = addr.value.trim();
      var p = pass.value;
      var prov = provider.value || "auto";
      var ps = +poll.value || 300;
      if (!a) return alert("Address required");
      list.push({
        address: a.toLowerCase(),
        password: p,
        provider: prov === "gmail" ? "google" : prov,
        poll_seconds: ps,
        enabled: true,
        notify: true,
      });
      addr.value = "";
      pass.value = "";
      provider.value = "auto";
      poll.value = "300";
      syncHidden();
      renderList();
    });

    blk.append(addRow);
    addRow.append(addr, pass, provider, poll, add);
    blk.append(listBox);
    renderList();
    syncHidden();
    pane.appendChild(blk);
  }
})();
