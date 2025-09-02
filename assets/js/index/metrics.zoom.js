/* assets/js/index/metrics.zoom.js
* Zoomable mini-charts for the Index page. No external libs.
 * Targets canvases: #chartLoad, #chartMem, #chartNet inside .chart containers.
 * Data source: api/metrics_summary.php (CPU load, memory usage, optional net bytes).
 *
 * Interactions:
 *  - Mouse wheel: zoom in/out around cursor
 *  - Drag: pan left/right
 *  - Double click / tap: reset zoom/pan
 */
(function () {
  'use strict';
  // tooltip helpers for sparklines (in-scope)
  function ensureSparkTip(){
    var t=document.getElementById('sparklineTip');
    if(t) return t;
    t=document.createElement('div'); t.id='sparklineTip';
    t.style.position='fixed'; t.style.zIndex=9999; t.style.pointerEvents='none';
    t.style.padding='6px 8px'; t.style.borderRadius='8px'; t.style.fontSize='12px';
    t.style.background='rgba(15,15,15,.9)'; t.style.color='var(--text-color,#eee)';
    t.style.border='1px solid rgba(255,255,255,.12)'; t.style.boxShadow='0 4px 16px rgba(0,0,0,.35)';
    t.hidden=true; document.body.appendChild(t); return t;
  }
  function sparkShowTip(html,x,y){
    var t=ensureSparkTip(); t.innerHTML=html; t.hidden=false;
    var r=t.getBoundingClientRect();
    t.style.left=Math.min(x+14, innerWidth-r.width-8)+'px';
    t.style.top=Math.min(y+12, innerHeight-r.height-8)+'px';
  }
  function sparkHideTip(){
    var t=document.getElementById('sparklineTip'); if(t) t.hidden=true;
  }
;

  const Bus = (window.Dashboard && window.Dashboard.Bus) || new EventTarget();
  function apiUrl(rel){ try{ return new URL(rel, location.origin + location.pathname.replace(/[^\/]*$/, '')); }catch(_){ return rel; } }
  const API = (document.body && document.body.dataset && document.body.dataset.apiMetrics) ? document.body.dataset.apiMetrics : apiUrl('api/metrics_summary.php');
  const POLL_MS = 5000;
  const MAX_POINTS = 900; // ~75 minutes @ 5s
  const MIN_COUNT = 10;   // allow immediate zoom with fewer samples
  const ZOOM_IN_FACTOR = 0.80;  // stronger zoom in
  const ZOOM_OUT_FACTOR = 1.25; // stronger zoom out

  // ---------------- utils ----------------
  function clamp(v, min, max) { return Math.max(min, Math.min(max, v)); }
  function num(x, d=0){ const n = Number(x); return Number.isFinite(n) ? n : d; }
  function colorForPct(p) {
    if (p == null || !isFinite(p)) return '#8aa0b3';
    if (p < 50) return '#26d07c';        // green
    if (p < 80) return '#f1c40f';        // yellow
    return '#e74c3c';                    // red
  }

  // Robust value extraction (handles different JSON shapes/units)
  function getCpuPercent(j){
    let v =
      (j && j.cpu && (j.cpu.percent ?? j.cpu.percentage ?? j.cpu_pct ?? j.cpuPercent)) ??
      (j && j.cpu && (j.cpu.load ?? j.cpu.load1 ?? j.cpu.load_one)) ??
      (Array.isArray(j?.loadavg) ? j.loadavg[0] : undefined) ??
      (Array.isArray(j?.cpu?.loadavg) ? j.cpu.loadavg[0] : undefined);
    v = num(v, 0);
    if (v <= 1.5) v = v * 100; // fraction -> %
    return clamp(v, 0, 100);
  }

  function getMemPercent(j){
    let pct =
      (j && j.mem && (j.mem.percent ?? j.mem.pct ?? j.mem_used_pct)) ??
      (j && j.memory && (j.memory.percent ?? j.memory.pct));
    if (pct != null) return clamp(num(pct,0), 0, 100);

    const used_gb = num(j?.mem?.used_gb, NaN);
    const free_gb = num(j?.mem?.free_gb, NaN);
    const total_gb = num(j?.mem?.total_gb, (isFinite(used_gb) && isFinite(free_gb)) ? (used_gb + free_gb) : NaN);
    if (isFinite(used_gb) && isFinite(total_gb) && total_gb > 0) return clamp((used_gb / total_gb) * 100, 0, 100);

    const used_b = num(j?.mem?.used_bytes ?? j?.mem?.used, NaN);
    const total_b= num(j?.mem?.total_bytes ?? j?.mem?.total, NaN);
    if (isFinite(used_b) && isFinite(total_b) && total_b > 0) return clamp((used_b / total_b) * 100, 0, 100);

    return 0;
  }

  function getMemMeta(j){
    const used_gb = num(j?.mem?.used_gb, NaN);
    const free_gb = num(j?.mem?.free_gb, NaN);
    const total_gb = num(j?.mem?.total_gb, (isFinite(used_gb) && isFinite(free_gb)) ? (used_gb + free_gb) : NaN);
    if (isFinite(used_gb) && isFinite(total_gb) && total_gb > 0) return `${used_gb.toFixed(1)} GB / ${total_gb.toFixed(1)} GB`;

    const used_b = num(j?.mem?.used_bytes ?? j?.mem?.used, NaN);
    const total_b= num(j?.mem?.total_bytes ?? j?.mem?.total, NaN);
    if (isFinite(used_b) && isFinite(total_b) && total_b > 0) {
      const gb = (b)=> (b/1024/1024/1024).toFixed(1)+' GB';
      return `${gb(used_b)} / ${gb(total_b)}`;
    }
    return '';
  }

  function getNetKbs(j, last, dtSec){
    const kbps = j?.net?.kbps ?? j?.net?.kb_s ?? j?.net?.kbs;
    if (kbps != null) return num(kbps, 0);
    const rx = num(j?.net?.rx_bytes ?? j?.net?.rx, 0);
    const tx = num(j?.net?.tx_bytes ?? j?.net?.tx, 0);
    if (!last) return 0;
    const drx = Math.max(0, rx - last.rx);
    const dtx = Math.max(0, tx - last.tx);
    return (drx + dtx) / dtSec / 1024; // KB/s
  }

  function getUptime(j){ return String(j?.uptime ?? j?.system?.uptime ?? ''); }
  function getLoadAvgStr(j){
    const la = j?.loadavg ?? j?.cpu?.loadavg;
    if (Array.isArray(la)) return la.join(', ');
    if (typeof la === 'string') return la;
    return '';
  }

  // ---------------- mini chart ----------------
  class MiniChart {
    constructor(canvas, options = {}) {
      this.canvas = canvas;
      this.ctx = canvas.getContext('2d');
      this.values = [];
      this.label = options.label || '';
      this.unit = options.unit || '';
      this.yMax = options.yMax ?? 100;
      this.dynamicY = options.dynamicY ?? false;
      this.colorMode = options.colorMode || 'byValue'; // or 'fixed'
      this.fixedColor = options.fixedColor || '#66aaff';

      this.viewStart = 0;
      this.viewCount = 300;

      this.dragging = false;
      this.dragStartX = 0;
      this.dragStartView = 0;

      const parent = canvas.parentElement;
      // Named handlers for add/remove
      const __onWheel = (e) => this.onWheel(e);
      const __onDown = (e) => this.onDown(e);
      const __onMove = (e) => this.onMove(e);
      const __onUp   = () => this.onUp();
      const __onDbl  = () => this.resetView();
      const __onResize = () => this.resize();
      const __onHover = (e) => this.hover(e);

      parent.addEventListener('wheel', __onWheel, { passive: false });
      parent.addEventListener('mousedown', __onDown);
      window.addEventListener('mousemove', __onMove);
      window.addEventListener('mouseup', __onUp);
      parent.addEventListener('dblclick', __onDbl);
      parent.addEventListener('mousemove', __onHover);
      parent.addEventListener('mouseleave', () => sparkHideTip());
      parent.addEventListener('touchstart', (e) => { if (e.touches && e.touches.length === 1) this.onDown(e.touches[0]); }, { passive: true });
      parent.addEventListener('touchmove', (e) => { if (e.touches && e.touches.length === 1) this.onMove(e.touches[0]); }, { passive: true });
      parent.addEventListener('touchend', __onUp);
      window.addEventListener('resize', __onResize);

      // Memory-friendly: cleanup on pagehide (browsers may keep page in bfcache)
      function __mz_cleanup(){
        try{
          parent.removeEventListener('wheel', __onWheel, { passive: false });
          parent.removeEventListener('mousedown', __onDown);
          window.removeEventListener('mousemove', __onMove);
          window.removeEventListener('mouseup', __onUp);
          parent.removeEventListener('dblclick', __onDbl);
          parent.removeEventListener('touchstart', (e) => { if (e.touches && e.touches.length === 1) this.onDown(e.touches[0]); }, { passive: true });
          parent.removeEventListener('touchmove', (e) => { if (e.touches && e.touches.length === 1) this.onMove(e.touches[0]); }, { passive: true });
          parent.removeEventListener('touchend', __onUp);
          window.removeEventListener('resize', __onResize);
        }catch(_){}
      }
      window.addEventListener('pagehide', __mz_cleanup, { once: true });

      parent.addEventListener('wheel', (e) => this.onWheel(e), { passive: false });
      parent.addEventListener('mousedown', (e) => this.onDown(e));
      window.addEventListener('mousemove', (e) => this.onMove(e));
      window.addEventListener('mouseup', () => this.onUp());
      parent.addEventListener('dblclick', () => this.resetView());
      // basic touch
      parent.addEventListener('touchstart', (e) => { if (e.touches.length === 1) this.onDown(e.touches[0]); }, { passive: true });
      parent.addEventListener('touchmove', (e) => { if (e.touches.length === 1) this.onMove(e.touches[0]); }, { passive: true });
      parent.addEventListener('touchend', () => this.onUp());

      this.resize();
      window.addEventListener('resize', () => this.resize());
      this.draw();
    }

    resetView() {
      this.viewCount = Math.min(300, Math.max(MIN_COUNT, this.values.length || 300));
      this.viewStart = Math.max(0, (this.values.length - this.viewCount));
      this.draw();
    }

    push(v) {
      if (v == null || !isFinite(v)) v = 0;
      this.values.push(v);
      if (this.values.length > MAX_POINTS) this.values.shift();

      const atRight = (this.viewStart + this.viewCount >= this.values.length - 1);
      if (atRight) this.viewStart = Math.max(0, this.values.length - this.viewCount);

      if (this.dynamicY) {
        const slice = this.values.slice(this.viewStart, this.viewStart + this.viewCount);
        const m = Math.max(1, ...slice);
        this.yMax = m * 1.15;
      }
      this.draw();
    }

    resize() {
      const dpr = window.devicePixelRatio || 1;
      const w = this.canvas.clientWidth || this.canvas.parentElement.clientWidth || 300;
      const h = this.canvas.clientHeight || 120;
      this.canvas.width = Math.floor(w * dpr);
      this.canvas.height = Math.floor(h * dpr);
      this.ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      this.draw();
    }

    onWheel(e) {
      // Ensure we handle zoom even if browser tries to treat as passive
      try { e.preventDefault(); } catch (_) {}
      if (typeof e.stopPropagation === 'function') e.stopPropagation();

      // Choose factor based on direction (stronger steps so it's obvious)
      const factor = (e.deltaY < 0) ? ZOOM_IN_FACTOR : ZOOM_OUT_FACTOR;

      const maxCount = Math.min(MAX_POINTS, this.values.length || MAX_POINTS);
      const minCount = MIN_COUNT;

      // If we still don't have many points, zoom anyway (toward minCount)
      const rect = this.canvas.getBoundingClientRect();
      const x = (e.clientX - rect.left) / Math.max(1, rect.width); // 0..1
      const centerIndex = Math.floor(this.viewStart + x * this.viewCount);

      const oldCount = this.viewCount || maxCount || 300;
      const newCountRaw = Math.round(oldCount * factor);
      const newCount = clamp(newCountRaw, minCount, Math.max(minCount, maxCount));

      // keep cursor anchored
      const leftCount = Math.round((centerIndex - this.viewStart) * (newCount / oldCount));
      this.viewCount = newCount;
      this.viewStart = clamp(centerIndex - leftCount, 0, Math.max(0, (this.values.length - this.viewCount)));

      this.draw();
    }

    onDown(e) {
      this.dragging = true;
      this.dragStartX = e.clientX || 0;
      this.dragStartView = this.viewStart;
    }
    onMove(e) {
      if (!this.dragging) return;
      const rect = this.canvas.getBoundingClientRect();
      const dx = (e.clientX - this.dragStartX) / Math.max(1, rect.width);
      const deltaIdx = Math.round(dx * this.viewCount);
      this.viewStart = clamp(this.dragStartView - deltaIdx, 0, Math.max(0, (this.values.length - this.viewCount)));
      this.draw();
    }
    onUp() { this.dragging = false; }

    hover(e){
      if(this.dragging) return; if(!this.values.length) return;
      const rect=this.canvas.getBoundingClientRect();
      const w=this.canvas.clientWidth||rect.width||300;
      const visCount = Math.max(1, Math.min(this.viewCount, this.values.length));
      const x=Math.max(0, Math.min(e.clientX-rect.left, w));
      const iVis = Math.round((visCount-1) * (w ? (x / w) : 0));
      const idx = Math.max(0, Math.min(this.values.length-1, this.viewStart + iVis));
      const v = this.values[idx];
      const now = Date.now();
      const when = new Date(now - (this.values.length-1-idx) * 5000).toLocaleString();
      const title = this.label || 'Metric';
      const valStr = this.unit === '%' ? Math.round(Math.max(0,Math.min(100,v)))+'%' : (v!=null? v.toFixed? v.toFixed(2) : String(v) : '0');
      const html = '<div style="font-weight:600;margin-bottom:2px;">'+title+'</div>' + '<div>'+when+'</div>' + '<div>value: '+valStr+'</div>';
      sparkShowTip(html, e.clientX, e.clientY);
    }

    draw() {
      const ctx = this.ctx;
      const w = this.canvas.clientWidth || 300;
      const h = this.canvas.clientHeight || 120;
      ctx.clearRect(0, 0, w, h);

      // light grid
      ctx.globalAlpha = 0.25;
      ctx.strokeStyle = '#91a0ad';
      ctx.lineWidth = 1;
      ctx.beginPath();
      for (let i = 1; i <= 3; i++) {
        const y = h * i / 4;
        ctx.moveTo(0, y); ctx.lineTo(w, y);
      }
      ctx.stroke();
      ctx.globalAlpha = 1;

      if (this.values.length < 2) return;
      const start = this.viewStart;
      const end = Math.min(this.values.length, start + this.viewCount);
      const vis = this.values.slice(start, end);
      const n = vis.length;
      if (n < 2) return;

      const yMax = this.yMax || 1;
      const xStep = w / Math.max(1, n - 1);

      ctx.lineWidth = 2.0;
      for (let i = 1; i < n; i++) {
        const v0 = Math.max(0, Math.min(yMax, vis[i - 1]));
        const v1 = Math.max(0, Math.min(yMax, vis[i]));
        const x0 = (i - 1) * xStep;
        const x1 = i * xStep;
        const y0 = h - (v0 / yMax) * h;
        const y1 = h - (v1 / yMax) * h;

        let stroke = this.fixedColor;
        if (this.colorMode === 'byValue') {
          const pct = Math.max(0, Math.min(100, (v1 / yMax) * 100));
          stroke = colorForPct(pct);
        }
        ctx.strokeStyle = stroke;
        ctx.beginPath();
        ctx.moveTo(x0, y0);
        ctx.lineTo(x1, y1);
        ctx.stroke();
      }
    }
  }

  // ---------------- module state ----------------
  const state = {
    charts: { load: null, mem: null, net: null },
    lastNet: null,
    ov: { load: null, mem: null, net: null } // per-chart overlays
  };

  function ensureCharts() {
    if (!state.charts.load) {
      const c1 = document.getElementById('chartLoad');
      const c2 = document.getElementById('chartMem');
      const c3 = document.getElementById('chartNet');

      if (c1) state.charts.load = new MiniChart(c1, { label: 'CPU Load', unit: '%', yMax: 100, dynamicY: false, colorMode: 'byValue' });
      if (c2) state.charts.mem = new MiniChart(c2, { label: 'Memory Used', unit: '%', yMax: 100, dynamicY: false, colorMode: 'byValue' });
      if (c3) state.charts.net = new MiniChart(c3, { label: 'Net KB/s', unit: 'KB/s', yMax: 2000, dynamicY: true, colorMode: 'fixed', fixedColor: '#5aa9ff' });

      // capture the 3 overlays in the same order as the charts
      const ovs = Array.from(document.querySelectorAll('.charts .chart .overlay'));
      state.ov = {
        load: ovs[0] || null,
        mem:  ovs[1] || null,
        net:  ovs[2] || null
      };
      for (const el of ovs) if (el) el.textContent = 'wheel: zoom • drag: pan • dblclick: reset';
    }
  }

  async function sample() {
    ensureCharts();
    try {
      const r = await fetch(API, { cache: 'no-store' });
      if (!r.ok) throw 0;
      const j = await r.json();

      // Values with robust extraction
      const cpuPct = getCpuPercent(j);
      const memPct = getMemPercent(j);

      // Push to charts
      if (state.charts.load) state.charts.load.push(cpuPct);
      if (state.charts.mem)  state.charts.mem.push(memPct);

      // Network KB/s
      const kbs = getNetKbs(j, state.lastNet, POLL_MS/1000);
      const rx = num(j?.net?.rx_bytes ?? j?.net?.rx, 0);
      const tx = num(j?.net?.tx_bytes ?? j?.net?.tx, 0);
      state.lastNet = { rx, tx };
      if (state.charts.net) state.charts.net.push(kbs);

      // ----- per-chart overlays -----
      const up = getUptime(j);
      const loadStr = getLoadAvgStr(j);
      const memStr = getMemMeta(j);
      if (state.ov?.load) {
        state.ov.load.innerHTML =
          `Uptime: ${up}<br>Load avg: ${loadStr}<br><span style="opacity:.7">wheel: zoom • drag: pan • dblclick: reset</span>`;
      }
      if (state.ov?.mem) {
        state.ov.mem.innerHTML =
          `Memory: ${memStr}<br><span style="opacity:.7">wheel: zoom • drag: pan • dblclick: reset</span>`;
      }
      if (state.ov?.net) {
        const nowKb = (typeof kbs === 'number' && isFinite(kbs)) ? `${kbs.toFixed(1)} KB/s` : '—';
        state.ov.net.innerHTML =
          `Net: ${nowKb}<br><span style="opacity:.7">wheel: zoom • drag: pan • dblclick: reset</span>`;
      }
    } catch (e) {
      // swallow transient fetch errors
    }
  }

  // ---------------- lifecycle ----------------
  let timer = null;
  try{ console.debug('API metrics endpoint', API.toString ? API.toString() : API); }catch(_){}
  function start() { if (!timer) { sample(); timer = setInterval(sample, POLL_MS); } }
  function stop() { if (timer) clearInterval(timer), timer = null; }

  document.addEventListener('DOMContentLoaded', () => { ensureCharts(); start(); });
  Bus.addEventListener('dashboard:tick', sample);

  // debug handle
  window.IndexMetricsZoom = { start, stop, sample };
})();
