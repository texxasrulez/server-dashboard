(function () {
  function $(sel, root) {
    return (root || document).querySelector(sel);
  }
  function esc(v) {
    return String(v == null ? "" : v);
  }
  function fmtRss(kb) {
    var n = Number(kb) || 0;
    if (n <= 0) return "0 KB";
    if (n >= 1024 * 1024) return (n / (1024 * 1024)).toFixed(2) + " GB";
    if (n >= 1024) return (n / 1024).toFixed(1) + " MB";
    return n.toFixed(0) + " KB";
  }
  function fmtUptime(sec) {
    var s = Number(sec);
    if (!isFinite(s) || s < 0) return "-";
    var d = Math.floor(s / 86400);
    var h = Math.floor((s % 86400) / 3600);
    var m = Math.floor((s % 3600) / 60);
    if (d > 0) return d + "d " + h + "h " + m + "m";
    return h + "h " + m + "m";
  }
  function cpuValue(proc) {
    if (!proc || typeof proc !== "object") return 0;
    var raw =
      proc.cpu_pct ??
      proc.cpu ??
      proc.cpu_percent ??
      proc.cpuPercentage ??
      proc.pcpu ??
      0;
    if (typeof raw === "string") {
      raw = raw.replace("%", "").trim();
    }
    var n = Number(raw);
    if (!isFinite(n)) return 0;
    return n;
  }
  function fmtCpu(proc) {
    return cpuValue(proc).toFixed(2) + "%";
  }
  function fmtState(state) {
    var s = String(state == null ? "" : state).trim();
    var code = s ? s.charAt(0).toUpperCase() : "?";
    var map = {
      R: "Running",
      S: "Sleeping",
      D: "Uninterruptible Sleep",
      T: "Stopped",
      t: "Tracing Stop",
      Z: "Zombie",
      X: "Dead",
      I: "Idle",
      W: "Paging",
      P: "Parked",
    };
    return map[code] || s || "Unknown";
  }

  var body = $("#procBody");
  var meta = $("#procMeta");
  var sortSel = $("#procSort");
  var limitSel = $("#procLimit");
  var filterInp = $("#procFilter");
  var modal = $("#procDetails");
  var modalDialog = $("#procDetails .proc-modal");
  var modalClose = $("#procDetailsClose");
  var detailGrid = $("#procDetailGrid");
  var detailCmdline = $("#procDetailCmdline");
  var table = $("#procTable");

  var refreshMs = 2000;
  var timer = null;
  var filterTimer = null;
  var lastRows = [];

  function queryParams() {
    var q = new URLSearchParams();
    q.set("sort", sortSel && sortSel.value ? sortSel.value : "cpu");
    q.set("limit", limitSel && limitSel.value ? limitSel.value : "50");
    var f = filterInp ? filterInp.value.trim() : "";
    if (f) q.set("filter", f);
    return q.toString();
  }

  function renderMeta(payload) {
    if (!meta) return;
    var load = payload && payload.loadavg ? payload.loadavg : null;
    var loadTxt = load
      ? "load: " +
        Number(load.l1 || 0).toFixed(2) +
        " " +
        Number(load.l5 || 0).toFixed(2) +
        " " +
        Number(load.l15 || 0).toFixed(2)
      : "load: n/a";
    var uptimeTxt = "uptime: " + fmtUptime(payload ? payload.uptime : null);
    var host = esc(payload && payload.host ? payload.host : "host");
    meta.textContent =
      host +
      " | " +
      loadTxt +
      " | " +
      uptimeTxt +
      " | updated " +
      new Date().toLocaleTimeString();
  }

  function renderRows(rows) {
    if (!body) return;
    lastRows = Array.isArray(rows) ? rows : [];
    body.innerHTML = "";

    if (!lastRows.length) {
      var tr = document.createElement("tr");
      tr.innerHTML =
        '<td colspan="6" class="muted">No processes matched this filter.</td>';
      body.appendChild(tr);
      return;
    }

    lastRows.forEach(function (p) {
      var tr = document.createElement("tr");
      tr.className = "proc-row";
      tr.dataset.pid = String(p.pid || "");
      tr.title = p.cmdline || p.cmd || "";
      tr.innerHTML =
        "" +
        "<td>" +
        esc(p.pid) +
        "</td>" +
        "<td>" +
        esc(p.user) +
        "</td>" +
        '<td class="num cpu-cell">' +
        fmtCpu(p) +
        "</td>" +
        '<td class="num">' +
        esc(fmtRss(p.rss_kb)) +
        "</td>" +
        "<td>" +
        esc(fmtState(p.state)) +
        "</td>" +
        '<td class="cmd-cell">' +
        esc(p.cmd || "") +
        "</td>";
      body.appendChild(tr);
    });
  }

  function showError(msg) {
    if (meta) meta.textContent = msg;
    if (!body) return;
    body.innerHTML =
      '<tr><td colspan="6" class="muted">' + esc(msg) + "</td></tr>";
  }

  function fetchProcesses() {
    var url = "api/processes.php?" + queryParams() + "&_=" + Date.now();
    return fetch(url, { credentials: "same-origin", cache: "no-store" }).then(
      function (r) {
        return r.text().then(function (t) {
          var j = null;
          try {
            j = JSON.parse(t);
          } catch (_e) {}
          if (!r.ok) {
            var err = j && j.error ? j.error : "HTTP " + r.status;
            throw new Error(err);
          }
          if (!j || j.ok !== true)
            throw new Error(j && j.error ? j.error : "Invalid API response");
          return j;
        });
      },
    );
  }

  function refresh() {
    if (document.hidden) return;
    fetchProcesses()
      .then(function (payload) {
        renderMeta(payload || {});
        renderRows((payload && payload.processes) || []);
      })
      .catch(function (err) {
        showError(
          "Processes API error: " + (err && err.message ? err.message : err),
        );
      });
  }

  function schedule() {
    if (timer) clearInterval(timer);
    timer = setInterval(refresh, refreshMs);
  }

  function queueRefresh() {
    if (filterTimer) clearTimeout(filterTimer);
    filterTimer = setTimeout(refresh, 250);
  }

  function detailRow(label, value) {
    return (
      '<div class="k">' +
      esc(label) +
      '</div><div class="v">' +
      esc(value) +
      "</div>"
    );
  }

  function forceModalStyles() {
    if (!modal || !modalDialog) return;
    modal.style.position = "fixed";
    modal.style.inset = "0";
    modal.style.display = "flex";
    modal.style.alignItems = "center";
    modal.style.justifyContent = "center";
    modal.style.padding = "1rem";
    modal.style.background = "rgba(0,0,0,.55)";
    modal.style.zIndex = "1200";

    modalDialog.style.position = "relative";
    modalDialog.style.left = "auto";
    modalDialog.style.top = "auto";
    modalDialog.style.transform = "none";
    modalDialog.style.width = "min(780px, 96vw)";
    modalDialog.style.maxHeight = "88vh";
    modalDialog.style.overflow = "auto";
    modalDialog.style.background = "var(--modal-bg, var(--card))";
    modalDialog.style.color = "var(--modal-fg, var(--fg))";
    modalDialog.style.border = "1px solid var(--modal-border, var(--border))";
    modalDialog.style.borderRadius = "16px";
    modalDialog.style.boxShadow = "0 20px 60px rgba(0,0,0,.5)";
  }

  function openDetails(pid) {
    var rec = null;
    for (var i = 0; i < lastRows.length; i++) {
      if (String(lastRows[i].pid) === String(pid)) {
        rec = lastRows[i];
        break;
      }
    }
    if (!rec || !modal) return;

    if (detailGrid) {
      detailGrid.innerHTML =
        "" +
        detailRow("PID", rec.pid || "") +
        detailRow("PPID", rec.ppid || "") +
        detailRow("User", rec.user || "") +
        detailRow("State", fmtState(rec.state)) +
        detailRow("CPU%", fmtCpu(rec)) +
        detailRow("RSS", fmtRss(rec.rss_kb)) +
        detailRow("Threads", rec.threads || 0);
    }
    if (detailCmdline) {
      detailCmdline.textContent = rec.cmdline || rec.cmd || "";
    }

    modal.hidden = false;
    forceModalStyles();
  }

  function syncHeaderSortState() {
    if (!table || !table.tHead || !table.tHead.rows.length) return;
    var current = sortSel && sortSel.value ? sortSel.value : "cpu";
    Array.prototype.forEach.call(table.tHead.rows[0].cells, function (th) {
      var key = th.getAttribute("data-sort-key") || "";
      th.classList.add("sortable");
      th.setAttribute("aria-sort", key === current ? "ascending" : "none");
    });
  }

  function handleHeaderSort(ev) {
    var th =
      ev.target && ev.target.closest ? ev.target.closest("th[data-sort-key]") : null;
    if (!th) return;
    if (ev.type === "keydown" && ev.key !== "Enter" && ev.key !== " ") return;
    ev.preventDefault();
    var key = th.getAttribute("data-sort-key") || "";
    if (!key || !sortSel) return;
    sortSel.value = key;
    syncHeaderSortState();
    refresh();
  }

  if (sortSel) sortSel.addEventListener("change", refresh);
  if (limitSel) limitSel.addEventListener("change", refresh);
  if (filterInp) filterInp.addEventListener("input", queueRefresh);
  if (sortSel) sortSel.addEventListener("change", syncHeaderSortState);
  if (table && table.tHead) {
    table.tHead.addEventListener("click", handleHeaderSort);
    table.tHead.addEventListener("keydown", handleHeaderSort);
    syncHeaderSortState();
  }
  if (body) {
    body.addEventListener("click", function (ev) {
      var tr =
        ev.target && ev.target.closest
          ? ev.target.closest("tr.proc-row")
          : null;
      if (!tr) return;
      openDetails(tr.dataset.pid || "");
    });
  }
  if (modalClose)
    modalClose.addEventListener("click", function () {
      if (modal) modal.hidden = true;
    });
  if (modal) {
    modal.addEventListener("click", function (ev) {
      if (ev.target === modal) modal.hidden = true;
    });
    forceModalStyles();
  }

  document.addEventListener("visibilitychange", function () {
    if (!document.hidden) refresh();
  });

  document.addEventListener("DOMContentLoaded", function () {
    syncHeaderSortState();
    refresh();
    schedule();
  });
})();
