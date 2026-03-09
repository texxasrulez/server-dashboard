(function () {
  "use strict";
  function $(s, ctx) {
    return (ctx || document).querySelector(s);
  }
  function $all(s, ctx) {
    return Array.prototype.slice.call((ctx || document).querySelectorAll(s));
  }
  function on(el, ev, fn, opts) {
    if (el) el.addEventListener(ev, fn, opts || false);
  }
  function throttle(fn, ms) {
    let t = 0;
    return function () {
      const n = Date.now();
      if (n - t > ms) {
        t = n;
        fn.apply(this, arguments);
      }
    };
  }

  function parseLooseJSON(txt) {
    try {
      return JSON.parse(txt);
    } catch (e) {}
    var s = (txt || "").replace(/^\uFEFF/, "").trim();
    var i = s.indexOf("{");
    var j = s.lastIndexOf("}");
    if (i >= 0 && j > i) {
      try {
        return JSON.parse(s.slice(i, j + 1));
      } catch (e) {}
    }
    return null;
  }
  function getQueryParam(name) {
    try {
      var params = new URLSearchParams(window.location.search || "");
      var val = params.get(name);
      return val == null || val === "" ? null : val;
    } catch (e) {
      var match = (window.location.search || "").match(
        new RegExp("[?&]" + name + "=([^&]+)"),
      );
      if (match && match[1]) {
        try {
          return decodeURIComponent(match[1].replace(/\+/g, " "));
        } catch (_) {
          return match[1];
        }
      }
    }
    return null;
  }
  var AUTO_INTERVAL_SEC = 60;
  var FETCH_LIMIT_CAP = 200000;
  var DOWNSAMPLE_MAX = 1100;
  var RANGE_MIN_INTERVAL = {
    "1h": 15, // keep fine detail
    "24h": 90, // at least 1.5 minutes per sample
    "7d": 300, // 5 minutes per sample
    "30d": 900, // 15 minutes per sample
  };

  function sinceFromRange(val) {
    var now = Math.floor(Date.now() / 1000);
    var map = { "1h": 3600, "24h": 86400, "7d": 604800, "30d": 2592000 };
    return now - (map[val] || 86400);
  }
  function rangeDuration(val) {
    var map = { "1h": 3600, "24h": 86400, "7d": 604800, "30d": 2592000 };
    return map[val] || 86400;
  }
  function limitForRange(val) {
    var rangeSec = rangeDuration(val);
    var interval = Math.max(5, parseInt(AUTO_INTERVAL_SEC, 10) || 60);
    var minInterval = RANGE_MIN_INTERVAL[val] || interval;
    var effectiveInterval = Math.max(interval, minInterval);
    var needed = Math.ceil(rangeSec / interval) + 10;
    var effectiveNeeded = Math.ceil(rangeSec / effectiveInterval) + 10;
    var padded = Math.ceil(Math.min(needed, effectiveNeeded) * 1.25);
    var minPoints = 1000;
    return Math.max(minPoints, Math.min(FETCH_LIMIT_CAP, padded));
  }
  function escapeHtml(s) {
    return (s == null ? "" : String(s)).replace(/[&<>"']/g, function (c) {
      return {
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#039;",
      }[c];
    });
  }
  function fmtDT(ts) {
    try {
      return new Date((ts | 0) * 1000).toLocaleString();
    } catch (e) {
      return String(ts);
    }
  }
  function fmtAgo(ts) {
    if (!ts) return "—";
    var s = Math.max(0, Math.floor(Date.now() / 1000) - (ts | 0));
    if (s < 45) return "just now";
    var m = Math.floor(s / 60);
    if (m < 60) return m + "m ago";
    var h = Math.floor(m / 60);
    if (h < 24) return h + "h ago";
    var d = Math.floor(h / 24);
    return d + "d ago";
  }

  function sizeCanvas(cv) {
    var dpr = window.devicePixelRatio || 1;
    var cssW = Math.max(220, Math.floor(cv.clientWidth || 320));
    var cssH = 56;
    cv.width = Math.floor(cssW * dpr);
    cv.height = Math.floor(cssH * dpr);
    cv.style.width = cssW + "px";
    cv.style.height = cssH + "px";
    return { W: cv.width, H: cv.height, dpr: dpr, cssW: cssW, cssH: cssH };
  }
  function drawSpark(
    cv,
    series,
    okBad,
    showBaseline,
    crossX,
    selX1,
    selX2,
    colors,
  ) {
    var d = sizeCanvas(cv);
    var ctx = cv.getContext("2d");
    var W = d.W,
      H = d.H;
    ctx.clearRect(0, 0, W, H);
    if (!series || !series.length) return;
    var min = Math.min.apply(null, series),
      max = Math.max.apply(null, series);
    if (max === min) max = min + 1;
    var x = (i) =>
      Math.round((i * (W - 4)) / Math.max(1, series.length - 1)) + 2;
    var y = (v) => H - Math.round(((v - min) / (max - min)) * (H - 4)) - 2;
    ctx.lineWidth = 1 * d.dpr;
    ctx.lineJoin = "round";
    ctx.lineCap = "round";
    if (showBaseline) {
      ctx.strokeStyle =
        getComputedStyle(cv).getPropertyValue("--muted-color") ||
        "rgba(127,127,127,.25)";
      ctx.beginPath();
      ctx.moveTo(0, H - 1);
      ctx.lineTo(W, H - 1);
      ctx.stroke();
    }
    var palette = colors || { ok: "#22a559", warn: "#f0b429", fail: "#d95140" };
    ctx.lineWidth = 1.5 * d.dpr;
    ctx.lineJoin = "round";
    ctx.lineCap = "round";
    var startIndex = 0;
    while (startIndex < series.length) {
      var status = okBad && okBad[startIndex] === false ? "fail" : "ok";
      var endIndex = startIndex;
      while (endIndex + 1 < series.length) {
        var nextStatus = okBad && okBad[endIndex + 1] === false ? "fail" : "ok";
        if (nextStatus !== status) break;
        endIndex++;
      }
      ctx.strokeStyle = status === "fail" ? palette.fail : palette.ok;
      ctx.beginPath();
      ctx.moveTo(x(startIndex), y(series[startIndex]));
      for (var j = startIndex + 1; j <= endIndex; j++)
        ctx.lineTo(x(j), y(series[j]));
      ctx.stroke();
      startIndex = endIndex + 1;
    }

    if (typeof selX1 === "number" && typeof selX2 === "number") {
      var a = Math.min(selX1, selX2),
        b = Math.max(selX1, selX2);
      ctx.save();
      ctx.fillStyle = "rgba(100,150,255,.15)";
      ctx.strokeStyle = "rgba(120,170,255,.55)";
      ctx.fillRect(a, 0, b - a, H);
      ctx.strokeRect(a + 0.5, 0.5, b - a - 1, H - 1);
      ctx.restore();
    }
    if (typeof crossX === "number") {
      ctx.save();
      ctx.strokeStyle = "rgba(255,255,255,.25)";
      ctx.beginPath();
      ctx.moveTo(crossX, 0);
      ctx.lineTo(crossX, H);
      ctx.stroke();
      ctx.restore();
    }
  }
  function downsampleSeries(series, maxPoints) {
    var times = series.times || [];
    var len = times.length;
    if (len <= maxPoints || maxPoints < 2) return series;
    var step = (len - 1) / (maxPoints - 1);
    var dsTimes = [],
      dsLat = [],
      dsOks = [],
      dsCodes = [];
    for (var i = 0; i < maxPoints; i++) {
      var idx = Math.min(len - 1, Math.round(i * step));
      dsTimes.push(series.times[idx]);
      dsLat.push(series.lat[idx]);
      dsOks.push(series.oks[idx]);
      dsCodes.push(series.codes[idx]);
    }
    return { times: dsTimes, lat: dsLat, oks: dsOks, codes: dsCodes };
  }

  var _CACHE = { probes: [], alerts: [] };
  var ZOOM = null;
  var CURRENT_RANGE = "24h";
  var REQUESTED_SERVICE = (function () {
    var raw = getQueryParam("service");
    if (!raw) return null;
    var trimmed = (raw || "").trim();
    return trimmed ? trimmed : null;
  })();
  var CURRENT_SERVICE = REQUESTED_SERVICE || "__all";
  var SERVICE_MAP = {};
  var SERVICE_META = {};
  var SERVICE_SELECT = null;
  var LAST_PROBE_TS = null;
  var LAST_COVERAGE_SEC = 0;
  var AUTOPROBE_UI = { toggle: null, input: null, btn: null, status: null };
  function severityBadge(sev) {
    var v = (sev || "warn").toLowerCase();
    var cls = "sev-badge sev-" + v.replace(/[^a-z]/g, "");
    return '<span class="' + cls + '">' + escapeHtml(v) + "</span>";
  }
  function buildExtras(it) {
    var parts = [];
    if (typeof it.value !== "undefined" && it.value !== null) {
      parts.push(
        "value: <strong>" + escapeHtml(String(it.value)) + "</strong>",
      );
    }
    if (it.http_code) {
      parts.push("HTTP " + escapeHtml(String(it.http_code)));
    }
    if (it.latency_ms) {
      parts.push("latency " + escapeHtml(String(it.latency_ms)) + "ms");
    }
    if (it.seen) {
      parts.push("seen " + escapeHtml(String(it.seen)) + "x");
    }
    return parts.length ? parts.join(" · ") : "&mdash;";
  }
  function serviceHistoryLink(id) {
    return "history.php?service=" + encodeURIComponent(id || "");
  }
  function getServiceAlertMeta(id) {
    var meta = SERVICE_META[id];
    return meta && meta.alert_meta ? meta.alert_meta : null;
  }

  function datasetBounds() {
    var minT = Infinity,
      maxT = -Infinity;
    for (var i = 0; i < _CACHE.probes.length; i++) {
      var t = _CACHE.probes[i].ts | 0;
      if (t) {
        if (t < minT) minT = t;
        if (t > maxT) maxT = t;
      }
    }
    if (!isFinite(minT) || !isFinite(maxT)) {
      var now = Math.floor(Date.now() / 1000);
      return { min: now - 3600, max: now };
    }
    return { min: minT, max: maxT };
  }
  function computeCoverage(items) {
    var minT = Infinity,
      maxT = -Infinity;
    items.forEach(function (it) {
      var t = it && it.ts ? it.ts | 0 : 0;
      if (!t) return;
      if (t < minT) minT = t;
      if (t > maxT) maxT = t;
    });
    if (!isFinite(minT) || !isFinite(maxT) || maxT <= minT) return 0;
    return maxT - minT;
  }
  function formatDurationShort(sec) {
    var s = Math.max(0, Math.floor(sec));
    if (s >= 86400) return Math.round((s / 86400) * 10) / 10 + "d";
    if (s >= 3600) return Math.round((s / 3600) * 10) / 10 + "h";
    if (s >= 60) return Math.round(s / 60) + "m";
    return s + "s";
  }

  function ensureMetaChips() {
    var root = $("#history-root");
    if (!root) return;
    var bar = root.querySelector(".toolbar");
    if (!bar) return;
    var id = "metaChipsWrap";
    var ex = document.getElementById(id);
    if (ex) return ex;
    var w = document.createElement("div", "card");
    w.id = id;
    w.className = "row gap-sm wrap";
    w.style.marginLeft = "auto";
    w.innerHTML =
      '<span id="zoomBadge" class="chip small" hidden></span>' +
      '<span id="rangeInfoChip" class="chip small" hidden></span>' +
      '<span id="lastProbeChip" class="chip small" hidden title=""></span>' +
      '<button id="zoomClearBtn" class="btn micro secondary" hidden>Clear zoom</button>';
    bar.appendChild(w);
    on($("#zoomClearBtn"), "click", function () {
      ZOOM = null;
      updateMetaChips();
      rerenderFromCache();
      window.toast.info("Zoom cleared");
    });
    return w;
  }
  function updateMetaChips() {
    ensureMetaChips();
    var zb = $("#zoomBadge"),
      cb = $("#zoomClearBtn"),
      lp = $("#lastProbeChip"),
      ri = $("#rangeInfoChip");
    if (ZOOM && zb && cb) {
      var fmt = (t) => new Date(t * 1000).toLocaleString();
      zb.textContent = "Zoom: " + fmt(ZOOM.from) + " → " + fmt(ZOOM.to);
      zb.hidden = false;
      cb.hidden = false;
    } else {
      if (zb) zb.hidden = true;
      if (cb) cb.hidden = true;
    }
    if (ri) {
      var target = rangeDuration(CURRENT_RANGE);
      var coverage = LAST_COVERAGE_SEC || 0;
      var interval = Math.max(5, AUTO_INTERVAL_SEC || 60);
      ri.textContent =
        "Interval ≈ " +
        interval +
        "s · Coverage " +
        formatDurationShort(Math.min(target, coverage)) +
        "/" +
        formatDurationShort(target);
      ri.hidden = false;
    }
    if (lp) {
      if (LAST_PROBE_TS) {
        lp.textContent = "Last probe: " + fmtAgo(LAST_PROBE_TS);
        lp.title = fmtDT(LAST_PROBE_TS);
        lp.hidden = false;
      } else {
        lp.hidden = true;
      }
    }
  }

  function buildCardActions(id) {
    if (!id) return null;
    var wrap = document.createElement("div");
    wrap.className = "svc-actions";
    var histLink = document.createElement("a");
    histLink.className = "chip small neutral";
    histLink.href = serviceHistoryLink(id);
    histLink.target = "_blank";
    histLink.rel = "noopener";
    histLink.textContent = "View history";
    wrap.appendChild(histLink);
    var alertMeta = getServiceAlertMeta(id) || {};
    var rules = Array.isArray(alertMeta.rule_ids)
      ? alertMeta.rule_ids.filter(Boolean)
      : [];
    if (rules.length) {
      var muteBtn = document.createElement("button");
      muteBtn.type = "button";
      muteBtn.className = "chip small warn";
      muteBtn.dataset.action = "mute-service";
      muteBtn.dataset.rules = rules.join(",");
      muteBtn.dataset.service = id;
      muteBtn.textContent = "Silence alerts";
      wrap.appendChild(muteBtn);
    }
    if (alertMeta.silenced_until) {
      var mutedChip = document.createElement("span");
      mutedChip.className = "chip small muted";
      mutedChip.textContent = "Muted " + fmtAgo(alertMeta.silenced_until | 0);
      wrap.appendChild(mutedChip);
    }
    return wrap.childNodes.length ? wrap : null;
  }

  function renderCards(items, since) {
    var wrap = $("#cardsGrid");
    if (!wrap) return;
    if (!wrap.dataset.actionsBound) {
      wrap.dataset.actionsBound = "1";
      on(wrap, "click", handleCardAction);
    }
    wrap.innerHTML = "";
    var byId = {};
    (items || []).forEach(function (it) {
      var id = it.id || "unknown";
      (byId[id] || (byId[id] = [])).push(it);
    });
    var ids = Object.keys(byId);
    if (CURRENT_SERVICE !== "__all") {
      ids = ids.filter(function (id) {
        return id === CURRENT_SERVICE;
      });
    }
    var showBaseline = !!($("#baselineToggle") && $("#baselineToggle").checked);

    ids.sort().forEach(function (id) {
      var arrAll = (byId[id] || []).sort(function (a, b) {
        return a.ts - b.ts;
      });
      var arr = ZOOM
        ? arrAll.filter(function (it) {
            var t = it.ts | 0;
            return t >= ZOOM.from && t <= ZOOM.to;
          })
        : arrAll.filter(function (it) {
            return (it.ts | 0) >= since;
          });
      var usingFallback = false;
      if (!arr.length && arrAll.length) {
        arr = arrAll.slice(-40);
        usingFallback = true;
      }
      var last = arr[arr.length - 1] || arrAll[arrAll.length - 1] || {};
      var name = last.name || id;

      var times = arr.map(function (x) {
        return x.ts | 0;
      });
      var lat = arr.map(function (x) {
        return Number(x.latency_ms || 0);
      });
      var oksRaw = arr.map(function (x) {
        return x.status === "up" || x.ok === 1 || x.ok === true ? 1 : 0;
      });
      var codes = arr.map(function (x) {
        return x.http_code || "";
      });
      var ds = downsampleSeries(
        { times: times, lat: lat, oks: oksRaw.map(Boolean), codes: codes },
        DOWNSAMPLE_MAX,
      );
      var timesDS = ds.times;
      var latDS = ds.lat;
      var oksDS = ds.oks;
      var codesDS = ds.codes;

      var subtitle = last.status || (last.ok ? "up" : "down");
      if (last.http_code) subtitle += " · HTTP " + last.http_code;
      subtitle += " · " + (last.latency_ms ?? 0) + " ms";
      var uptimePct = arr.length
        ? Math.round(
            (arr.reduce(function (acc, it) {
              return (
                acc +
                (it.status === "up" || it.ok === 1 || it.ok === true ? 1 : 0)
              );
            }, 0) /
              arr.length) *
              100,
          )
        : null;

      var card = document.createElement("div");
      card.className = "card";
      if (usingFallback) card.style.opacity = 0.75;
      var stateClass = null;
      var lastOk = !!(
        last &&
        (last.status === "up" || last.ok === true || last.ok === 1)
      );
      if (!lastOk) stateClass = "fail";
      else if (uptimePct !== null) {
        if (uptimePct < 70) stateClass = "fail";
        else if (uptimePct < 90) stateClass = "warn";
        else stateClass = "ok";
      } else if (lastOk) {
        stateClass = "ok";
      }
      if (stateClass) card.classList.add("state-" + stateClass);

      var head = document.createElement("div");
      head.className = "row between align-center";
      var left = document.createElement("div");
      left.innerHTML =
        "<strong>" +
        escapeHtml(name) +
        '</strong><span class="subtitle" style="margin-left:8px;">' +
        escapeHtml(subtitle) +
        "</span>";
      var right = document.createElement("div");
      var csvBtn = document.createElement("button");
      csvBtn.className = "btn secondary micro";
      csvBtn.textContent = "CSV";
      right.appendChild(csvBtn);
      head.appendChild(left);
      head.appendChild(right);

      var body = document.createElement("div");
      var cv = document.createElement("canvas");
      cv.className = "spark";
      cv.title =
        "wheel: zoom · shift+wheel: pan · drag: select · dblclick: reset";
      body.appendChild(cv);
      card.appendChild(head);
      card.appendChild(body);
      var actionBar = buildCardActions(id);
      if (actionBar) card.appendChild(actionBar);
      if (uptimePct !== null) {
        var uptimeEl = document.createElement("div");
        uptimeEl.className = "muted small";
        uptimeEl.textContent = "Uptime (selected range): " + uptimePct + "%";
        body.appendChild(uptimeEl);
      }
      wrap.appendChild(card);

      cv._series = {
        times: timesDS,
        lat: latDS,
        oks: oksDS,
        codes: codesDS,
        name: name,
      };

      var palette = { ok: "#22a559", warn: "#f0b429", fail: "#d95140" };
      drawSpark(
        cv,
        latDS,
        oksDS,
        showBaseline,
        undefined,
        undefined,
        undefined,
        palette,
      );

      function idxFromEvent(ev) {
        var rect = cv.getBoundingClientRect();
        var x = Math.min(Math.max(ev.clientX - rect.left, 0), rect.width);
        var n = latDS.length || 1;
        var frac = n > 1 ? x / rect.width : 0;
        return {
          i: Math.round(frac * (n - 1)),
          px: x * (window.devicePixelRatio || 1),
        };
      }

      on(
        cv,
        "mousemove",
        throttle(function (ev) {
          if (!latDS.length) return;
          var p = idxFromEvent(ev);
          var i = p.i;
          var when = new Date(
            (cv._series.times[i] || 0) * 1000,
          ).toLocaleString();
          var v = latDS[i] | 0,
            ok = !!cv._series.oks[i],
            code = cv._series.codes[i] || "";
          var html =
            '<div style="font-weight:600;margin-bottom:2px;">' +
            escapeHtml(name) +
            "</div>" +
            "<div>" +
            escapeHtml(when) +
            "</div>" +
            "<div>latency: <b>" +
            v +
            " ms</b>" +
            (code ? " · HTTP " + escapeHtml(String(code)) : "") +
            "</div>" +
            "<div>status: " +
            (ok
              ? '<span style="color:#22a559">up</span>'
              : '<span style="color:#d95140">down</span>') +
            "</div>";
          drawSpark(
            cv,
            latDS,
            oksDS,
            showBaseline,
            p.px,
            selPx1,
            selPx2,
            palette,
          );
          showTip(html, ev.clientX, ev.clientY);
        }, 16),
      );
      on(cv, "mouseleave", function () {
        hideTip();
        drawSpark(
          cv,
          latDS,
          oksDS,
          showBaseline,
          undefined,
          undefined,
          undefined,
          palette,
        );
      });

      var dragging = false,
        selPx1 = null,
        selPx2 = null,
        startPx = null,
        startIdx = null;
      on(cv, "mousedown", function (ev) {
        if (!latDS.length) return;
        dragging = true;
        var p = idxFromEvent(ev);
        startPx = p.px;
        startIdx = p.i;
        selPx1 = p.px;
        selPx2 = p.px;
        drawSpark(
          cv,
          latDS,
          oksDS,
          showBaseline,
          null,
          selPx1,
          selPx2,
          palette,
        );
      });
      on(window, "mousemove", function (ev) {
        if (!dragging) return;
        var rect = cv.getBoundingClientRect();
        if (ev.clientY < rect.top - 40 || ev.clientY > rect.bottom + 40) return;
        var p = idxFromEvent(ev);
        selPx2 = p.px;
        drawSpark(
          cv,
          latDS,
          oksDS,
          showBaseline,
          null,
          selPx1,
          selPx2,
          palette,
        );
      });
      on(window, "mouseup", function (ev) {
        if (!dragging) return;
        dragging = false;
        var end = idxFromEvent(ev);
        selPx2 = end.px;
        drawSpark(
          cv,
          latDS,
          oksDS,
          showBaseline,
          undefined,
          undefined,
          undefined,
          palette,
        );
        var i1 = Math.max(0, Math.min(startIdx, end.i));
        var i2 = Math.max(0, Math.max(startIdx, end.i));
        if (i2 - i1 < 2) return;
        var from = cv._series.times[i1],
          to = cv._series.times[i2];
        if (from === to) return;
        ZOOM = { from: from, to: to };
        updateMetaChips();
        rerenderFromCache();
        window.toast.info("Zoom applied");
      });

      on(
        cv,
        "wheel",
        function (ev) {
          if (!latDS.length) return;
          ev.preventDefault();
          var now = Math.floor(Date.now() / 1000);
          var dur = rangeDuration(CURRENT_RANGE);
          var windowMin = now - dur,
            windowMax = now;
          var bounds = datasetBounds();
          var curFrom = ZOOM ? ZOOM.from : Math.max(bounds.min, windowMin);
          var curTo = ZOOM ? ZOOM.to : Math.min(bounds.max, windowMax);
          var rect = cv.getBoundingClientRect();
          var frac = Math.min(
            Math.max((ev.clientX - rect.left) / rect.width, 0),
            1,
          );
          var pivot = Math.round(curFrom + frac * (curTo - curFrom));

          if (ev.shiftKey) {
            var pan =
              Math.round((curTo - curFrom) * 0.1) * (ev.deltaY > 0 ? 1 : -1);
            var nf = Math.max(bounds.min, curFrom + pan);
            var nt = Math.min(bounds.max, curTo + pan);
            var width = curTo - curFrom;
            if (nt - nf < width) {
              nf = nt - width;
            }
            ZOOM = { from: nf, to: nt };
            updateMetaChips();
            rerenderFromCache();
            return;
          }
          var factor = ev.deltaY < 0 ? 0.8 : 1.25;
          var newFrom = Math.round(pivot - (pivot - curFrom) * factor);
          var newTo = Math.round(pivot + (curTo - pivot) * factor);
          var minSpan = 60;
          if (newTo - newFrom < minSpan) {
            var mid = Math.round((newFrom + newTo) / 2);
            newFrom = mid - Math.floor(minSpan / 2);
            newTo = mid + Math.ceil(minSpan / 2);
          }
          newFrom = Math.max(bounds.min, Math.max(windowMin, newFrom));
          newTo = Math.min(bounds.max, Math.min(windowMax, newTo));
          if (newTo <= newFrom) return;
          ZOOM = { from: newFrom, to: newTo };
          updateMetaChips();
          rerenderFromCache();
        },
        { passive: false },
      );
      // Double-click to reset zoom
      on(cv, "dblclick", function () {
        ZOOM = null;
        updateMetaChips();
        rerenderFromCache();
        try {
          window.toast.info("Zoom cleared");
        } catch (_) {}
      });

      on(csvBtn, "click", function () {
        if (!lat.length) {
          window.toast.warn("No data");
          return;
        }
        var lines = ["ts,iso,latency_ms,status,http_code,service"];
        for (var i = 0; i < lat.length; i++) {
          var t = times[i],
            iso = new Date(t * 1000).toISOString();
          var s = oksRaw[i] ? "up" : "down";
          var h = codes[i] || "";
          lines.push([t, iso, lat[i], s, h, JSON.stringify(name)].join(","));
        }
        var blob = new Blob([lines.join("\n")], { type: "text/csv" });
        var a = document.createElement("a");
        a.href = URL.createObjectURL(blob);
        a.download = (name || "service") + "_history.csv";
        a.click();
        setTimeout(function () {
          URL.revokeObjectURL(a.href);
        }, 1500);
      });
    });
  }

  function handleCardAction(ev) {
    var btn = ev.target.closest('[data-action="mute-service"]');
    if (!btn) return;
    ev.preventDefault();
    var rules = (btn.dataset.rules || "")
      .split(",")
      .map(function (s) {
        return s.trim();
      })
      .filter(Boolean);
    if (!rules.length) {
      window.toast &&
        window.toast.warn &&
        window.toast.warn("No alert rules for this service.");
      return;
    }
    var svcId = btn.dataset.service || "";
    var svcName =
      SERVICE_MAP[svcId] ||
      (SERVICE_META[svcId] && SERVICE_META[svcId].name) ||
      svcId ||
      "service";
    var minsInput = prompt("Mute alerts for " + svcName + " (minutes):", "60");
    if (!minsInput) return;
    var mins = Math.max(1, parseInt(minsInput, 10) || 60);
    var prev = btn.textContent;
    btn.textContent = "Muting…";
    btn.disabled = true;
    silenceRuleIds(rules, mins)
      .then(function () {
        window.toast &&
          window.toast.success &&
          window.toast.success("Alerts muted for " + mins + "m.");
        load(CURRENT_RANGE);
      })
      .catch(function (err) {
        window.toast &&
          window.toast.error &&
          window.toast.error(
            "Mute failed: " + (err && err.message ? err.message : err),
          );
      })
      .finally(function () {
        btn.textContent = prev;
        btn.disabled = false;
      });
  }

  function fetchText(url, opts) {
    return fetch(
      url,
      Object.assign({ credentials: "include", cache: "no-store" }, opts || {}),
    ).then((r) =>
      r.text().then((t) => ({ ok: r.ok, status: r.status, text: t })),
    );
  }
  function fetchJSON(url, opts) {
    return fetchText(url, opts).then(({ ok, status, text }) => {
      var j = parseLooseJSON(text);
      if (!ok && j && j.error)
        throw new Error(j.error + " (HTTP " + status + ")");
      if (!ok) throw new Error("HTTP " + status);
      return j || {};
    });
  }
  function silenceRuleIds(ids, minutes) {
    if (!ids || !ids.length)
      return Promise.reject(new Error("No alert rules available"));
    return fetch("api/alerts_bulk.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      credentials: "include",
      body: JSON.stringify({
        action: "silence",
        ids: ids,
        silence_minutes: minutes,
      }),
    }).then(function (r) {
      if (!r.ok) throw new Error("HTTP " + r.status);
      return r.json();
    });
  }
  function loadAutoInterval() {
    return fetchJSON("api/autoprobe_get.php")
      .then(function (j) {
        if (j && j.interval) {
          var sec = parseInt(j.interval, 10);
          if (sec && sec > 0) AUTO_INTERVAL_SEC = Math.max(5, sec);
        }
        if (j) applyAutoprobeUI(j);
      })
      .catch(function () {});
  }
  function applyAutoprobeUI(cfg) {
    if (!cfg) return;
    var toggle = AUTOPROBE_UI.toggle;
    var input = AUTOPROBE_UI.input;
    var status = AUTOPROBE_UI.status;
    if (toggle) toggle.checked = !!cfg.enabled;
    if (input)
      input.value = Math.max(
        5,
        parseInt(cfg.interval || AUTO_INTERVAL_SEC || 60, 10) || 60,
      );
    if (status) {
      if (cfg.enabled) {
        status.textContent =
          "Running every " +
          Math.max(5, parseInt(cfg.interval || 60, 10)) +
          "s";
      } else {
        status.textContent = "Auto probe paused";
      }
    }
  }

  function renderAlerts(items) {
    var tb = $("#alertsTable tbody");
    if (!tb) return;
    var empty = document.getElementById("alertsEmptyRow");
    tb.innerHTML = "";
    var filt = items || [];
    if (CURRENT_SERVICE !== "__all") {
      filt = filt.filter(function (it) {
        var sid = it.service_id || it.id;
        return sid === CURRENT_SERVICE;
      });
    }
    if (ZOOM)
      filt = filt.filter(function (it) {
        var t = it.ts | 0;
        return t >= ZOOM.from && t <= ZOOM.to;
      });
    var rows = filt.slice(-50).reverse();
    if (!rows.length) {
      var trEmpty = document.createElement("tr");
      trEmpty.innerHTML =
        '<td colspan="6" class="muted">No alert events in this range.</td>';
      tb.appendChild(trEmpty);
      return;
    }
    rows.forEach(function (it) {
      var tr = document.createElement("tr");
      var ts = it.ts | 0;
      var rel = fmtAgo(ts);
      var abs = fmtDT(ts);
      var cond =
        (it.metric || "") + " " + (it.op || "") + " " + (it.threshold ?? "");
      var extras = buildExtras(it);
      var svc = it.service_name || it.service_id || "";
      tr.innerHTML =
        '<td><div class="time-cell"><span class="time-rel">' +
        escapeHtml(rel) +
        '</span><span class="time-abs">' +
        escapeHtml(abs) +
        "</span></div></td>" +
        "<td>" +
        escapeHtml(svc) +
        "</td>" +
        "<td>" +
        escapeHtml(it.alert_name || "") +
        "</td>" +
        "<td>" +
        escapeHtml(cond) +
        "</td>" +
        "<td>" +
        extras +
        "</td>" +
        "<td>" +
        severityBadge(it.severity || "warn") +
        "</td>";
      tb.appendChild(tr);
    });
  }

  function setBusy(btn, on) {
    if (!btn) return;
    btn._origText = btn._origText || btn.textContent;
    btn.disabled = !!on;
    btn.textContent = on ? btn.dataset.busyText || "Working…" : btn._origText;
  }

  function load(range) {
    var root = $("#history-root");
    if (!root) return;
    CURRENT_RANGE = range || CURRENT_RANGE;
    var since = sinceFromRange(CURRENT_RANGE);
    var probesLimit = limitForRange(CURRENT_RANGE);
    var perServiceParam = "&per_service=1&per_service_limit=" + probesLimit;
    var alertsLimit = Math.max(
      200,
      Math.round(Math.max(1000, probesLimit / 4)),
    );

    var probesUrl =
      root.dataset.exportProbes +
      "&limit=" +
      probesLimit +
      "&start=" +
      since +
      perServiceParam +
      "&_=" +
      Date.now();
    var alertsUrl =
      root.dataset.exportAlerts +
      "&limit=" +
      alertsLimit +
      "&start=" +
      since +
      "&_=" +
      Date.now();
    var cronUrl = "api/cron_health.php";
    var servicesListUrl = "api/services_list.php";
    var servicesListPromise = fetchJSON(servicesListUrl).catch(function (err) {
      console.warn("[history] service metadata load failed", err);
      return null;
    });
    var diag = $("#diagBox");
    if (diag) diag.textContent = "Loading…";
    return Promise.all([
      fetchJSON(probesUrl),
      fetchJSON(alertsUrl),
      fetchJSON(cronUrl),
      servicesListPromise,
    ])
      .then(function (res) {
        _CACHE.probes = res[0] && res[0].items ? res[0].items : [];
        _CACHE.alerts = res[1] && res[1].items ? res[1].items : [];
        SERVICE_MAP = {};
        _CACHE.probes.forEach(function (it) {
          if (!it) return;
          var sid = it.id || it.service_id;
          if (!sid) return;
          SERVICE_MAP[sid] = it.name || SERVICE_MAP[sid] || sid;
        });
        _CACHE.alerts.forEach(function (it) {
          if (!it) return;
          var sid = it.service_id || it.id;
          if (!sid) return;
          if (!SERVICE_MAP[sid]) SERVICE_MAP[sid] = it.service_name || sid;
        });
        if (REQUESTED_SERVICE && !SERVICE_MAP[REQUESTED_SERVICE]) {
          SERVICE_MAP[REQUESTED_SERVICE] = REQUESTED_SERVICE;
        }
        SERVICE_META = {};
        var svcPayload = res[3] || {};
        var svcItems =
          svcPayload && Array.isArray(svcPayload.items) ? svcPayload.items : [];
        svcItems.forEach(function (svc) {
          if (!svc || !svc.id) return;
          SERVICE_META[svc.id] = {
            name: svc.name || svc.id,
            alert_meta: svc.alert_meta || null,
          };
        });
        refreshServiceSelect();
        // infer last probe ts from dataset and cron marker
        var maxT = 0;
        for (var i = 0; i < _CACHE.probes.length; i++) {
          var t = _CACHE.probes[i].ts | 0;
          if (t > maxT) maxT = t;
        }
        if (maxT) LAST_PROBE_TS = maxT;
        try {
          var cron = res[2] || {};
          if (cron && cron.history_ts) {
            LAST_PROBE_TS = Math.max(LAST_PROBE_TS || 0, cron.history_ts | 0);
          }
          if (cron && cron.last_ts) {
            LAST_PROBE_TS = Math.max(LAST_PROBE_TS || 0, cron.last_ts | 0);
          }
        } catch (_) {}
        LAST_COVERAGE_SEC = computeCoverage(_CACHE.probes);
        updateMetaChips();

        renderCards(_CACHE.probes, since);
        renderAlerts(_CACHE.alerts);
        if (diag) {
          var coverage = formatDurationShort(
            Math.min(rangeDuration(CURRENT_RANGE), LAST_COVERAGE_SEC || 0),
          );
          var target = formatDurationShort(rangeDuration(CURRENT_RANGE));
          diag.textContent =
            "Probes: " +
            (_CACHE.probes.length | 0) +
            " · Alerts: " +
            (_CACHE.alerts.length | 0) +
            " · Interval ≈ " +
            Math.max(5, AUTO_INTERVAL_SEC || 60) +
            "s · Coverage " +
            coverage +
            "/" +
            target +
            " · Updated " +
            new Date().toLocaleTimeString();
        }
        window.toast.info("History loaded");
      })
      .catch(function (e) {
        if (diag) diag.textContent = "Load failed";
        window.toast.error("Load failed: " + e.message);
      });
  }

  function rerenderFromCache() {
    var since = sinceFromRange(CURRENT_RANGE);
    renderCards(_CACHE.probes, since);
    renderAlerts(_CACHE.alerts);
  }

  function refreshServiceSelect() {
    var sel = SERVICE_SELECT || document.getElementById("serviceSelect");
    if (!sel) return;
    SERVICE_SELECT = sel;
    var current = CURRENT_SERVICE || "__all";
    var unique = Object.keys(SERVICE_MAP).sort(function (a, b) {
      return (SERVICE_MAP[a] || a).localeCompare(SERVICE_MAP[b] || b);
    });
    var needsRebuild = sel.dataset._count !== String(unique.length + 1);
    if (!needsRebuild) {
      var values = Array.prototype.map
        .call(sel.options, function (opt) {
          return opt.value;
        })
        .join(",");
      var expected = ["__all"].concat(unique).join(",");
      if (values !== expected) needsRebuild = true;
    }
    if (needsRebuild) {
      sel.innerHTML = "";
      var optAll = document.createElement("option");
      optAll.value = "__all";
      optAll.textContent = "All services";
      sel.appendChild(optAll);
      unique.forEach(function (id) {
        var opt = document.createElement("option");
        opt.value = id;
        opt.textContent = SERVICE_MAP[id] || id;
        sel.appendChild(opt);
      });
      sel.dataset._count = String(unique.length + 1);
    }
    if (current !== "__all" && !SERVICE_MAP[current]) {
      current = "__all";
    }
    sel.value = current;
    CURRENT_SERVICE = current;
  }

  function wire() {
    var selInit = document.querySelector("#rangeSelect");
    if (selInit) {
      CURRENT_RANGE = selInit.value || CURRENT_RANGE;
    }
    SERVICE_SELECT = document.getElementById("serviceSelect");
    ensureMetaChips();
    updateMetaChips();
    var sel = $("#rangeSelect");
    var baseline = $("#baselineToggle");
    var svcSel = $("#serviceSelect");
    var root = $("#history-root");
    AUTOPROBE_UI.toggle = document.getElementById("autoprobeEnabled");
    AUTOPROBE_UI.input = document.getElementById("autoprobeInterval");
    AUTOPROBE_UI.btn = document.getElementById("autoprobeApply");
    AUTOPROBE_UI.status = document.getElementById("autoprobeStatus");

    window.addEventListener("autoprobe:config", function (ev) {
      applyAutoprobeUI(ev.detail || {});
    });
    if (AUTOPROBE_UI.btn) {
      on(AUTOPROBE_UI.btn, "click", function () {
        if (!window.AutoProbe || typeof window.AutoProbe.set !== "function") {
          toastWarn("Autoprobe module not available yet.");
          return;
        }
        var intervalVal = Math.max(
          5,
          parseInt(
            (AUTOPROBE_UI.input && AUTOPROBE_UI.input.value) ||
              AUTO_INTERVAL_SEC ||
              60,
            10,
          ) || 60,
        );
        if (AUTOPROBE_UI.input) {
          AUTOPROBE_UI.input.value = intervalVal;
        }
        setBusy(AUTOPROBE_UI.btn, true);
        window.AutoProbe.set({
          enabled: AUTOPROBE_UI.toggle ? !!AUTOPROBE_UI.toggle.checked : true,
          interval: intervalVal,
        })
          .then(function () {
            window.toast &&
              window.toast.success &&
              window.toast.success("Autoprobe updated");
          })
          .catch(function (err) {
            toastError(
              "Autoprobe update failed: " +
                (err && err.message ? err.message : err || "unknown"),
            );
          })
          .finally(function () {
            setBusy(AUTOPROBE_UI.btn, false);
          });
      });
    }

    function reload(btn) {
      if (btn) setBusy(btn, true);
      ZOOM = null;
      updateMetaChips();
      load(sel ? sel.value : "24h").finally(function () {
        if (btn) setBusy(btn, false);
      });
    }
    on(sel, "change", function () {
      CURRENT_RANGE = sel.value || "24h";
      rerenderFromCache();
      reload();
    });
    on(baseline, "change", rerenderFromCache);
    if (svcSel) {
      on(svcSel, "change", function () {
        CURRENT_SERVICE = svcSel.value || "__all";
        rerenderFromCache();
      });
    }

    var toolbar = root.querySelector(".toolbar") || document;
    on(toolbar, "click", function (ev) {
      var t = ev.target.closest("button");
      if (!t) return;
      var txt = (t.textContent || "").trim().toLowerCase();
      var act = (t.dataset.action || "").toLowerCase();
      if (t.id === "probeBtn" || act === "probe-now" || txt === "probe now") {
        ev.preventDefault();
        var url = root.dataset.probeEval;
        if (!url) {
          window.toast.error("No probe URL");
          return;
        }
        setBusy(t, true);
        var diag = $("#diagBox");
        if (diag) diag.textContent = "Probing…";
        fetchText(url)
          .then(function (res) {
            var j = parseLooseJSON(res.text) || {};
            if (res.ok && !j.error) {
              LAST_PROBE_TS = Math.floor(Date.now() / 1000);
              updateMetaChips();
              window.toast.success("Probe kicked");
            } else {
              throw new Error(j && j.error ? j.error : "HTTP " + res.status);
            }
          })
          .catch(function (e) {
            window.toast.error("Probe failed: " + e.message);
          })
          .finally(function () {
            setBusy(t, false);
            reload();
          });
      }

      if (
        t.id === "resetHistoryBtn" ||
        act === "reset-history" ||
        txt === "reset history"
      ) {
        ev.preventDefault();
        if (
          !confirm(
            "Reset probe & alert history now? This archives current logs.",
          )
        )
          return;
        setBusy(t, true);
        fetchJSON("api/history_rotate.php", { method: "POST" })
          .then(function (j) {
            if (j && j.ok) {
              window.toast.success("History rotated");
            } else {
              throw new Error(j && j.error ? j.error : "Rotate failed");
            }
          })
          .catch(function (e) {
            window.toast.error(e.message || "Rotate failed");
          })
          .finally(function () {
            setBusy(t, false);
            reload();
          });
        return;
      }
      if (t.id === "refreshBtn" || act === "refresh" || txt === "refresh") {
        ev.preventDefault();
        reload(t);
      }
      if (t.id === "exportProbesBtn" || act === "export-probes") {
        ev.preventDefault();
        doExport("probes");
      }
      if (t.id === "exportAlertsBtn" || act === "export-alerts") {
        ev.preventDefault();
        doExport("alerts");
      }
    });

    function doExport(which) {
      var url =
        which === "alerts"
          ? root.dataset.exportAlerts
          : root.dataset.exportProbes;
      var since = sinceFromRange(CURRENT_RANGE);
      var limit = limitForRange(CURRENT_RANGE);
      var extra = "";
      if (which !== "alerts") {
        extra = "&per_service=1&per_service_limit=" + limit;
      }
      fetchJSON(url + "&limit=" + limit + "&start=" + since + extra)
        .then(function (j) {
          var modal = $("#exportModal"),
            pre = $("#exportPre");
          if (!modal) {
            var blob = new Blob([JSON.stringify(j, null, 2)], {
              type: "application/json",
            });
            var a = document.createElement("a");
            a.href = URL.createObjectURL(blob);
            a.download = which + "_export.json";
            a.click();
            setTimeout(function () {
              URL.revokeObjectURL(a.href);
            }, 2000);
            return;
          }
          pre.textContent = JSON.stringify(j, null, 2);
          modal.hidden = false;
          on(
            $("#exportClose"),
            "click",
            function () {
              modal.hidden = true;
            },
            { once: true },
          );
          on(
            $("#exportCopy"),
            "click",
            function () {
              navigator.clipboard.writeText(pre.textContent).then(function () {
                window.toast.success("Copied");
              });
            },
            { once: true },
          );
          on(
            $("#exportDownload"),
            "click",
            function () {
              var blob = new Blob([pre.textContent], {
                type: "application/json",
              });
              var a = document.createElement("a");
              a.href = URL.createObjectURL(blob);
              a.download = which + "_export.json";
              a.click();
              setTimeout(function () {
                URL.revokeObjectURL(a.href);
              }, 2000);
            },
            { once: true },
          );
        })
        .catch(function (e) {
          window.toast.error("Export failed: " + e.message);
        });
    }

    on(window, "resize", throttle(rerenderFromCache, 250));
    on(window, "keydown", function (ev) {
      if (ev.key === "Escape" && ZOOM) {
        ZOOM = null;
        updateMetaChips();
        rerenderFromCache();
        window.toast.info("Zoom cleared");
      }
    });

    loadAutoInterval().finally(function () {
      load(sel ? sel.value : "24h");
    });
  }

  // Tooltip impl (shared)
  function ensureTip() {
    if (window.historyTipElem) return window.historyTipElem;
    var t = document.createElement("div");
    t.id = "historyTip";
    t.style.position = "fixed";
    t.style.zIndex = 9999;
    t.style.pointerEvents = "none";
    t.style.padding = "6px 8px";
    t.style.borderRadius = "8px";
    t.style.fontSize = "12px";
    t.style.background = "rgba(15,15,15,.9)";
    t.style.color = "var(--text-color,#eee)";
    t.style.boxShadow = "0 6px 18px rgba(0,0,0,.35)";
    t.style.border = "1px solid rgba(255,255,255,.12)";
    t.hidden = true;
    document.body.appendChild(t);
    window.historyTipElem = t;
    return t;
  }
  function showTip(html, x, y) {
    var t = ensureTip();
    t.innerHTML = html;
    t.hidden = false;
    const r = t.getBoundingClientRect();
    const nx = Math.min(x + 14, window.innerWidth - r.width - 8),
      ny = Math.min(y + 14, window.innerHeight - r.height - 8);
    t.style.left = nx + "px";
    t.style.top = ny + "px";
  }
  function hideTip() {
    var t = window.historyTipElem;
    if (t) t.hidden = true;
  }

  if (document.readyState === "loading")
    document.addEventListener("DOMContentLoaded", wire);
  else wire();
})();
