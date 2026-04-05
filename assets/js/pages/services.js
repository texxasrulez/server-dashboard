(function () {
  const $ = (s, c) => (c || document).querySelector(s);
  const $$ = (s, c) => Array.from((c || document).querySelectorAll(s));
  const t = (key, fallback, vars) => {
    try {
      if (window.I18N && typeof window.I18N.t === "function") {
        return window.I18N.t(key, fallback, vars);
      }
    } catch (_e) {}
    return fallback != null ? fallback : key;
  };
  const csrfToken = () => {
    const m = document.querySelector('meta[name="csrf-token"]');
    return m && m.content ? String(m.content) : "";
  };
  const apiFetch = (url, init) => {
    const opts = init ? Object.assign({}, init) : {};
    if (!opts.credentials) opts.credentials = "same-origin";
    const method = String(opts.method || "GET").toUpperCase();
    if (method !== "GET" && method !== "HEAD" && method !== "OPTIONS") {
      const token = csrfToken();
      if (token) {
        const headers = new Headers(opts.headers || {});
        if (!headers.has("X-CSRF-Token")) headers.set("X-CSRF-Token", token);
        opts.headers = headers;
        if (opts.body instanceof URLSearchParams && !opts.body.has("_csrf")) {
          opts.body.set("_csrf", token);
        } else if (
          (headers.get("Content-Type") || "").indexOf("application/json") >=
            0 &&
          typeof opts.body === "string"
        ) {
          try {
            const parsed = JSON.parse(opts.body);
            if (
              parsed &&
              typeof parsed === "object" &&
              !parsed._csrf &&
              !parsed.csrf
            ) {
              parsed._csrf = token;
              opts.body = JSON.stringify(parsed);
            }
          } catch (_e) {}
        }
      }
    }
    return fetch(url, opts);
  };

  const modal = $("#svcModal");
  const form = $("#svcForm");

  const api = {
    list: () =>
      apiFetch("api/services_list.php").then((r) => {
        if (!r.ok) {
          throw new Error("HTTP " + r.status);
        }
        return r.json();
      }),
    upsert: (item) =>
      apiFetch("api/service_upsert.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(item),
      }).then((r) => {
        if (!r.ok) {
          throw new Error("HTTP " + r.status);
        }
        return r.json();
      }),
    del: (id) =>
      apiFetch("api/service_delete.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: new URLSearchParams({ id }),
      }).then((r) => {
        if (!r.ok) {
          throw new Error("HTTP " + r.status);
        }
        return r.json();
      }),
    probe: (item) =>
      apiFetch("api/service_probe.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(item),
      }).then((r) => {
        if (!r.ok) {
          throw new Error("HTTP " + r.status);
        }
        return r.json();
      }),
    toggle: (id) =>
      apiFetch("api/service_toggle.php", {
        method: "POST",
        body: new URLSearchParams({ id }),
      }).then((r) => {
        if (!r.ok) {
          throw new Error("HTTP " + r.status);
        }
        return r.json();
      }),
    importJson: (payload) =>
      apiFetch("api/services_import.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      }).then((r) => {
        if (!r.ok) {
          throw new Error("HTTP " + r.status);
        }
        return r.json();
      }),
    importCsv: (csvText) =>
      apiFetch("api/services_import.php?format=csv", {
        method: "POST",
        headers: { "Content-Type": "text/csv" },
        body: csvText,
      }).then((r) => {
        if (!r.ok) {
          throw new Error("HTTP " + r.status);
        }
        return r.json();
      }),
    probeAll: () =>
      apiFetch("api/services_probe_all.php").then((r) => {
        if (!r.ok) {
          throw new Error("HTTP " + r.status);
        }
        return r.json();
      }),
    silenceRules: (ids, minutes) =>
      apiFetch("api/alerts_bulk.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          action: "silence",
          ids: ids,
          silence_minutes: minutes,
        }),
      }).then((r) => {
        if (!r.ok) {
          throw new Error("HTTP " + r.status);
        }
        return r.json();
      }),
  };
  const SERVICE_CACHE = new Map();

  // ---------- helpers ----------
  const isPort = (v) => Number.isInteger(v) && v >= 1 && v <= 65535;

  function showAlert(msg) {
    const el = $("#formAlert");
    if (!el) return;
    el.textContent = msg || "";
    el.hidden = !msg;
  }
  function clearErrors() {
    $$(".is-invalid").forEach((x) => x.classList.remove("is-invalid"));
    $$(".field-msg").forEach((x) => x.remove());
    showAlert("");
  }
  function setErr(input, msg) {
    if (!input) return;
    input.classList.add("is-invalid");
    const small = document.createElement("div", "card");
    small.className = "field-msg";
    small.textContent = msg;
    if (input.parentElement) input.parentElement.appendChild(small);
  }

  function pill(status) {
    const s = status === "up" ? "up" : status === "warn" ? "warn" : "down";
    const span = document.createElement("span");
    span.className = "chip " + s;
    span.textContent = t(
      "services_page.status." + s,
      s.toUpperCase(),
    );
    return span;
  }
  function relTime(ts) {
    if (!ts) return "";
    if (ts > 1e12) ts = Math.floor(ts / 1000);
    const diff = Math.max(0, Math.floor(Date.now() / 1000) - ts);
    if (diff < 45) return t("services_page.relative_time.just_now", "just now");
    const m = Math.floor(diff / 60);
    if (m < 60)
      return t("services_page.relative_time.minutes_ago", "{count}m ago", {
        count: m,
      });
    const h = Math.floor(m / 60);
    if (h < 24)
      return t("services_page.relative_time.hours_ago", "{count}h ago", {
        count: h,
      });
    const d = Math.floor(h / 24);
    return t("services_page.relative_time.days_ago", "{count}d ago", {
      count: d,
    });
  }
  function badge(text, cls) {
    const span = document.createElement("span");
    span.className = "chip " + (cls || "neutral");
    span.textContent = text;
    return span;
  }

  // Sorting
  let sortKey = null;
  let sortDir = 1; // 1 asc, -1 desc
  function sortItems(items) {
    if (!sortKey) return items;
    return items.slice().sort((a, b) => {
      let va = a[sortKey];
      let vb = b[sortKey];
      if (typeof va === "string") va = va.toLowerCase();
      if (typeof vb === "string") vb = vb.toLowerCase();
      if (va < vb) return -1 * sortDir;
      if (va > vb) return 1 * sortDir;
      return 0;
    });
  }
  $("#servicesTable thead")?.addEventListener("click", (e) => {
    const th = e.target.closest("th.sortable");
    if (!th) return;
    const key = th.dataset.sort;
    if (sortKey === key) sortDir = -sortDir;
    else {
      sortKey = key;
      sortDir = 1;
    }
    refresh();
  });

  function trTemplate(it) {
    const tr = document.createElement("tr");
    tr.dataset.id = it.id;
    tr.innerHTML = `
      <td class="nowrap">
        <div class="nm">${it.name || ""}</div>
        <div class="muted mini-result"></div>
      </td>
      <td>${it.type || "other"}</td>
      <td>${it.host || ""}</td>
      <td class="center">${it.port || ""}</td>
      <td class="center">${it.check || "tcp"}</td>
      <td class="center">${it.timeout_ms || 800} ${t("services_page.units.ms", "ms")}</td>
      <td class="center">${it.check === "http" ? it.path || "/" : t("common.none_dash", "-")}</td>
      <td class="center enabled-pill ${it.enabled ? "on" : "off"}">
        <div class="cell-center">
          <nav class="tabs compact"><a href="#" data-act="toggle">${it.enabled ? t("common.on", "On") : t("common.off", "Off")}</a></nav>
        </div>
      </td>
      <td class="actions-tabs">
        <div class="cell-center">
          <nav class="tabs compact">
            <a href="#" data-act="test">${t("services_page.actions.test", "Test")}</a>
            <a href="#" data-act="edit">${t("common.edit", "Edit")}</a>
            <a href="#" data-act="del">${t("common.delete", "Delete")}</a>
          </nav>
        </div>
      </td>`;
    return tr;
  }

  function openModal(editing = false, data = null) {
    // Default values on "Add Service"
    try {
      const hostInput = document.getElementById("f_host");
      if (!editing && hostInput) {
        // Use placeholder if present, else fall back to 127.0.0.1
        const defHost = hostInput.getAttribute("placeholder") || "127.0.0.1";
        if (!hostInput.value) hostInput.value = defHost;
      }
    } catch (_) {}

    clearErrors();
    $("#modalTitle").textContent = editing
      ? t("services_page.edit_service", "Edit Service")
      : t("services_page.add_service", "Add Service");
    if (form) form.reset();
    $("#f_id").value = data?.id || "";
    $("#f_name").value = data?.name || "";
    $("#f_type").value = data?.type || "other";
    $("#f_host").value = data?.host || "127.0.0.1";
    $("#f_port").value = data?.port || "";
    $("#f_check").value = data?.check || "tcp";
    $("#f_timeout").value = data?.timeout_ms ?? 800;
    $("#f_path").value = data?.path || "";
    $("#f_enabled").checked = !!(data?.enabled ?? true);
    $("#f_testOnSave").checked = true;
    togglePath();
    modal.hidden = false;
    setTimeout(() => $("#f_name")?.focus(), 0);
  }
  function closeModal() {
    modal.hidden = true;
  }

  function togglePath() {
    const isHttp = $("#f_check").value === "http";
    $("#f_path").disabled = !isHttp;
    $("#f_path").placeholder = isHttp
      ? "/health"
      : t("services_page.path_not_used", "(not used)");
  }
  $("#f_check")?.addEventListener("change", togglePath);

  async function refresh() {
    const tbody = $("#svcBody");
    if (!tbody) return;
    tbody.innerHTML = "";
    try {
      const data = await api.list();
      SERVICE_CACHE.clear();
      const items = sortItems(data.items || []);
      items.forEach((it) => {
        if (it && it.id) SERVICE_CACHE.set(String(it.id), it);
        const row = trTemplate(it);
        tbody.appendChild(row);
        decorateRow(row, it);
      });
    } catch (e) {
      console.error("Failed to load services", e);
    }
  }
  function decorateRow(row, svc) {
    const mini = row.querySelector(".mini-result");
    if (!mini) return;
    mini.innerHTML = "";
    const meta = svc.status_meta || {};
    if (meta.status) {
      mini.appendChild(pill(meta.status));
    }
    const details = [];
    if (meta.latency_ms != null) details.push(meta.latency_ms + " ms");
    if (meta.http_code) details.push("HTTP " + meta.http_code);
    if (meta.ts) details.push(relTime(meta.ts));
    if (details.length) {
      const span = document.createElement("span");
      span.className = "muted";
      span.textContent = " " + details.join(" · ");
      mini.appendChild(span);
    }
    const uptime = svc.uptime_meta;
    if (uptime && uptime.uptime_pct != null) {
      mini.appendChild(
        badge(
          t("services_page.uptime", "Uptime {pct}%", {
            pct: uptime.uptime_pct,
          }),
          uptime.uptime_pct >= 95
            ? "ok"
            : uptime.uptime_pct >= 80
              ? "warn"
              : "down",
        ),
      );
    }
    const alertMeta = svc.alert_meta || {};
    if (alertMeta.last_alert && alertMeta.last_alert.ts) {
      const last = alertMeta.last_alert;
      const chip = badge(
        t("services_page.alert_last_seen", "Alert {time}", {
          time: relTime(last.ts),
        }),
        "warn",
      );
      chip.title = (last.name || "") + " (" + (last.severity || "warn") + ")";
      mini.appendChild(chip);
    }
    if (alertMeta.silenced_until) {
      const mute = badge(
        t("services_page.alerts_muted_until", "Muted {time}", {
          time: relTime(alertMeta.silenced_until),
        }),
        "neutral",
      );
      mini.appendChild(mute);
    }
    const linkRow = document.createElement("div");
    linkRow.className = "svc-links";
    if (svc.id) {
      const hist = document.createElement("a");
      hist.className = "chip neutral small";
      hist.textContent = t("history.title", "History");
      hist.href = "history.php?service=" + encodeURIComponent(svc.id);
      hist.target = "_blank";
      hist.rel = "noopener";
      linkRow.appendChild(hist);
    }
    if (alertMeta.rule_ids && alertMeta.rule_ids.length) {
      const muteBtn = document.createElement("a");
      muteBtn.href = "#";
      muteBtn.dataset.act = "silence-service";
      muteBtn.dataset.rules = alertMeta.rule_ids.join(",");
      muteBtn.className = "chip warn small";
      muteBtn.textContent = t("services_page.mute_alerts", "Mute alerts");
      linkRow.appendChild(muteBtn);
    }
    if (linkRow.childNodes.length) mini.appendChild(linkRow);
  }

  // Filter
  $("#svcSearch")?.addEventListener("input", (e) => {
    const q = String((e && e.target && e.target.value) || "")
      .toLowerCase()
      .trim();
    $$("#svcBody tr").forEach((tr) => {
      tr.hidden =
        q &&
        !String(tr.textContent || "")
          .toLowerCase()
          .includes(q);
    });
  });

  // Table actions
  $("#servicesTable")?.addEventListener("click", async (e) => {
    const a = e.target.closest("a[data-act]");
    if (!a) return;
    e.preventDefault();
    const tr = a.closest("tr");
    if (!tr) return;
    const id = tr.dataset.id;
    let it = SERVICE_CACHE.get(id);
    if (!it) {
      const list = await api.list();
      it = (list.items || []).find((x) => x.id === id);
    }
    if (!it) return;

    const act = a.dataset.act;
    if (act === "edit") {
      openModal(true, it);
    } else if (act === "del") {
      if (confirm(t("services_page.confirm_delete", "Delete this service?"))) {
        await api.del(id);
        refresh();
      }
    } else if (act === "test") {
      try {
        if (window.toast) toast.info(t("services_page.probe_kicked", "Probe kicked"));
      } catch (e) {}
      const res = await api.probe(it);
      applyProbe(tr, res);
    } else if (act === "toggle") {
      const res = await api.toggle(id);
      if (res.item) {
        const cell = tr.querySelector(".enabled-pill");
        cell.classList.toggle("on", !!res.item.enabled);
        cell.classList.toggle("off", !res.item.enabled);
        cell.querySelector('a[data-act="toggle"]').textContent = res.item
          .enabled
          ? t("common.on", "On")
          : t("common.off", "Off");
        SERVICE_CACHE.set(id, Object.assign({}, it, res.item));
      }
    } else if (act === "silence-service") {
      const ruleIds = (a.dataset.rules || "")
        .split(",")
        .map((s) => s.trim())
        .filter(Boolean);
      if (!ruleIds.length) {
        window.toast &&
          window.toast.warn &&
          window.toast.warn(
            t("services_page.no_alert_rules", "No alert rules to mute."),
          );
        return;
      }
      const minsInput = prompt(
        t(
          "services_page.prompt_mute_minutes",
          "Mute related alerts for how many minutes?",
        ),
        "60",
      );
      if (!minsInput) return;
      const mins = Math.max(1, parseInt(minsInput, 10) || 60);
      try {
        await api.silenceRules(ruleIds, mins);
        window.toast &&
          window.toast.success &&
          window.toast.success(
            t("services_page.alerts_muted_for", "Alerts muted for {mins}m.", {
              mins,
            }),
          );
        refresh();
      } catch (err) {
        window.toast &&
          window.toast.error &&
          window.toast.error(
            t("services_page.mute_failed", "Mute failed: {message}", {
              message: err.message || err,
            }),
          );
      }
    }
  });

  function applyProbe(tr, res) {
    /* TOAST: probe result */
    try {
      if (window.toast) {
        var name =
          tr.querySelector(".nm")?.textContent?.trim() ||
          t("services.title", "Service");
        var lat =
          res && res.latency_ms != null
            ? " " + res.latency_ms + " " + t("services_page.units.ms", "ms")
            : "";
        var code =
          res && res.http_code != null ? " • HTTP " + res.http_code : "";
        var msg =
          name +
          ": " +
          t(
            "services_page.status." + String(res.status || "unknown"),
            String(res.status || "unknown").toUpperCase(),
          ) +
          lat +
          code;
        if (res.status === "up") toast.success(msg);
        else if (res.status === "warn") toast.warn(msg);
        else toast.error(msg);
      }
    } catch (e) {}

    decorateRow(tr, {
      status_meta: {
        status: res.status || "unknown",
        latency_ms: res.latency_ms ?? null,
        http_code: res.http_code ?? null,
        ts: Math.floor(Date.now() / 1000),
      },
    });
  }

  // Toolbar buttons
  $("#btnAddService")?.addEventListener("click", (e) => {
    e.preventDefault();
    openModal(false, null);
  });

  // Import: trigger hidden file input
  $("#btnImport")?.addEventListener("click", (e) => {
    e.preventDefault();
    $("#importFile").click();
  });
  $("#importFile")?.addEventListener("change", async (e) => {
    const file = e.target.files[0];
    if (!file) return;
    const ext = (file.name.split(".").pop() || "").toLowerCase();
    const text = await file.text();
    let res;
    if (ext === "csv") res = await api.importCsv(text);
    else {
      let json;
      try {
        json = JSON.parse(text);
      } catch {
        alert(t("services_page.invalid_json", "Invalid JSON"));
        return;
      }
      res = await api.importJson(json);
    }
    if (res && !res.error) {
      refresh();
    } else alert(res.error || t("services_page.import_failed", "Import failed"));
    e.target.value = "";
  });

  // ---- Global AutoProbe bridging ----
  function syncFromGlobal() {
    if (!window.AutoProbe) return;
    const cfg = window.AutoProbe.get();
    const sec = Math.max(5, parseInt(cfg.interval || 60, 10));
    const on = !!cfg.enabled;
    const cb = $("#autoProbe");
    const inp = $("#autoProbeSec");
    if (cb) cb.checked = on;
    if (inp) inp.value = sec;
  }
  function writeGlobal() {
    if (!window.AutoProbe) return;
    const cfg = window.AutoProbe.get();
    cfg.enabled = $("#autoProbe")?.checked || false;
    cfg.interval = Math.max(5, parseInt($("#autoProbeSec")?.value || "60", 10));
    window.AutoProbe.set(cfg);
  }
  // listen to background probe results to update the visible table live
  window.addEventListener("services:probeUpdate", (ev) => {
    const res = ev.detail || {};
    (res.results || []).forEach((r) => {
      const tr = document.querySelector('#svcBody tr[data-id="' + r.id + '"]');
      if (!tr) return;
      const mini = tr.querySelector(".mini-result");
      if (!mini) return;
      mini.innerHTML = "";
      const span = document.createElement("span");
      span.className =
        "pill " +
        (r.status === "up" ? "up" : r.status === "warn" ? "warn" : "down");
      span.textContent = (r.status || "").toUpperCase();
      mini.appendChild(span);
      if (r.latency_ms != null) {
        const meta = document.createElement("span");
        meta.className = "muted";
        meta.textContent =
          " " +
          r.latency_ms +
          " " +
          t("services_page.units.ms", "ms") +
          (r.http_code ? " • HTTP " + r.http_code : "");
        mini.appendChild(meta);
      }
    });
  });

  // Auto probe
  function setAutoProbe(on) {
    writeGlobal();
  }
  $("#autoProbe")?.addEventListener("change", () => writeGlobal());
  $("#autoProbeSec")?.addEventListener("change", () => writeGlobal());
  // sync UI with global on load and when config changes
  window.addEventListener("autoprobe:config", syncFromGlobal);
  document.addEventListener("DOMContentLoaded", syncFromGlobal);

  // Modal actions
  $("#modalClose")?.addEventListener("click", (e) => {
    e.preventDefault();
    closeModal();
  });
  $("#saveService")?.addEventListener("click", async (e) => {
    e.preventDefault();
    clearErrors();
    const item = {
      id: $("#f_id").value || undefined,
      name: $("#f_name").value.trim(),
      type: $("#f_type").value,
      host: $("#f_host").value.trim(),
      port: parseInt($("#f_port").value, 10) || 0,
      check: $("#f_check").value,
      timeout_ms: parseInt($("#f_timeout").value, 10) || 800,
      path: $("#f_path").value.trim() || "/",
      enabled: $("#f_enabled").checked,
    };

    let valid = true;
    if (!item.name) {
      setErr(
        $("#f_name"),
        t("services_page.validation.name_required", "Name is required."),
      );
      valid = false;
    }
    if (!item.host) {
      setErr(
        $("#f_host"),
        t("services_page.validation.host_required", "Host is required."),
      );
      valid = false;
    }
    if (!Number.isFinite(item.port) || !isPort(item.port)) {
      setErr(
        $("#f_port"),
        t("services_page.validation.port_range", "Port must be 1-65535."),
      );
      valid = false;
    }
    if (item.check === "http" && (!item.path || item.path[0] !== "/")) {
      $("#f_path").value = "/" + (item.path || "");
      item.path = $("#f_path").value;
    }
    if (!valid) {
      showAlert(
        t(
          "services_page.validation.correct_fields",
          "Please correct the highlighted fields.",
        ),
      );
      return;
    }

    const res = await api.upsert(item);
    if (res.error) {
      showAlert(res.error);
      return;
    }
    closeModal();
    await refresh();

    if ($("#f_testOnSave")?.checked) {
      const id = res.item && res.item.id ? res.item.id : item.id;
      const tr = id ? $('#svcBody tr[data-id="' + id + '"]') : null;
      const probeRes = await api.probe(res.item || item);
      if (tr) applyProbe(tr, probeRes);
    }
  });

  // Exports
  try {
    const makeExportUrl = (fmt) => {
      try {
        return new URL(
          "api/services_export.php?format=" + encodeURIComponent(fmt),
          window.location.href,
        ).toString();
      } catch (e) {
        return "api/services_export.php?format=" + encodeURIComponent(fmt);
      }
    };
    document.getElementById("btnExportJson")?.addEventListener("click", (e) => {
      e.preventDefault();
      window.location.href = makeExportUrl("json");
    });
    document.getElementById("btnExportCsv")?.addEventListener("click", (e) => {
      e.preventDefault();
      window.location.href = makeExportUrl("csv");
    });
  } catch (e) {
    /* no-op */
  }

  document.addEventListener("DOMContentLoaded", refresh);
})();
