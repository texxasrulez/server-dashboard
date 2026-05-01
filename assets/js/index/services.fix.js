/* assets/js/index/services.fix.js
 * Renders services and applies status. Tries multiple endpoints for the list.
 * Updated: adds a leading state icon (✓/✕/!/–) synced to the pill.
 */
(function () {
  const Bus = (window.Dashboard && window.Dashboard.Bus) || new EventTarget();
  const DEBUG = /[?&]debugStatus=1/.test(location.search);
  const ALERTS_BULK = "api/alerts_bulk.php";
  let servicesRoot = null;

  const LIST_CANDIDATES = [
    "api/services_list.php",
    "api/services.php",
    "api/services_get.php",
  ];

  function norm(v) {
    return (v == null ? "" : String(v)).trim().toLowerCase();
  }
  function yn(v) {
    if (v === true) return true;
    if (typeof v === "number") return v > 0;
    const s = norm(v);
    return (
      s === "1" ||
      s === "true" ||
      s === "on" ||
      s === "y" ||
      s === "yes" ||
      s === "t"
    );
  }
  function isEnabled(row) {
    if (row && row.disabled != null) return !yn(row.disabled);
    if (row && row.enabled != null) return yn(row.enabled);
    if (row && row.active != null) return yn(row.active);
    return true;
  }
  async function getJson(url) {
    const r = await fetch(url, { cache: "no-store" });
    if (!r.ok) throw new Error(String(r.status));
    return await r.json();
  }
  async function loadList() {
    for (const url of LIST_CANDIDATES) {
      try {
        const j = await getJson(url);
        const arr = Array.isArray(j.services)
          ? j.services
          : Array.isArray(j.items)
            ? j.items
            : Array.isArray(j)
              ? j
              : [];
        if (arr.length) {
          if (DEBUG) console.log("[services.fix] list from", url, arr.length);
          return arr.filter(isEnabled);
        }
      } catch (e) {
        if (DEBUG) console.warn("[services.fix] fail", url, e);
      }
    }
    return [];
  }

  function esc(s) {
    return String(s == null ? "" : s);
  }
  function tag(s) {
    return s ? `<span class="tag">${esc(s)}</span>` : "";
  }
  function relTime(ts) {
    if (!ts) return "";
    if (ts > 1e12) ts = Math.floor(ts / 1000);
    const diff = Math.max(0, Math.floor(Date.now() / 1000) - ts);
    if (diff < 45) return "just now";
    const m = Math.floor(diff / 60);
    if (m < 60) return m + "m ago";
    const h = Math.floor(m / 60);
    if (h < 24) return h + "h ago";
    const d = Math.floor(h / 24);
    return d + "d ago";
  }

  function renderCard(svc) {
    const rawId = svc.id || "";
    const id = esc(rawId);
    const name = esc(svc.name || svc.service || "Service");
    const host = esc(svc.host || "");
    const port = svc.port != null ? String(svc.port) : "";
    const check = esc(svc.check || svc.probe || "");
    const type = esc(svc.type || svc.category || "other");
    const path = esc(svc.path || "");
    const url =
      check === "http" && host
        ? host.match(/^https?:/)
          ? host
          : "http://" + host + (port ? ":" + port : "") + path
        : "";
    const uptimeMeta = svc.uptime_meta || {};
    const alertMeta = svc.alert_meta || {};
    const lastAlertTs = alertMeta.last_alert ? alertMeta.last_alert.ts : null;
    const silencedUntil = alertMeta.silenced_until || null;
    const historyHref = rawId
      ? `history.php?service=${encodeURIComponent(rawId)}`
      : "";
    const detailHref = rawId
      ? `service_detail.php?id=${encodeURIComponent(rawId)}`
      : "";
    const incidentMeta = svc.incident_meta || {};
    const incidentHref = incidentMeta.incident_id
      ? `incident.php?id=${encodeURIComponent(incidentMeta.incident_id)}`
      : "";
    const actions = [];
    if (historyHref) {
      actions.push(
        `<a class="chip small neutral svc-action" href="${historyHref}" target="_blank" rel="noopener">View history</a>`,
      );
    }
    if (detailHref) {
      actions.push(
        `<a class="chip small neutral svc-action" href="${detailHref}">Details</a>`,
      );
    }
    if (incidentHref) {
      actions.push(
        `<a class="chip small warn svc-action" href="${incidentHref}">Incident</a>`,
      );
    }
    if (
      alertMeta &&
      Array.isArray(alertMeta.rule_ids) &&
      alertMeta.rule_ids.length
    ) {
      const rulesAttr = alertMeta.rule_ids.map((r) => esc(r)).join(",");
      actions.push(
        `<a class="chip small warn svc-action" href="#" data-action="mute" data-rules="${rulesAttr}">Silence alerts</a>`,
      );
    }
    const actionsHtml = actions.length
      ? `<div class="svc-actions">${actions.join("")}</div>`
      : "";
    const metaTags = [
      tag(type),
      tag(check),
      host
        ? `<span class="tag">${host}${port ? ":" + port : ""}${path}</span>`
        : "",
      url
        ? `<a class="tag" href="${url}" target="_blank" rel="noopener">open</a>`
        : "",
      uptimeMeta && uptimeMeta.uptime_pct != null
        ? `<span class="tag">uptime ${uptimeMeta.uptime_pct}%</span>`
        : "",
      lastAlertTs
        ? `<span class="tag warn">alert ${relTime(lastAlertTs)}</span>`
        : "",
      silencedUntil
        ? `<span class="tag muted">muted ${relTime(silencedUntil)}</span>`
        : "",
    ].join(" ");

    return `
      <div class="card service-card" data-svc-id="${id}" data-svc-name="${name}">
        <div class="row between">
          <div class="svc-name">
            <span class="svc-state-icon neutral" aria-hidden="true"></span>
            <span class="svc-title">${name}</span>
          </div>
          <div class="row" style="gap:.5rem;align-items:center">
            <span class="pill neutral">--</span>
            <span class="svc-latency muted"></span>
          </div>
        </div>
        <div class="muted small" style="margin-top:.25rem">
          ${metaTags}
        </div>
        ${actionsHtml}
      </div>`;
  }

  async function render() {
    const root = document.getElementById("services");
    if (!root) return;
    servicesRoot = root;
    const list = await loadList();
    root.innerHTML = list.map(renderCard).join("");
    applyCounts();
  }

  function pill(card) {
    return card.querySelector(".pill");
  }
  function lat(card) {
    return card.querySelector(".svc-latency");
  }
  function key(card) {
    const id = card.getAttribute("data-svc-id");
    if (id) return id;
    const nm =
      card.getAttribute("data-svc-name") ||
      card.querySelector(".svc-name")?.textContent;
    return nm ? norm(nm) : null;
  }

  function setPill(card, state, latency) {
    const p = pill(card);
    if (!p) return;
    p.classList.remove("up", "down", "warn", "neutral");
    let cls = "neutral",
      txt = "--";
    if (state === "up") {
      cls = "up";
      txt = "UP";
    } else if (state === "down") {
      cls = "down";
      txt = "DOWN";
    } else if (state === "warn") {
      cls = "warn";
      txt = "WARN";
    }
    p.classList.add(cls);
    p.textContent = txt;

    // sync the leading state icon
    const icon = card.querySelector(".svc-state-icon");
    if (icon) {
      icon.classList.remove("up", "down", "warn", "neutral");
      icon.classList.add(cls);
      icon.title = txt;
    }

    const l = lat(card);
    if (l)
      l.textContent =
        latency != null && isFinite(latency) && latency > 0
          ? Math.round(latency) + " ms"
          : "";
  }

  function applyCounts() {
    const upEl = document.getElementById("svcUpCount");
    const totEl = document.getElementById("svcTotal");
    if (!upEl && !totEl) return;
    const cards = Array.from(
      document.querySelectorAll("#services .service-card"),
    );
    const up = cards.filter((c) => c.querySelector(".pill.up")).length;
    const total = cards.length;
    if (upEl) upEl.textContent = String(up);
    if (totEl) totEl.textContent = String(total);
  }

  function extractLatency(o) {
    if (!o || typeof o !== "object") return null;
    if (o.latency_ms != null) return Number(o.latency_ms);
    if (o.latency != null) return Number(o.latency);
    if (o.time_ms != null) return Number(o.time_ms);
    if (o.rt != null) return Number(o.rt);
    return null;
  }
  function extractStatus(o) {
    const s = norm(o?.status || o?.state || o?.result || o?.health);
    const lat = extractLatency(o);
    if (s) {
      if (/^(up|ok|online|open|alive|running|passing|success|good)$/.test(s))
        return { state: "up", latency: lat };
      if (/^(down|fail|failed|closed|offline|error|dead|critical)$/.test(s))
        return { state: "down", latency: lat };
      if (/^(warn|warning|degraded|slow|partial)$/.test(s))
        return { state: "warn", latency: lat };
    }
    const code = Number(o?.code || o?.http_code || o?.status_code);
    if (!Number.isNaN(code) && code > 0) {
      if (code >= 200 && code < 400) return { state: "up", latency: lat };
      if (code >= 500) return { state: "down", latency: lat };
      return { state: "warn", latency: lat };
    }
    if (typeof o?.up === "boolean")
      return { state: o.up ? "up" : "down", latency: lat };
    return { state: null, latency: lat };
  }
  function mapById(items) {
    const m = new Map();
    for (const it of items) {
      const k =
        it && (it.id != null ? String(it.id) : it.name ? norm(it.name) : null);
      if (!k) continue;
      const { state, latency } = extractStatus(it);
      m.set(k, { state: state || "down", latency });
    }
    return m;
  }
  async function fetchStatus() {
    try {
      const r = await fetch("api/services_status.php", { cache: "no-store" });
      if (!r.ok) throw 0;
      const j = await r.json();
      const arr = Array.isArray(j.results)
        ? j.results
        : Array.isArray(j.items)
          ? j.items
          : [];
      return mapById(arr);
    } catch (e) {
      return new Map();
    }
  }

  async function applyStatus() {
    const root = document.getElementById("services");
    if (!root) return;
    const map = await fetchStatus();
    const cards = Array.from(root.querySelectorAll(".service-card"));
    for (const c of cards) {
      const k = key(c);
      if (!k) continue;
      const rec = map.get(k);
      if (!rec) continue;
      setPill(c, rec.state, rec.latency);
    }
    applyCounts();
  }

  async function refreshAll() {
    await render();
    await applyStatus();
  }

  async function silenceRules(ids, minutes) {
    const res = await fetch(ALERTS_BULK, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        action: "silence",
        ids: ids,
        silence_minutes: minutes,
      }),
    });
    if (!res.ok) throw new Error("HTTP " + res.status);
    return res.json();
  }

  function handleCardActions(ev) {
    const btn = ev.target.closest("[data-action]");
    if (!btn || btn.dataset.action !== "mute") return;
    ev.preventDefault();
    const rules = (btn.dataset.rules || "")
      .split(",")
      .map((s) => s.trim())
      .filter(Boolean);
    if (!rules.length) {
      window.toast &&
        window.toast.warn &&
        window.toast.warn("No alert rules for this service.");
      return;
    }
    const card = btn.closest(".service-card");
    const svcName = card?.dataset?.svcName || card?.dataset?.svcId || "service";
    const minsInput = prompt(`Mute alerts for ${svcName} (minutes):`, "60");
    if (!minsInput) return;
    const mins = Math.max(1, parseInt(minsInput, 10) || 60);
    const prev = btn.textContent;
    btn.textContent = "Muting…";
    btn.classList.add("is-busy");
    silenceRules(rules, mins)
      .then(() => {
        window.toast &&
          window.toast.success &&
          window.toast.success(`Alerts muted for ${mins}m.`);
        refreshAll();
      })
      .catch((err) => {
        window.toast &&
          window.toast.error &&
          window.toast.error(
            "Mute failed: " + (err && err.message ? err.message : err),
          );
      })
      .finally(() => {
        btn.textContent = prev;
        btn.classList.remove("is-busy");
      });
  }

  // Hooks
  Bus.addEventListener("dashboard:tick", refreshAll);
  document.addEventListener("DOMContentLoaded", () => {
    servicesRoot = document.getElementById("services");
    servicesRoot?.addEventListener("click", handleCardActions);
    refreshAll();
  });
  window.addEventListener("services:probeUpdate", (e) => {
    const d = e?.detail || {};
    const arr = Array.isArray(d.results)
      ? d.results
      : Array.isArray(d.items)
        ? d.items
        : [];
    if (!arr.length) return;
    const map = mapById(arr);
    const root = document.getElementById("services");
    if (!root) return;
    const cards = Array.from(root.querySelectorAll(".service-card"));
    for (const c of cards) {
      const k = key(c);
      if (!k) continue;
      const rec = map.get(k);
      if (rec) setPill(c, rec.state, rec.latency);
    }
    applyCounts();
  });
})();
