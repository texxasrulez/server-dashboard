(function () {
  "use strict";

  function $(sel, ctx) {
    return (ctx || document).querySelector(sel);
  }

  function escapeHtml(value) {
    return String(value == null ? "" : value).replace(/[&<>"']/g, function (c) {
      return {
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#039;",
      }[c];
    });
  }

  function fmt(value, unit) {
    if (value == null || value === "") return "—";
    var num = Number(value);
    if (!isFinite(num)) return "—";
    var out = num >= 100 ? num.toFixed(0) : num.toFixed(2).replace(/\.00$/, "");
    return unit ? out + " " + unit : out;
  }

  function fmtDate(value) {
    if (!value) return "—";
    try {
      if (typeof value === "number" && isFinite(value)) {
        return new Date(value < 1000000000000 ? value * 1000 : value).toLocaleString();
      }
      if (typeof value === "string" && /^\d+$/.test(value)) {
        var numeric = Number(value);
        return new Date(numeric < 1000000000000 ? numeric * 1000 : numeric).toLocaleString();
      }
      return new Date(value).toLocaleString();
    } catch (e) {
      return String(value);
    }
  }

  function qs(root) {
    return {
      range: $("#speedtestRange", root).value,
      server: $("#speedtestServer", root).value,
      include_failed: $("#speedtestIncludeFailed", root).checked ? "1" : "0",
    };
  }

  function withParams(url, params) {
    var u = new URL(url, window.location.href);
    Object.keys(params).forEach(function (key) {
      if (params[key] === "") return;
      u.searchParams.set(key, params[key]);
    });
    return u.toString();
  }

  function signalSpeedtestRefresh() {
    try {
      window.localStorage.setItem("speedtest-refresh-at", String(Date.now()));
    } catch (_) {}
  }

  function getPageSize(root) {
    var stored = Number(root.__speedtestPageSize);
    if (isFinite(stored) && stored > 0) return stored;
    var select = $("#speedtestPageSize", root);
    var fallback = Number(select && select.value) || 25;
    root.__speedtestPageSize = fallback;
    return fallback;
  }

  function setPageSize(root, value) {
    var next = Number(value) || 25;
    root.__speedtestPageSize = next;
    var select = $("#speedtestPageSize", root);
    if (select) {
      select.value = String(next);
    }
    try {
      window.localStorage.setItem("speedtest-page-size", String(next));
    } catch (_) {}
  }

  function setState(root, state, text) {
    $("#speedtestLoading", root).hidden = state !== "loading";
    $("#speedtestError", root).hidden = state !== "error";
    $("#speedtestEmpty", root).hidden = state !== "empty";
    $("#speedtestContent", root).hidden = state !== "ready";
    if (state !== "ready" && $("#speedtestPagination", root)) {
      $("#speedtestPagination", root).hidden = true;
    }
    if (state === "error") {
      $("#speedtestError", root).textContent = text || "Failed to load speedtest history.";
    }
  }

  function statusClass(status) {
    status = String(status || "").toLowerCase();
    if (status === "success") return "summary-status-success";
    if (status === "failure") return "summary-status-failure";
    return "";
  }

  function renderSummary(root, summary) {
    var cards = [
      {
        label: "Latest result",
        value: summary.latest_result ? summary.latest_result.status.toUpperCase() : "—",
        valueClass: summary.latest_result ? statusClass(summary.latest_result.status) : "",
        meta: summary.latest_result ? fmtDate(summary.latest_result.timestamp) : "No tests yet",
      },
      { label: "Latest download", value: fmt(summary.latest_download_mbps, "Mbps"), meta: "" },
      { label: "Latest upload", value: fmt(summary.latest_upload_mbps, "Mbps"), meta: "" },
      { label: "Latest ping", value: fmt(summary.latest_ping_ms, "ms"), meta: "" },
      { label: "Average download", value: fmt(summary.average_download_mbps, "Mbps"), meta: "Selected range" },
      { label: "Average upload", value: fmt(summary.average_upload_mbps, "Mbps"), meta: "Selected range" },
      [
        "Best result",
        summary.best_result ? fmt(summary.best_result.download_mbps, "Mbps") : "—",
        summary.best_result ? fmtDate(summary.best_result.timestamp) : "",
      ],
      [
        "Worst result",
        summary.worst_result ? fmt(summary.worst_result.download_mbps, "Mbps") : "—",
        summary.worst_result ? fmtDate(summary.worst_result.timestamp) : "",
      ],
      ["Success count", String(summary.success_count || 0), ""],
      ["Failure count", String(summary.failure_count || 0), ""],
      ["Last successful test", summary.last_successful_test ? fmtDate(summary.last_successful_test) : "—", ""],
      ["Next scheduled test", summary.next_scheduled_test ? fmtDate(summary.next_scheduled_test) : "—", ""],
    ];
    $("#speedtestSummary", root).innerHTML = cards
      .map(function (card) {
        if (Array.isArray(card)) {
          card = { label: card[0], value: card[1], meta: card[2] || "", valueClass: "" };
        }
        return (
          '<article class="summary-card">' +
          '<div class="summary-label">' +
          escapeHtml(card.label) +
          "</div>" +
          '<div class="summary-value ' + escapeHtml(card.valueClass || "") + '">' +
          escapeHtml(card.value) +
          "</div>" +
          '<div class="summary-meta">' +
          escapeHtml(card.meta || "") +
          "</div>" +
          "</article>"
        );
      })
      .join("");
  }

  function ensureChartTooltip(root) {
    if (root.__speedtestChartTooltip) return root.__speedtestChartTooltip;
    var tip = document.createElement("div");
    tip.className = "speedtest-chart-tooltip";
    tip.hidden = true;
    document.body.appendChild(tip);
    root.__speedtestChartTooltip = tip;
    return tip;
  }

  function hideChartTooltip(root) {
    var tip = ensureChartTooltip(root);
    tip.hidden = true;
  }

  function showChartTooltip(root, event, meta) {
    var tip = ensureChartTooltip(root);
    tip.innerHTML =
      '<div class="tooltip-title">' + escapeHtml(meta.label) + "</div>" +
      '<div><strong>' + escapeHtml(fmt(meta.point.value, meta.unit)) + "</strong></div>" +
      '<div>' + escapeHtml(fmtDate(meta.point.timestamp)) + "</div>" +
      '<div>Status: ' + escapeHtml(String(meta.point.status || "—").toUpperCase()) + "</div>";
    tip.hidden = false;
    tip.style.left = event.pageX + 14 + "px";
    tip.style.top = event.pageY + 14 + "px";
  }

  function pointDistance(a, b, x, y) {
    var dx = a - x;
    var dy = b - y;
    return Math.sqrt(dx * dx + dy * dy);
  }

  function findNearestPoint(chartData, x, y) {
    var best = null;
    (chartData.plotPoints || []).forEach(function (point) {
      var dist = pointDistance(point.x, point.y, x, y);
      if (dist > 16) return;
      if (!best || dist < best.dist) {
        best = { point: point.point, x: point.x, y: point.y, dist: dist };
      }
    });
    return best;
  }

  function bindChartCanvas(root, canvas) {
    if (!canvas || canvas.dataset.bound === "1") return;
    canvas.dataset.bound = "1";
    canvas.addEventListener("mousemove", function (event) {
      var rect = canvas.getBoundingClientRect();
      var chartData = canvas.__chartData;
      if (!chartData) return;
      var hover = findNearestPoint(chartData, event.clientX - rect.left, event.clientY - rect.top);
      if (!hover) {
        canvas.__hoverTs = null;
        hideChartTooltip(root);
        renderCharts(root, root.__speedtestPayload.charts || {});
        return;
      }
      canvas.__hoverTs = hover.point.ts;
      showChartTooltip(root, event, { label: chartData.label, unit: chartData.unit, point: hover.point });
      renderCharts(root, root.__speedtestPayload.charts || {});
    });
    canvas.addEventListener("mouseleave", function () {
      canvas.__hoverTs = null;
      hideChartTooltip(root);
      renderCharts(root, root.__speedtestPayload.charts || {});
    });
    canvas.addEventListener("wheel", function (event) {
      var chartData = canvas.__chartData;
      if (!chartData || !chartData.plotPoints || chartData.plotPoints.length < 2) return;
      event.preventDefault();
      var rect = canvas.getBoundingClientRect();
      var x = event.clientX - rect.left;
      var ratio = (x - chartData.padding.left) / (chartData.width - chartData.padding.left - chartData.padding.right);
      var clamped = Math.max(0, Math.min(1, ratio));
      var span = chartData.maxX - chartData.minX;
      var center = chartData.minX + span * clamped;
      var factor = event.deltaY < 0 ? 0.75 : 1.35;
      var newSpan = Math.max(60, Math.min(chartData.fullMaxX - chartData.fullMinX, span * factor));
      var newMin = center - newSpan * clamped;
      var newMax = center + newSpan * (1 - clamped);
      if (newMin < chartData.fullMinX) {
        newMax += chartData.fullMinX - newMin;
        newMin = chartData.fullMinX;
      }
      if (newMax > chartData.fullMaxX) {
        newMin -= newMax - chartData.fullMaxX;
        newMax = chartData.fullMaxX;
      }
      root.__speedtestChartZooms = root.__speedtestChartZooms || {};
      root.__speedtestChartZooms[chartData.key] = { minX: newMin, maxX: newMax };
      renderCharts(root, root.__speedtestPayload.charts || {});
    }, { passive: false });
    canvas.addEventListener("dblclick", function () {
      var chartData = canvas.__chartData;
      if (!chartData) return;
      root.__speedtestChartZooms = root.__speedtestChartZooms || {};
      delete root.__speedtestChartZooms[chartData.key];
      renderCharts(root, root.__speedtestPayload.charts || {});
    });
  }

  function drawSeries(root, canvas, key, label, unit, points, color) {
    if (!canvas) return;
    bindChartCanvas(root, canvas);
    var ctx = canvas.getContext("2d");
    var dpr = window.devicePixelRatio || 1;
    var w = Math.max(280, canvas.clientWidth || canvas.width || 320);
    var h = Math.max(220, canvas.clientHeight || canvas.height || 220);
    canvas.width = Math.floor(w * dpr);
    canvas.height = Math.floor(h * dpr);
    canvas.style.height = h + "px";
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, w, h);

    var usable = points.filter(function (point) {
      return point && point.value != null && isFinite(Number(point.value));
    });
    if (!usable.length) {
      canvas.__chartData = null;
      ctx.fillStyle = "rgba(255,255,255,.55)";
      ctx.font = "13px sans-serif";
      ctx.fillText("No data in this range", 14, 28);
      return;
    }

    var padding = { top: 16, right: 10, bottom: 24, left: 44 };
    var allXs = usable.map(function (point) {
      return Number(point.ts || 0);
    });
    var fullMinX = Math.min.apply(null, allXs);
    var fullMaxX = Math.max.apply(null, allXs);
    var zoom = root.__speedtestChartZooms && root.__speedtestChartZooms[key];
    if (zoom && isFinite(zoom.minX) && isFinite(zoom.maxX)) {
      usable = usable.filter(function (point) {
        var ts = Number(point.ts || 0);
        return ts >= zoom.minX && ts <= zoom.maxX;
      });
    }
    if (!usable.length) {
      usable = points.filter(function (point) {
        return point && point.value != null && isFinite(Number(point.value));
      });
    }
    var xs = usable.map(function (point) {
      return Number(point.ts || 0);
    });
    var ys = usable.map(function (point) {
      return Number(point.value);
    });
    var minX = Math.min.apply(null, xs);
    var maxX = Math.max.apply(null, xs);
    var minY = Math.min.apply(null, ys);
    var maxY = Math.max.apply(null, ys);
    if (minX === maxX) maxX = minX + 1;
    if (minY === maxY) maxY = minY + 1;

    function px(ts) {
      return padding.left + ((ts - minX) / (maxX - minX)) * (w - padding.left - padding.right);
    }
    function py(val) {
      return h - padding.bottom - ((val - minY) / (maxY - minY)) * (h - padding.top - padding.bottom);
    }

    ctx.strokeStyle = "rgba(255,255,255,.08)";
    ctx.lineWidth = 1;
    ctx.beginPath();
    for (var i = 0; i < 4; i++) {
      var y = padding.top + ((h - padding.top - padding.bottom) / 3) * i;
      ctx.moveTo(padding.left, y);
      ctx.lineTo(w - padding.right, y);
    }
    ctx.stroke();

    ctx.fillStyle = "rgba(255,255,255,.65)";
    ctx.font = "12px sans-serif";
    ctx.fillText(String(maxY.toFixed(2).replace(/\.00$/, "")), 6, padding.top + 2);
    ctx.fillText(String(minY.toFixed(2).replace(/\.00$/, "")), 6, h - padding.bottom);
    ctx.fillStyle = "rgba(255,255,255,.42)";
    ctx.fillText("Wheel to zoom, double-click to reset", padding.left, h - 6);

    ctx.strokeStyle = color;
    ctx.lineWidth = 2;
    ctx.beginPath();
    var plotPoints = [];
    usable.forEach(function (point, index) {
      var x = px(Number(point.ts || 0));
      var y = py(Number(point.value));
      plotPoints.push({ x: x, y: y, point: point });
      if (index === 0) ctx.moveTo(x, y);
      else ctx.lineTo(x, y);
    });
    ctx.stroke();

    ctx.fillStyle = color;
    usable.forEach(function (point) {
      var x = px(Number(point.ts || 0));
      var y = py(Number(point.value));
      ctx.beginPath();
      ctx.arc(x, y, canvas.__hoverTs === point.ts ? 4.5 : 2.5, 0, Math.PI * 2);
      ctx.fill();
    });
    canvas.__chartData = {
      key: key,
      label: label,
      unit: unit,
      minX: minX,
      maxX: maxX,
      fullMinX: fullMinX,
      fullMaxX: fullMaxX,
      padding: padding,
      width: w,
      height: h,
      plotPoints: plotPoints,
    };
  }

  function renderCharts(root, charts) {
    root.__speedtestChartZooms = root.__speedtestChartZooms || {};
    drawSeries(root, $("#chartDownload", root), "download", "Download Mbps", "Mbps", charts.download || [], "#62c97d");
    drawSeries(root, $("#chartUpload", root), "upload", "Upload Mbps", "Mbps", charts.upload || [], "#6ab7ff");
    drawSeries(root, $("#chartPing", root), "ping", "Ping ms", "ms", charts.ping || [], "#ffd166");
    drawSeries(root, $("#chartJitter", root), "jitter", "Jitter ms", "ms", charts.jitter || [], "#f78c6c");
    var packetLoss = charts.packet_loss || [];
    $("#packetLossCard", root).hidden = !packetLoss.some(function (point) {
      return point && point.value != null;
    });
    drawSeries(root, $("#chartPacketLoss", root), "packet_loss", "Packet loss %", "%", packetLoss, "#ff7b9c");
  }

  function renderTable(root, rows) {
    var tbody = $("#speedtestTableBody", root);
    var sortedRows = (rows || []).slice().sort(function (a, b) {
      return Number(b && b.timestamp_ts ? b.timestamp_ts : 0) - Number(a && a.timestamp_ts ? a.timestamp_ts : 0);
    });
    var pageSize = getPageSize(root);
    var totalPages = Math.max(1, Math.ceil(sortedRows.length / pageSize));
    root.__speedtestPage = Math.max(1, Math.min(root.__speedtestPage || 1, totalPages));
    var pageStart = (root.__speedtestPage - 1) * pageSize;
    var pageRows = sortedRows.slice(pageStart, pageStart + pageSize);
    tbody.innerHTML = pageRows
      .map(function (row) {
        var server = row.server_label || row.server_id || "—";
        var serverParts = [];
        if (row.server_name) serverParts.push('<div><strong>' + escapeHtml(row.server_name) + "</strong></div>");
        if (row.server_location) serverParts.push('<div class="muted small">' + escapeHtml(row.server_location) + "</div>");
        if (row.server_id) serverParts.push('<div class="muted small">ID: ' + escapeHtml(row.server_id) + "</div>");
        var serverCell = serverParts.length ? serverParts.join("") : "—";
        return (
          "<tr>" +
          '<td data-value="' + escapeHtml(row.timestamp_ts) + '">' + escapeHtml(fmtDate(row.timestamp)) + "</td>" +
          '<td data-value="' + escapeHtml(row.status) + '"><span class="status-pill status-' + escapeHtml(row.status) + '">' + escapeHtml(row.status) + "</span></td>" +
          '<td data-value="' + escapeHtml(row.backend || "") + '">' + escapeHtml(row.backend || "—") + "</td>" +
          '<td data-value="' + escapeHtml(server) + '">' + serverCell + "</td>" +
          '<td data-value="' + escapeHtml(row.ping_ms == null ? "" : row.ping_ms) + '">' + escapeHtml(fmt(row.ping_ms, "ms")) + "</td>" +
          '<td data-value="' + escapeHtml(row.jitter_ms == null ? "" : row.jitter_ms) + '">' + escapeHtml(fmt(row.jitter_ms, "ms")) + "</td>" +
          '<td data-value="' + escapeHtml(row.download_mbps == null ? "" : row.download_mbps) + '">' + escapeHtml(fmt(row.download_mbps, "Mbps")) + "</td>" +
          '<td data-value="' + escapeHtml(row.upload_mbps == null ? "" : row.upload_mbps) + '">' + escapeHtml(fmt(row.upload_mbps, "Mbps")) + "</td>" +
          '<td data-value="' + escapeHtml(row.packet_loss == null ? "" : row.packet_loss) + '">' + escapeHtml(fmt(row.packet_loss, "%")) + "</td>" +
          '<td data-value="' + escapeHtml(row.duration_ms == null ? "" : row.duration_ms) + '">' + escapeHtml(fmt(row.duration_ms, "ms")) + "</td>" +
          '<td data-value="' + escapeHtml(row.raw_tool_version || "") + '">' + escapeHtml(row.raw_tool_version || "—") + "</td>" +
          '<td data-value="' + escapeHtml(row.error_message || "") + '">' + escapeHtml(row.error_message || "—") + "</td>" +
          "</tr>"
        );
      })
      .join("");
    var pagination = $("#speedtestPagination", root);
    if (pagination) {
      pagination.hidden = sortedRows.length <= pageSize;
    }
    if ($("#speedtestPageInfo", root)) {
      $("#speedtestPageInfo", root).textContent = "Page " + root.__speedtestPage + " of " + totalPages + " • " + sortedRows.length + " rows";
    }
    if ($("#speedtestPrevPage", root)) {
      $("#speedtestPrevPage", root).disabled = root.__speedtestPage <= 1;
    }
    if ($("#speedtestNextPage", root)) {
      $("#speedtestNextPage", root).disabled = root.__speedtestPage >= totalPages;
    }
    if (window.SortableTable && typeof window.SortableTable.init === "function") {
      window.SortableTable.init("#speedtestTable");
    }
  }

  function updateServerOptions(root, currentValue, servers) {
    var select = $("#speedtestServer", root);
    var previous = currentValue || select.value || "";
    select.innerHTML = '<option value="">All servers</option>' +
      (servers || [])
        .map(function (server) {
          return '<option value="' + escapeHtml(server.value) + '">' + escapeHtml(server.label) + "</option>";
        })
        .join("");
    select.value = previous;
    if (select.value !== previous) {
      select.value = "";
      return true;
    }
    return false;
  }

  function renderWarning(root, invalidLines) {
    var box = $("#speedtestWarning", root);
    if (!invalidLines) {
      box.hidden = true;
      box.textContent = "";
      return;
    }
    box.hidden = false;
    box.textContent = invalidLines + " history line(s) were skipped because they were unreadable.";
  }

  function render(root, json, currentServer) {
    var resetServer = updateServerOptions(root, currentServer, json.servers || []);
    if (resetServer && currentServer) {
      load(root);
      return;
    }
    root.__speedtestPage = 1;
    renderWarning(root, json.invalid_lines || 0);
    if (!json.rows || !json.rows.length) {
      setState(root, "empty");
      return;
    }
    renderSummary(root, json.summary || {});
    renderCharts(root, json.charts || {});
    renderTable(root, json.rows || []);
    setState(root, "ready");
  }

  function load(root) {
    var params = qs(root);
    setState(root, "loading");
    fetch(withParams(root.dataset.historyUrl, params), {
      credentials: "same-origin",
    })
      .then(function (response) {
        return response.json().then(function (json) {
          if (!response.ok || !json.ok) {
            throw new Error((json && json.error) || "Request failed");
          }
          return json;
        });
      })
      .then(function (json) {
        root.__speedtestPayload = json;
        render(root, json, params.server);
      })
      .catch(function (error) {
        setState(root, "error", error.message || "Failed to load speedtest history.");
      });
  }

  function bind(root) {
    ["#speedtestServer", "#speedtestIncludeFailed"].forEach(function (sel) {
      $(sel, root).addEventListener("change", function () {
        load(root);
      });
    });
    $("#speedtestRange", root).addEventListener("change", function () {
      $("#speedtestServer", root).value = "";
      load(root);
    });
    $("#speedtestRefresh", root).addEventListener("click", function () {
      load(root);
    });
    $("#speedtestRunNow", root).addEventListener("click", function () {
      var btn = $("#speedtestRunNow", root);
      btn.disabled = true;
      fetch(root.dataset.runUrl, {
        method: "POST",
        credentials: "same-origin",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8",
          "X-CSRF-Token": root.dataset.csrf || "",
        },
        body: "_csrf=" + encodeURIComponent(root.dataset.csrf || ""),
      })
        .then(function (response) {
          return response.json().catch(function () {
            return null;
          });
        })
        .then(function (json) {
          if (!json || !json.ok) {
            throw new Error((json && json.error) || "Speedtest run failed");
          }
          signalSpeedtestRefresh();
          load(root);
          if (json && json.queued) {
            window.setTimeout(function () {
              signalSpeedtestRefresh();
              load(root);
            }, 2000);
            window.setTimeout(function () {
              signalSpeedtestRefresh();
              load(root);
            }, 7000);
          }
          window.toast &&
            window.toast.success &&
            window.toast.success((json && json.queued) ? "Speedtest run started." : "Speedtest run completed.");
        })
        .catch(function (error) {
          window.toast &&
            window.toast.error &&
            window.toast.error(error.message || "Speedtest run failed");
        })
        .then(function () {
          btn.disabled = false;
        }, function () {
          btn.disabled = false;
        });
    });
    $("#speedtestExport", root).addEventListener("click", function () {
      window.location.href = withParams(root.dataset.exportUrl, qs(root));
    });
    $("#speedtestPageSize", root).addEventListener("change", function () {
      root.__speedtestPage = 1;
      setPageSize(root, this.value);
      if (root.__speedtestPayload) {
        renderTable(root, root.__speedtestPayload.rows || []);
      }
    });
    $("#speedtestPrevPage", root).addEventListener("click", function () {
      root.__speedtestPage = Math.max(1, (root.__speedtestPage || 1) - 1);
      if (root.__speedtestPayload) {
        renderTable(root, root.__speedtestPayload.rows || []);
      }
    });
    $("#speedtestNextPage", root).addEventListener("click", function () {
      root.__speedtestPage = (root.__speedtestPage || 1) + 1;
      if (root.__speedtestPayload) {
        renderTable(root, root.__speedtestPayload.rows || []);
      }
    });
    window.addEventListener("storage", function (event) {
      if (event && event.key === "speedtest-refresh-at") {
        load(root);
      }
    });
    document.addEventListener("visibilitychange", function () {
      if (!document.hidden) {
        load(root);
      }
    });
    window.setInterval(function () {
      if (!document.hidden) {
        load(root);
      }
    }, 60000);
    window.addEventListener("resize", function () {
      if (root.__speedtestPayload && !$("#speedtestContent", root).hidden) {
        renderCharts(root, root.__speedtestPayload.charts || {});
      }
    });
  }

  document.addEventListener("DOMContentLoaded", function () {
    var root = document.getElementById("speedtest-root");
    if (!root) return;
    try {
      setPageSize(root, window.localStorage.getItem("speedtest-page-size") || 25);
    } catch (_) {
      setPageSize(root, 25);
    }
    bind(root);
    load(root);
  });
})();
