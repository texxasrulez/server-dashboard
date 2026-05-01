(function () {
  "use strict";

  function $(sel, ctx) {
    return (ctx || document).querySelector(sel);
  }

  function t(key, fallback, vars) {
    if (window.I18N && typeof window.I18N.t === "function") {
      return window.I18N.t(key, fallback, vars);
    }
    return fallback != null ? fallback : key;
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

  function withParams(url, params) {
    var u = new URL(url, window.location.href);
    Object.keys(params).forEach(function (key) {
      if (params[key] == null || params[key] === "") return;
      u.searchParams.set(key, params[key]);
    });
    return u.toString();
  }

  function fmtDate(value) {
    if (!value) return "—";
    try {
      return new Date(value).toLocaleString();
    } catch (_) {
      return String(value);
    }
  }

  function fmtInt(value) {
    if (value == null || value === "") return "—";
    var num = Number(value);
    if (!isFinite(num)) return "—";
    return num.toLocaleString();
  }

  function fmtFloat(value, digits) {
    if (value == null || value === "") return "—";
    var num = Number(value);
    if (!isFinite(num)) return "—";
    return num.toFixed(digits == null ? 2 : digits).replace(/\.00$/, "").replace(/(\.\d*[1-9])0+$/, "$1");
  }

  function fmtPercent(value) {
    if (value == null || value === "") return "—";
    var num = Number(value);
    if (!isFinite(num)) return "—";
    return num.toFixed(0) + "%";
  }

  function fmtPercentDelta(value) {
    if (value == null || value === "") return "—";
    var num = Number(value);
    if (!isFinite(num)) return "—";
    return (num > 0 ? "+" : "") + num.toFixed(1).replace(/\.0$/, "") + " pts";
  }

  function fmtTemp(value) {
    if (value == null || value === "") return "—";
    var num = Number(value);
    if (!isFinite(num)) return "—";
    return num.toFixed(0) + " C";
  }

  function fmtDays(value) {
    if (value == null || value === "") return "—";
    var num = Number(value);
    if (!isFinite(num)) return "—";
    if (num < 1) return t("drive_health.units.less_than_one_day", "<1 day");
    if (num < 30) return t("drive_health.units.days", "{count} days", { count: Math.round(num) });
    if (num < 365) return t("drive_health.units.months_short", "{count} mo", { count: fmtFloat(num / 30, 1) });
    return t("drive_health.units.years_short", "{count} yr", { count: fmtFloat(num / 365, 1) });
  }

  function fmtBytes(value) {
    if (value == null || value === "") return "—";
    var num = Number(value);
    if (!isFinite(num) || num < 0) return "—";
    var units = ["B", "KB", "MB", "GB", "TB", "PB"];
    var unit = 0;
    while (num >= 1024 && unit < units.length - 1) {
      num = num / 1024;
      unit += 1;
    }
    var digits = unit >= 3 ? 2 : 1;
    return num.toFixed(digits).replace(/\.0+$/, "").replace(/(\.\d*[1-9])0+$/, "$1") + " " + units[unit];
  }

  function insightValue(item, formatter) {
    if (!item || item.status !== "ok") {
      return '<span class="muted">' + escapeHtml(t("drive_health.insights.not_enough_history", "Not enough history yet")) + "</span>";
    }
    return escapeHtml(formatter(item.value != null ? item.value : item.days != null ? item.days : item.wear_delta));
  }

  function projectionText(item) {
    if (!item) return t("drive_health.insights.not_enough_history", "Not enough history yet");
    if (item.status === "reached") return t("drive_health.insights.reached", "Reached");
    if (item.status !== "ok") return t("drive_health.insights.not_enough_history", "Not enough history yet");
    return fmtDays(item.days) + " (" + fmtDate(item.eta) + ")";
  }

  function metricLabel(key) {
    if (key === "percentage_used") return t("drive_health.metrics.percentage_used", "Percentage Used");
    if (key === "power_on_hours") return t("drive_health.metrics.power_on_hours", "Power On Hours");
    if (key === "data_units_written_bytes") return t("drive_health.metrics.data_written", "Data Written");
    if (key === "temperature_c") return t("drive_health.metrics.temperature", "Temperature");
    return key;
  }

  function metricFormatter(key, value) {
    if (key === "percentage_used") return fmtPercent(value);
    if (key === "power_on_hours") return fmtInt(value) + " h";
    if (key === "data_units_written_bytes") return fmtBytes(value);
    if (key === "temperature_c") return fmtTemp(value);
    return fmtInt(value);
  }

  function setState(root, state, text) {
    $("#driveHealthLoading", root).hidden = state !== "loading";
    $("#driveHealthError", root).hidden = state !== "error";
    $("#driveHealthEmpty", root).hidden = state !== "empty";
    $("#driveHealthContent", root).hidden = state !== "ready";
    if (state === "error") {
      $("#driveHealthError", root).textContent = text || t("drive_health.states.load_failed", "Failed to load drive health data.");
    }
  }

  function renderCards(root, snapshot) {
    var devices = Array.isArray(snapshot && snapshot.devices) ? snapshot.devices : [];
    $("#driveHealthCards", root).innerHTML = devices
      .map(function (device) {
        var unavailable = !device.available;
        return (
          '<article class="drive-card">' +
          '<div class="drive-card-head">' +
          '<div><div class="drive-card-title">' + escapeHtml(device.label || device.device || t("drive_health.common.drive", "Drive")) + "</div>" +
          '<div class="drive-card-meta">' + escapeHtml(device.device || "—") + "</div></div>" +
          '<div class="drive-status ' + (unavailable ? "drive-status-bad" : "drive-status-good") + '">' +
          escapeHtml(unavailable ? t("drive_health.states.unavailable", "Unavailable") : t("drive_health.states.available", "Available")) +
          "</div></div>" +
          '<div class="drive-card-identity">' +
          '<div><span class="muted small">' + escapeHtml(t("drive_health.card.model", "Model")) + '</span><div>' + escapeHtml(device.model || "—") + "</div></div>" +
          '<div><span class="muted small">' + escapeHtml(t("drive_health.card.serial", "Serial")) + '</span><div>' + escapeHtml(device.serial || "—") + "</div></div>" +
          "</div>" +
          (unavailable
            ? '<div class="drive-unavailable">' + escapeHtml(device.error || t("drive_health.states.drive_unavailable", "Drive unavailable")) + "</div>"
            : '<div class="drive-stats-grid">' +
                statCell(t("drive_health.card.current_wear", "Current wear"), fmtPercent(device.percentage_used)) +
                statCell(t("drive_health.card.power_on_hours", "Power-on hours"), device.power_on_hours == null ? "—" : fmtInt(device.power_on_hours) + " h") +
                statCell(t("drive_health.card.current_temp", "Current temp"), fmtTemp(device.temperature_c)) +
                statCell(t("drive_health.card.total_written", "Total written"), fmtBytes(device.data_units_written_bytes)) +
                statCell(t("drive_health.card.integrity_errors", "Integrity errors"), fmtInt(device.media_and_data_integrity_errors)) +
                statCell(t("drive_health.card.error_log_entries", "Error log entries"), fmtInt(device.error_information_log_entries)) +
                statCell(t("drive_health.card.critical_warning", "Critical warning"), fmtInt(device.critical_warning)) +
              "</div>") +
          '<div class="drive-card-foot">' + escapeHtml(t("drive_health.meta.last_updated_prefix", "Last updated:")) + " " + escapeHtml(fmtDate(snapshot.recorded_at)) + "</div>" +
          "</article>"
        );
      })
      .join("");
  }

  function statCell(label, value) {
    return (
      '<div class="drive-stat">' +
      '<div class="drive-stat-label">' + escapeHtml(label) + "</div>" +
      '<div class="drive-stat-value">' + escapeHtml(value) + "</div>" +
      "</div>"
    );
  }

  function flattenHistory(history) {
    var rows = [];
    (history || []).forEach(function (snapshot) {
      var recordedAt = snapshot && snapshot.recorded_at;
      (snapshot && Array.isArray(snapshot.devices) ? snapshot.devices : []).forEach(function (device) {
        rows.push({
          recorded_at: recordedAt,
          label: device.label || device.device || t("drive_health.common.drive", "Drive"),
          device: device.device || "",
          percentage_used: device.percentage_used,
          power_on_hours: device.power_on_hours,
          temperature_c: device.temperature_c,
          data_units_written_bytes: device.data_units_written_bytes,
          media_and_data_integrity_errors: device.media_and_data_integrity_errors,
          error_information_log_entries: device.error_information_log_entries,
          available: !!device.available,
          error: device.error || "",
        });
      });
    });
    rows.sort(function (a, b) {
      return new Date(b.recorded_at).getTime() - new Date(a.recorded_at).getTime();
    });
    return rows;
  }

  function chartSvg(points, key, color) {
    var values = points
      .filter(function (point) {
        return point.available && point[key] != null && point.ts != null;
      })
      .map(function (point) {
        return {
          ts: Number(point.ts),
          value: Number(point[key]),
          recorded_at: point.recorded_at,
        };
      });

    if (values.length < 2) {
      return '<div class="chart-empty">' + escapeHtml(t("drive_health.insights.not_enough_history", "Not enough history yet")) + "</div>";
    }

    var width = 420;
    var height = 180;
    var padLeft = 42;
    var padRight = 12;
    var padTop = 12;
    var padBottom = 26;
    var minX = values[0].ts;
    var maxX = values[values.length - 1].ts;
    var minY = Math.min.apply(null, values.map(function (item) { return item.value; }));
    var maxY = Math.max.apply(null, values.map(function (item) { return item.value; }));
    if (minX === maxX) maxX = minX + 1;
    if (minY === maxY) {
      minY = minY - 1;
      maxY = maxY + 1;
    }
    var spanY = maxY - minY;
    minY = minY - spanY * 0.08;
    maxY = maxY + spanY * 0.08;

    function x(ts) {
      return padLeft + ((ts - minX) / (maxX - minX)) * (width - padLeft - padRight);
    }
    function y(val) {
      return height - padBottom - ((val - minY) / (maxY - minY)) * (height - padTop - padBottom);
    }

    var path = values
      .map(function (item, index) {
        return (index ? "L" : "M") + x(item.ts).toFixed(2) + " " + y(item.value).toFixed(2);
      })
      .join(" ");

    var area = path + " L" + x(values[values.length - 1].ts).toFixed(2) + " " + (height - padBottom) + " L" + x(values[0].ts).toFixed(2) + " " + (height - padBottom) + " Z";

    var pointsMarkup = values.map(function (item) {
      var cx = x(item.ts).toFixed(2);
      var cy = y(item.value).toFixed(2);
      var text = metricFormatter(key, item.value) + " " + t("drive_health.common.at", "at") + " " + fmtDate(item.recorded_at);
      return '<circle cx="' + cx + '" cy="' + cy + '" r="3.5" fill="' + color + '"><title>' + escapeHtml(text) + "</title></circle>";
    }).join("");

    var yTop = metricFormatter(key, maxY);
    var yBottom = metricFormatter(key, minY);
    var xStart = fmtDate(values[0].recorded_at);
    var xEnd = fmtDate(values[values.length - 1].recorded_at);

    return (
      '<svg class="drive-chart-svg" viewBox="0 0 ' + width + " " + height + '" preserveAspectRatio="none" role="img" aria-label="' + escapeHtml(t("drive_health.common.chart_aria", "{metric} chart", { metric: metricLabel(key) })) + '">' +
      '<line x1="' + padLeft + '" y1="' + padTop + '" x2="' + padLeft + '" y2="' + (height - padBottom) + '" class="chart-axis"></line>' +
      '<line x1="' + padLeft + '" y1="' + (height - padBottom) + '" x2="' + (width - padRight) + '" y2="' + (height - padBottom) + '" class="chart-axis"></line>' +
      '<path d="' + area + '" class="chart-area" style="--chart-color:' + color + ';"></path>' +
      '<path d="' + path + '" class="chart-line" style="--chart-color:' + color + ';"></path>' +
      pointsMarkup +
      '<text x="6" y="' + (padTop + 10) + '" class="chart-label">' + escapeHtml(yTop) + "</text>" +
      '<text x="6" y="' + (height - padBottom) + '" class="chart-label">' + escapeHtml(yBottom) + "</text>" +
      '<text x="' + padLeft + '" y="' + (height - 6) + '" class="chart-label">' + escapeHtml(xStart) + "</text>" +
      '<text x="' + (width - padRight) + '" y="' + (height - 6) + '" class="chart-label chart-label-end">' + escapeHtml(xEnd) + "</text>" +
      "</svg>"
    );
  }

  function renderDevicePanels(root, snapshot, payload) {
    var series = Array.isArray(payload && payload.series) ? payload.series : [];
    var insights = Array.isArray(payload && payload.insights) ? payload.insights : [];
    var insightMap = {};
    var seriesMap = {};
    insights.forEach(function (item) {
      insightMap[item.device] = item;
    });
    series.forEach(function (item) {
      seriesMap[item.device] = item;
    });

    var devices = [];
    var snapshotDevices = snapshot && Array.isArray(snapshot.devices) ? snapshot.devices : [];
    snapshotDevices.forEach(function (device) {
      if (!device || !device.device) return;
      devices.push({
        device: device.device,
        label: device.label || device.device,
        serial: device.serial || null,
        points: (seriesMap[device.device] && seriesMap[device.device].points) || [],
      });
    });
    series.forEach(function (item) {
      if (!item || !item.device || seriesMap[item.device] == null) return;
      var exists = devices.some(function (device) { return device.device === item.device; });
      if (!exists) devices.push(item);
    });

    $("#driveHealthDevicePanels", root).innerHTML = series
      .length || devices.length
      ? devices.map(function (deviceSeries, index) {
        var colors = ["#6ec7ff", "#ffb864", "#7ee7a8", "#ff8b8b"];
        var chartKeys = ["percentage_used", "power_on_hours", "data_units_written_bytes", "temperature_c"];
        var insight = insightMap[deviceSeries.device] || {};
        var chartCards = chartKeys.map(function (key, chartIndex) {
          return (
            '<section class="drive-chart-card">' +
            '<div class="drive-chart-title">' + escapeHtml(metricLabel(key)) + "</div>" +
            chartSvg(deviceSeries.points || [], key, colors[chartIndex % colors.length]) +
            "</section>"
          );
        }).join("");

        return (
          '<article class="drive-panel">' +
          '<div class="drive-panel-head">' +
          '<div><div class="drive-panel-title">' + escapeHtml(deviceSeries.label || deviceSeries.device || (t("drive_health.common.drive", "Drive") + " " + (index + 1))) + '</div>' +
          '<div class="drive-panel-meta">' + escapeHtml(deviceSeries.device || "—") + (deviceSeries.serial ? " • " + escapeHtml(deviceSeries.serial) : "") + "</div></div>" +
          '<div class="drive-panel-count">' + escapeHtml(t("drive_health.meta.points_count", "{count} points", { count: fmtInt((deviceSeries.points || []).length) })) + "</div>" +
          "</div>" +
          '<div class="drive-insights-grid">' +
          insightCard(t("drive_health.insights.average_write_rate", "Average write rate / day"), insight.range && insight.range.average_write_rate_status === "ok" ? fmtBytes(insight.range.average_write_rate_bytes_per_day) : t("drive_health.insights.not_enough_history", "Not enough history yet"), t("drive_health.insights.selected_range", "Selected range")) +
          insightCard(t("drive_health.insights.wear_increase_30d", "Wear increase / 30d"), insight.last_30d && insight.last_30d.status === "ok" ? fmtPercentDelta(insight.last_30d.wear_delta) : t("drive_health.insights.not_enough_history", "Not enough history yet"), t("drive_health.insights.trailing_30d", "Trailing 30 days")) +
          insightCard(t("drive_health.insights.projection_80", "Projection to 80%"), projectionText(insight.projection_80), t("drive_health.insights.linear_estimate", "Linear estimate")) +
          insightCard(t("drive_health.insights.projection_90", "Projection to 90%"), projectionText(insight.projection_90), t("drive_health.insights.linear_estimate", "Linear estimate")) +
          "</div>" +
          '<div class="drive-charts-grid">' + chartCards + "</div>" +
          "</article>"
        );
      }).join("")
      : '<div class="drive-health-state">' + escapeHtml(t("drive_health.states.no_series", "No drive series are available yet for this range.")) + "</div>";
  }

  function insightCard(label, value, meta) {
    return (
      '<div class="drive-insight">' +
      '<div class="drive-insight-label">' + escapeHtml(label) + "</div>" +
      '<div class="drive-insight-value">' + escapeHtml(value) + "</div>" +
      '<div class="drive-insight-meta">' + escapeHtml(meta || "") + "</div>" +
      "</div>"
    );
  }

  function renderHistory(root, payload) {
    var history = Array.isArray(payload && payload.history) ? payload.history : [];
    var rows = flattenHistory(history);
    var series = Array.isArray(payload && payload.series) ? payload.series : [];
    var body = $("#driveHealthHistoryBody", root);
    var empty = $("#driveHealthHistoryEmpty", root);
    var wrap = $("#driveHealthHistoryWrap", root);
    var summary = payload && payload.summary ? payload.summary : {};

    $("#driveHealthHistorySummary", root).textContent = t("drive_health.meta.history_summary", "History: {count} snapshots in {range}", {
      count: fmtInt(summary.snapshot_count || 0),
      range: String((payload.filters && payload.filters.range) || "24h"),
    });
    $("#driveHealthSeriesSummary", root).textContent = t("drive_health.meta.series_summary", "Series: {drives} drives, {points} points", {
      drives: fmtInt(series.length),
      points: fmtInt(series.reduce(function (sum, item) {
        return sum + (Array.isArray(item.points) ? item.points.length : 0);
      }, 0)),
    });

    if (!rows.length) {
      empty.hidden = false;
      wrap.hidden = true;
      body.innerHTML = "";
      return;
    }

    empty.hidden = true;
    wrap.hidden = false;
    body.innerHTML = rows
      .map(function (row) {
        var statusText = row.available ? t("drive_health.states.ok", "OK") : (row.error || t("drive_health.states.unavailable", "Unavailable"));
        return (
          "<tr>" +
          '<td data-value="' + escapeHtml(String(new Date(row.recorded_at).getTime() || 0)) + '">' + escapeHtml(fmtDate(row.recorded_at)) + "</td>" +
          '<td data-value="' + escapeHtml(row.label + " " + row.device) + '"><strong>' + escapeHtml(row.label) + "</strong><div class=\"muted small\">" + escapeHtml(row.device) + "</div></td>" +
          '<td data-value="' + escapeHtml(String(row.percentage_used == null ? "" : row.percentage_used)) + '">' + escapeHtml(fmtPercent(row.percentage_used)) + "</td>" +
          '<td data-value="' + escapeHtml(String(row.power_on_hours == null ? "" : row.power_on_hours)) + '">' + escapeHtml(row.power_on_hours == null ? "—" : fmtInt(row.power_on_hours) + " " + t("drive_health.units.hours_short", "h")) + "</td>" +
          '<td data-value="' + escapeHtml(String(row.temperature_c == null ? "" : row.temperature_c)) + '">' + escapeHtml(fmtTemp(row.temperature_c)) + "</td>" +
          '<td data-value="' + escapeHtml(String(row.data_units_written_bytes == null ? "" : row.data_units_written_bytes)) + '">' + escapeHtml(fmtBytes(row.data_units_written_bytes)) + "</td>" +
          '<td data-value="' + escapeHtml(String(row.media_and_data_integrity_errors == null ? "" : row.media_and_data_integrity_errors)) + '">' + escapeHtml(fmtInt(row.media_and_data_integrity_errors)) + "</td>" +
          '<td data-value="' + escapeHtml(String(row.error_information_log_entries == null ? "" : row.error_information_log_entries)) + '">' + escapeHtml(fmtInt(row.error_information_log_entries)) + "</td>" +
          '<td data-value="' + escapeHtml(statusText) + '">' + escapeHtml(statusText) + "</td>" +
          "</tr>"
        );
      })
      .join("");
  }

  function render(root, statusPayload, historyPayload) {
    var snapshot = statusPayload && statusPayload.snapshot ? statusPayload.snapshot : null;
    var hasSnapshot = snapshot && Array.isArray(snapshot.devices) && snapshot.devices.length > 0;
    var hasHistory = historyPayload && Array.isArray(historyPayload.history) && historyPayload.history.length > 0;

    if (!hasSnapshot && !hasHistory) {
      setState(root, "empty");
      return;
    }

    setState(root, "ready");
    $("#driveHealthLastUpdated", root).textContent = t("drive_health.meta.last_updated", "Last updated: {value}", {
      value: fmtDate(statusPayload && statusPayload.summary ? statusPayload.summary.recorded_at : null),
    });

    renderCards(root, snapshot || { recorded_at: null, devices: [] });
    renderDevicePanels(root, snapshot || { devices: [] }, historyPayload || { series: [], insights: [] });
    renderHistory(root, historyPayload || { history: [], series: [], summary: {}, filters: {} });
  }

  function exportData(root, format) {
    var range = $("#driveHealthRange", root).value || "24h";
    window.location.href = withParams(root.dataset.exportUrl, { range: range, format: format });
  }

  async function load(root) {
    setState(root, "loading");
    var range = $("#driveHealthRange", root).value || "24h";
    try {
      var urls = [
        root.dataset.statusUrl,
        withParams(root.dataset.historyUrl, { range: range }),
      ];
      var responses = await Promise.all(urls.map(function (url) { return fetch(url, { credentials: "same-origin" }); }));
      var payloads = await Promise.all(responses.map(function (res) { return res.json(); }));

      if (!responses[0].ok || !payloads[0].ok) {
        throw new Error((payloads[0] && payloads[0].error) || t("drive_health.states.load_status_failed", "Failed to load current drive status."));
      }
      if (!responses[1].ok || !payloads[1].ok) {
        throw new Error((payloads[1] && payloads[1].error) || t("drive_health.states.load_history_failed", "Failed to load drive history."));
      }

      render(root, payloads[0], payloads[1]);
    } catch (err) {
      setState(root, "error", err && err.message ? err.message : t("drive_health.states.load_failed", "Failed to load drive health data."));
    }
  }

  function boot() {
    var root = $("#driveHealthRoot");
    if (!root) return;

    $("#driveHealthRefresh", root).addEventListener("click", function () {
      load(root);
    });
    $("#driveHealthRange", root).addEventListener("change", function () {
      load(root);
    });
    $("#driveHealthExportJson", root).addEventListener("click", function () {
      exportData(root, "json");
    });
    $("#driveHealthExportCsv", root).addEventListener("click", function () {
      exportData(root, "csv");
    });

    load(root);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot, { once: true });
  } else {
    boot();
  }
})();
