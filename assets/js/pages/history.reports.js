(function () {
  "use strict";

  function $(selector, ctx) {
    return (ctx || document).querySelector(selector);
  }

  function esc(value) {
    return String(value == null ? "" : value).replace(
      /[&<>"']/g,
      function (char) {
        return {
          "&": "&amp;",
          "<": "&lt;",
          ">": "&gt;",
          '"': "&quot;",
          "'": "&#039;",
        }[char];
      },
    );
  }

  var root = $("#reportsRoot");
  if (!root) return;

  var monthInput = $("#reportMonth", root);
  var previewBtn = $("#reportPreviewBtn", root);
  var htmlBtn = $("#reportHtmlBtn", root);
  var csvBtn = $("#reportCsvBtn", root);
  var summary = $("#reportSummary", root);
  var tbody = $("#reportTable tbody", root);
  var jsonUrl =
    root.getAttribute("data-report-json") || "api/report_uptime.php";
  var htmlUrl =
    root.getAttribute("data-report-html") ||
    "api/report_uptime.php?format=html";
  var csvUrl =
    root.getAttribute("data-report-csv") || "api/report_uptime.php?format=csv";
  var modal = null;

  monthInput.value = new Date().toISOString().slice(0, 7);

  function ensureModal() {
    if (modal) return modal;

    var style = document.createElement("style");
    style.textContent = [
      ".history-report-modal{position:fixed;inset:0;background:rgba(8,12,18,.68);display:flex;align-items:center;justify-content:center;padding:1rem;z-index:9999}",
      ".history-report-modal[hidden]{display:none !important}",
      ".history-report-dialog{width:min(1100px,100%);height:min(82vh,820px);background:var(--card,#111);border:1px solid rgba(255,255,255,.12);border-radius:18px;display:grid;grid-template-rows:auto 1fr;overflow:hidden;box-shadow:0 24px 60px rgba(0,0,0,.34)}",
      ".history-report-head{display:flex;justify-content:space-between;gap:1rem;align-items:center;padding:.85rem 1rem;border-bottom:1px solid var(--border)}",
      ".history-report-body{padding:1rem;overflow:auto}",
      ".history-report-body iframe{width:100%;height:100%;border:0;border-radius:12px;background:#fff}",
    ].join("");
    document.head.appendChild(style);

    modal = document.createElement("div");
    modal.className = "history-report-modal";
    modal.hidden = true;
    modal.innerHTML = [
      '<div class="history-report-dialog">',
      '  <div class="history-report-head">',
      '    <strong id="historyReportTitle">Uptime Report</strong>',
      '    <button type="button" class="btn secondary" id="historyReportClose">Close</button>',
      "  </div>",
      '  <div class="history-report-body" id="historyReportBody"></div>',
      "</div>",
    ].join("");
    document.body.appendChild(modal);

    modal.addEventListener("click", function (event) {
      if (event.target === modal) {
        modal.hidden = true;
      }
    });
    document
      .getElementById("historyReportClose")
      .addEventListener("click", function () {
        modal.hidden = true;
      });
    document.addEventListener("keydown", function (event) {
      if (event.key === "Escape" && modal && !modal.hidden) {
        modal.hidden = true;
      }
    });

    return modal;
  }

  function openHtmlModal(url) {
    ensureModal();
    var body = document.getElementById("historyReportBody");
    body.innerHTML = "";
    var frame = document.createElement("iframe");
    frame.src = url;
    frame.loading = "lazy";
    body.appendChild(frame);
    modal.hidden = false;
  }

  function monthValue() {
    return monthInput.value || new Date().toISOString().slice(0, 7);
  }

  function buildUrl(base) {
    var url = new URL(base, window.location.href);
    url.searchParams.set("month", monthValue());
    return url.toString();
  }

  function renderRows(items) {
    if (!items || !items.length) {
      tbody.innerHTML =
        '<tr><td colspan="8" class="muted">No probe history was found for this month.</td></tr>';
      return;
    }
    tbody.innerHTML = items
      .map(function (item) {
        return [
          "<tr>",
          '<td data-value="' +
            esc(item.service_name || item.service_id || "") +
            '">' +
            esc(item.service_name || item.service_id || "") +
            "</td>",
          '<td data-value="' +
            esc(item.uptime_percent) +
            '">' +
            esc(item.uptime_percent) +
            "%</td>",
          '<td data-value="' +
            esc(item.coverage_percent) +
            '">' +
            esc(item.coverage_percent) +
            "%</td>",
          '<td data-value="' +
            esc(item.samples) +
            '">' +
            esc(item.samples) +
            "</td>",
          '<td data-value="' +
            esc(item.down_samples) +
            '">' +
            esc(item.down_samples) +
            "</td>",
          '<td data-value="' +
            esc(item.avg_latency_ms == null ? "" : item.avg_latency_ms) +
            '">' +
            esc(
              item.avg_latency_ms == null ? "n/a" : item.avg_latency_ms + " ms",
            ) +
            "</td>",
          '<td data-value="' +
            esc(item.max_latency_ms == null ? "" : item.max_latency_ms) +
            '">' +
            esc(item.max_latency_ms) +
            " ms</td>",
          '<td data-value="' +
            esc(item.last_status || "unknown") +
            '">' +
            esc(item.last_status || "unknown") +
            "</td>",
          "</tr>",
        ].join("");
      })
      .join("");
  }

  function preview() {
    fetch(buildUrl(jsonUrl), { credentials: "same-origin" })
      .then(function (response) {
        return response.text().then(function (text) {
          var payload = null;
          try {
            payload = JSON.parse(text);
          } catch (_) {}
          if (!response.ok || !payload || payload.ok === false) {
            throw new Error(
              (payload && payload.error) || "Unable to load report preview",
            );
          }
          return payload;
        });
      })
      .then(function (payload) {
        summary.textContent =
          "Overall time-weighted uptime " +
          payload.overall.sla_percent +
          "% with " +
          payload.overall.coverage_percent +
          "% coverage across " +
          payload.overall.samples +
          " samples for " +
          payload.overall.services +
          " services. Source: " +
          payload.source;
        renderRows(payload.items || []);
      })
      .catch(function (error) {
        summary.textContent = error.message;
        tbody.innerHTML =
          '<tr><td colspan="8" class="muted">Report preview failed.</td></tr>';
        if (window.toast && window.toast.error) {
          window.toast.error(error.message);
        }
      });
  }

  previewBtn.addEventListener("click", function () {
    preview();
  });

  htmlBtn.addEventListener("click", function () {
    openHtmlModal(buildUrl(htmlUrl));
  });

  csvBtn.addEventListener("click", function () {
    window.location.href = buildUrl(csvUrl);
  });

  preview();
})();
