(function () {
  "use strict";
  var root = document.getElementById("cronRoot");
  if (!root) return;

  var token = root.dataset.token || "";
  var endpoints = {
    alerts: root.dataset.alertUrl || "api/cron_mark.php?what=alerts",
    history: root.dataset.historyUrl || "api/cron_mark.php?what=history",
  };
  var heartbeatApi = root.dataset.heartbeatUrl || "api/cron_heartbeat.php";
  var alertsEval = root.dataset.alertsEval || "";
  var historyEval = root.dataset.historyEval || alertsEval;
  var baseUrl = root.dataset.baseUrl || window.location.origin;
  var refreshBtn = document.getElementById("cronRefresh");
  var copyAllBtn = document.getElementById("cronCopyAll");
  var statusNote = document.getElementById("cronStatusNote");
  var cronCore = document.getElementById("cronCore");
  var jobsList = document.getElementById("cronJobsList");
  var tokenField = document.getElementById("cronTokenField");
  var tokenToggle = document.getElementById("cronTokenToggle");
  var tokenCopy = document.getElementById("cronTokenCopy");
  var jobCache = new Map();
  var wizard = document.getElementById("cronWizard");
  var lastSeverity = "ok";
  var crontabList = document.getElementById("crontabList");
  var crontabRefresh = document.getElementById("crontabRefresh");
  var crontabNote = document.getElementById("crontabNote");

  function formatDate(ts) {
    if (!ts || ts <= 0) return "—";
    if (ts < 1e12) ts *= 1000;
    try {
      return new Date(ts).toLocaleString();
    } catch (_) {
      return String(ts);
    }
  }
  function formatAgo(ts) {
    if (!ts || ts <= 0) return "";
    if (ts > 1e12) ts = Math.floor(ts / 1000);
    var diff = Math.max(0, Math.floor(Date.now() / 1000) - ts);
    if (diff < 45) return "just now";
    var m = Math.floor(diff / 60);
    if (m < 60) return m + "m ago";
    var h = Math.floor(m / 60);
    if (h < 24) return h + "h ago";
    var d = Math.floor(h / 24);
    return d + "d ago";
  }
  function chipClass(status) {
    status = (status || "warn").toLowerCase();
    if (status !== "ok" && status !== "warn" && status !== "fail")
      status = "warn";
    return "chip " + status;
  }
  function buildUrl(key) {
    var raw = endpoints[key] || "";
    try {
      var u = new URL(raw, window.location.href);
      if (token && !u.searchParams.get("token"))
        u.searchParams.set("token", token);
      return u.toString();
    } catch (_) {
      if (!token) return raw;
      return (
        raw +
        (raw.indexOf("?") >= 0 ? "&" : "?") +
        "token=" +
        encodeURIComponent(token)
      );
    }
  }
  function buildCurl(key) {
    return 'curl -fsS "' + buildUrl(key) + '"';
  }
  function maskCurl(cmd) {
    if (!token) return cmd;
    return cmd.split(token).join("•••");
  }
  function copyText(text) {
    if (!text) return;
    try {
      navigator.clipboard.writeText(text);
      window.toast && window.toast.info && window.toast.info("Copied");
    } catch (_) {
      var area = document.createElement("textarea");
      area.value = text;
      document.body.appendChild(area);
      area.select();
      document.execCommand("copy");
      document.body.removeChild(area);
      window.toast && window.toast.info && window.toast.info("Copied");
    }
  }

  function setBusy(btn, on) {
    if (!btn) return;
    btn.dataset.orig = btn.dataset.orig || btn.textContent;
    btn.disabled = !!on;
    if (on) btn.textContent = "…";
    else btn.textContent = btn.dataset.orig;
  }

  function renderCore(data) {
    var expect = data.expect || {};
    var defs = [
      {
        key: "alerts",
        label: "Alerts evaluator",
        desc: "Runs alert rules and notifications.",
        expect: expect.alerts_min,
      },
      {
        key: "history",
        label: "History sampler",
        desc: "Writes probe samples for charts.",
        expect: expect.history_min,
      },
    ];
    defs.forEach(function (def) {
      var card = cronCore.querySelector(
        '.cron-card[data-core="' + def.key + '"]',
      );
      if (!card) return;
      var info = data[def.key] || {};
      card.classList.remove("skeleton");
      card.innerHTML = [
        '<div class="card-head">',
        '<div><div class="card-title">' + escapeHtml(def.label) + "</div>",
        '<div class="muted small">' + escapeHtml(def.desc) + "</div></div>",
        '<span class="' +
          chipClass(info.status) +
          '">' +
          escapeHtml((info.status || "warn").toUpperCase()) +
          "</span>",
        "</div>",
        '<div class="meta-grid">',
        '<div><span class="muted">Last run</span><strong>' +
          escapeHtml(formatDate(info.last)) +
          '</strong><span class="muted">' +
          escapeHtml(formatAgo(info.last)) +
          "</span></div>",
        '<div><span class="muted">Next due</span><strong>' +
          escapeHtml(formatDate(info.next_due)) +
          "</strong></div>",
        '<div><span class="muted">Expected every</span><strong>' +
          escapeHtml(def.expect ? def.expect + " min" : "—") +
          "</strong></div>",
        "</div>",
        '<div class="card-actions">',
        '<button class="btn secondary" data-copy="' +
          def.key +
          '">Copy cURL</button>',
        '<button class="btn ghost" data-ping="' +
          def.key +
          '">Ping now</button>',
        "</div>",
      ].join("");
    });
  }

  function renderJobs(list) {
    jobCache.clear();
    if (!list || !list.length) {
      jobsList.classList.add("empty");
      jobsList.innerHTML =
        '<div class="muted small">No custom jobs defined yet.</div>';
      return;
    }
    jobsList.classList.remove("empty");
    jobsList.innerHTML = "";
    list.forEach(function (job) {
      if (!job || !job.id) return;
      jobCache.set(job.id, job);
      var div = document.createElement("div");
      div.className = "cron-job";
      div.dataset.jobId = job.id;
      div.innerHTML = [
        '<div class="job-head">',
        "<div>",
        '<div class="job-title">' + escapeHtml(job.label || job.id) + "</div>",
        job.line
          ? '<code class="job-line">' + escapeHtml(job.line) + "</code>"
          : "",
        "</div>",
        '<span class="' +
          chipClass(job.status) +
          '">' +
          escapeHtml((job.status || "warn").toUpperCase()) +
          "</span>",
        "</div>",
        '<div class="job-grid">',
        '<div><span class="muted">Last heartbeat</span><strong>' +
          escapeHtml(formatDate(job.last)) +
          '</strong><span class="muted">' +
          escapeHtml(formatAgo(job.last)) +
          "</span></div>",
        '<div><span class="muted">Heartbeat file</span><code>' +
          escapeHtml(job.heartbeat || "—") +
          "</code></div>",
        '<div><span class="muted">Next due</span><strong>' +
          escapeHtml(formatDate(job.next_due)) +
          "</strong></div>",
        '<div><span class="muted">Stale after</span><strong>' +
          escapeHtml((job.stale_min || 5) + " min") +
          "</strong></div>",
        "</div>",
        '<div class="job-actions">',
        job.line
          ? '<button class="btn secondary" data-copy-line="' +
            job.id +
            '">Copy cron line</button>'
          : "",
        job.heartbeat
          ? '<button class="btn secondary" data-copy-heart="' +
            job.id +
            '">Copy heartbeat path</button>'
          : "",
        '<button class="btn secondary" data-copy-hearturl="' +
          job.id +
          '">Copy API URL</button>',
        "</div>",
      ].join("");
      jobsList.appendChild(div);
    });
  }

  function escapeHtml(str) {
    return (str == null ? "" : String(str)).replace(/[&<>"']/g, function (c) {
      return {
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#039;",
      }[c];
    });
  }

  function updateSnippets() {
    var alertsCode = document.getElementById("cronCurlAlerts");
    var histCode = document.getElementById("cronCurlHistory");
    if (alertsCode) alertsCode.textContent = maskCurl(buildCurl("alerts"));
    if (histCode) histCode.textContent = maskCurl(buildCurl("history"));
  }

  function buildHeartbeatUrl(jobId) {
    var base = heartbeatApi || "api/cron_heartbeat.php";
    try {
      var u = new URL(base, baseUrl || window.location.href);
      if (jobId) u.searchParams.set("id", jobId);
      if (token) u.searchParams.set("token", token);
      return u.toString();
    } catch (_) {
      return base;
    }
  }

  function checkHealthStatus(data) {
    var level = "ok";
    function apply(status) {
      if (!status) return;
      status = status.toLowerCase();
      if (status === "fail") level = "fail";
      else if (status === "warn" && level !== "fail") level = "warn";
    }
    if (data.alerts) apply(data.alerts.status);
    if (data.history) apply(data.history.status);
    (data.jobs || []).forEach(function (job) {
      apply(job.status);
    });
    if (level === lastSeverity) return;
    if (level === "ok" && lastSeverity !== "ok") {
      window.toast &&
        window.toast.success &&
        window.toast.success("Cron jobs recovered.");
    } else if (level === "warn") {
      window.toast &&
        window.toast.warn &&
        window.toast.warn("Cron jobs are delayed.");
    } else if (level === "fail") {
      window.toast &&
        window.toast.error &&
        window.toast.error("Cron jobs are failing or stale!");
    }
    lastSeverity = level;
  }

  function scheduleFromMinutes(min) {
    var m = Math.max(1, parseInt(min, 10) || 1);
    if (m >= 60) return "0 * * * *";
    return "*/" + m + " * * * *";
  }

  function buildEvalUrl(kind, limit) {
    var base = kind === "history" ? historyEval || alertsEval : alertsEval;
    if (!base) base = window.location.origin + "/api/alerts_eval.php";
    var u = new URL(base, baseUrl || window.location.href);
    if (token) u.searchParams.set("token", token);
    if (kind === "history") {
      u.searchParams.set("probe", "1");
    } else {
      u.searchParams.set("probe", "1");
      if (limit) u.searchParams.set("limit", limit);
    }
    return u.toString();
  }

  function buildWizardLines() {
    if (!wizard) return { alerts: "", history: "", job: "" };
    var alertsEvery = document.getElementById("wizardAlertsEvery");
    var historyEvery = document.getElementById("wizardHistoryEvery");
    var limitInput = document.getElementById("wizardLimit");
    var jobIdInput = document.getElementById("wizardJobId");
    var jobEvery = document.getElementById("wizardJobEvery");
    var limitVal = Math.max(
      100,
      parseInt(limitInput?.value || "5000", 10) || 5000,
    );
    var jobId = (jobIdInput?.value || "custom_job").trim() || "custom_job";
    var alertsLine =
      scheduleFromMinutes(alertsEvery?.value || 10) +
      ' curl -fsS "' +
      buildEvalUrl("alerts", limitVal) +
      '" >/dev/null 2>&1';
    var historyLine =
      scheduleFromMinutes(historyEvery?.value || 5) +
      ' curl -fsS "' +
      buildEvalUrl("history", limitVal) +
      '" >/dev/null 2>&1';
    var jobLine =
      scheduleFromMinutes(jobEvery?.value || 5) +
      ' curl -fsS "' +
      buildHeartbeatUrl(jobId) +
      '" >/dev/null 2>&1';
    return { alerts: alertsLine, history: historyLine, job: jobLine };
  }

  function updateWizardOutputs() {
    if (!wizard) return;
    var lines = buildWizardLines();
    var a = document.getElementById("wizardAlertsLine");
    var h = document.getElementById("wizardHistoryLine");
    var j = document.getElementById("wizardJobLine");
    if (a) a.textContent = lines.alerts;
    if (h) h.textContent = lines.history;
    if (j) j.textContent = lines.job;
  }

  function ping(key, btn) {
    setBusy(btn, true);
    fetch(buildUrl(key), { credentials: "same-origin" })
      .then(function (r) {
        return r.text().then(function (t) {
          try {
            return JSON.parse(t);
          } catch (_) {
            return null;
          }
        });
      })
      .then(function () {
        window.toast &&
          window.toast.success &&
          window.toast.success("Ping sent");
        load();
      })
      .catch(function (e) {
        window.toast &&
          window.toast.error &&
          window.toast.error("Ping failed: " + (e.message || e));
      })
      .finally(function () {
        setBusy(btn, false);
      });
  }

  function renderCrontab(items) {
    if (!crontabList) return;
    if (!items || !items.length) {
      crontabList.innerHTML =
        '<div class="muted small" style="padding:.75rem;">No crontab entries detected.</div>';
      return;
    }
    crontabList.innerHTML = "";
    items.forEach(function (line, idx) {
      var div = document.createElement("div");
      div.className = "crontab-line " + (line.type || "entry");
      var content = "";
      if (line.type === "comment" || line.type === "blank") {
        content = '<div class="muted small">' + escapeHtml(line.raw) + "</div>";
      } else if (line.type === "env") {
        content =
          '<div><strong>Env</strong></div><div class="cron-command">' +
          escapeHtml(line.raw) +
          "</div>";
      } else {
        content =
          '<div><span class="muted">Schedule</span><strong>' +
          escapeHtml(line.schedule || "") +
          "</strong></div>" +
          '<div class="cron-command">' +
          escapeHtml(line.command || line.raw || "") +
          "</div>" +
          '<div class="line-actions"><button class="btn secondary" data-copy-cronline="' +
          idx +
          '">Copy</button></div>';
      }
      div.innerHTML = content;
      div.dataset.raw = line.raw || "";
      crontabList.appendChild(div);
    });
  }

  function loadCrontab() {
    if (!crontabList) return;
    setBusy(crontabRefresh, true);
    fetch("api/cron_list.php?_=" + Date.now(), { credentials: "same-origin" })
      .then(function (r) {
        return r.json();
      })
      .then(function (data) {
        if (!data || !data.ok) {
          crontabList.innerHTML =
            '<div class="muted small" style="padding:.75rem;">' +
            escapeHtml(
              data && data.error ? data.error : "Unable to read crontab",
            ) +
            "</div>";
          if (crontabNote)
            crontabNote.textContent =
              "Failed to read crontab (exit " +
              ((data && data.exit) != null ? data.exit : "?") +
              ").";
          return;
        }
        renderCrontab(data.items || []);
        if (crontabNote)
          crontabNote.textContent =
            "Crontab entries for " + (data.user || "current user") + ".";
      })
      .catch(function (err) {
        crontabList.innerHTML =
          '<div class="muted small" style="padding:.75rem;">' +
          escapeHtml(err.message || err) +
          "</div>";
        if (crontabNote) crontabNote.textContent = "Failed to read crontab.";
      })
      .finally(function () {
        setBusy(crontabRefresh, false);
      });
  }

  function load() {
    if (refreshBtn) refreshBtn.disabled = true;
    fetch("api/cron_health.php?_=" + Date.now(), { credentials: "same-origin" })
      .then(function (r) {
        if (!r.ok) throw new Error("HTTP " + r.status);
        return r.json();
      })
      .then(function (data) {
        if (!data || !data.ok) throw new Error("Bad response");
        renderCore(data);
        renderJobs(data.jobs || []);
        if (statusNote) {
          statusNote.textContent =
            "Updated " + formatDate(data.now || Math.floor(Date.now() / 1000));
        }
        checkHealthStatus(data);
      })
      .catch(function (err) {
        console.error(err);
        window.toast &&
          window.toast.error &&
          window.toast.error(
            "Cron health load failed: " + (err.message || err),
          );
      })
      .finally(function () {
        if (refreshBtn) refreshBtn.disabled = false;
      });
  }

  root.addEventListener(
    "click",
    function (ev) {
      var target = ev.target;
      var copyKey = target.closest && target.closest("[data-copy]");
      if (copyKey) {
        ev.preventDefault();
        var key = copyKey.getAttribute("data-copy");
        if (key === "alerts" || key === "history") {
          copyText(buildCurl(key));
        }
        return;
      }
      var pingBtn = target.closest && target.closest("[data-ping]");
      if (pingBtn) {
        ev.preventDefault();
        var pk = pingBtn.getAttribute("data-ping");
        if (pk === "alerts" || pk === "history") ping(pk, pingBtn);
        return;
      }
      var lineBtn = target.closest && target.closest("[data-copy-line]");
      if (lineBtn) {
        ev.preventDefault();
        var job = jobCache.get(lineBtn.getAttribute("data-copy-line"));
        if (job && job.line) copyText(job.line);
        return;
      }
      var heartBtn = target.closest && target.closest("[data-copy-heart]");
      if (heartBtn) {
        ev.preventDefault();
        var job2 = jobCache.get(heartBtn.getAttribute("data-copy-heart"));
        if (job2 && job2.heartbeat) copyText(job2.heartbeat);
        return;
      }
      var heartUrlBtn =
        target.closest && target.closest("[data-copy-hearturl]");
      if (heartUrlBtn) {
        ev.preventDefault();
        var job3 = jobCache.get(heartUrlBtn.getAttribute("data-copy-hearturl"));
        var id = job3
          ? job3.id || ""
          : heartUrlBtn.getAttribute("data-copy-hearturl") || "";
        if (id) copyText(buildHeartbeatUrl(id));
        return;
      }
      var wizardCopy = target.closest && target.closest("[data-copy-wizard]");
      if (wizardCopy) {
        ev.preventDefault();
        var which = wizardCopy.getAttribute("data-copy-wizard");
        var lines = buildWizardLines();
        if (which && lines[which]) copyText(lines[which]);
        return;
      }
      var cronline = target.closest && target.closest("[data-copy-cronline]");
      if (cronline) {
        ev.preventDefault();
        var idx = parseInt(cronline.getAttribute("data-copy-cronline"), 10);
        var node =
          crontabList && crontabList.querySelectorAll(".crontab-line")[idx];
        if (node && node.dataset.raw) copyText(node.dataset.raw);
        return;
      }
    },
    { passive: false },
  );

  if (refreshBtn) {
    refreshBtn.addEventListener("click", function () {
      load();
    });
  }
  if (copyAllBtn) {
    copyAllBtn.addEventListener("click", function () {
      copyText(buildCurl("alerts") + "\n" + buildCurl("history"));
    });
  }
  if (tokenToggle && tokenField) {
    tokenToggle.addEventListener("click", function () {
      if (!tokenField.value || tokenField.value === "Not set") return;
      var nowType =
        tokenField.getAttribute("type") === "password" ? "text" : "password";
      tokenField.setAttribute("type", nowType);
      tokenToggle.textContent = nowType === "password" ? "Show" : "Hide";
    });
  }
  if (tokenCopy && tokenField) {
    tokenCopy.addEventListener("click", function () {
      if (!tokenField.value || tokenField.value === "Not set") return;
      copyText(tokenField.value);
    });
  }
  if (crontabRefresh) {
    crontabRefresh.addEventListener("click", function (e) {
      e.preventDefault();
      loadCrontab();
    });
  }

  if (wizard) {
    [
      "wizardAlertsEvery",
      "wizardHistoryEvery",
      "wizardLimit",
      "wizardJobId",
      "wizardJobEvery",
    ].forEach(function (id) {
      var el = document.getElementById(id);
      if (el)
        el.addEventListener("input", function () {
          updateWizardOutputs();
        });
    });
    updateWizardOutputs();
  }
  updateSnippets();
  load();
  loadCrontab();
})();
