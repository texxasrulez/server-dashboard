# Index — Zoomable Metrics (CPU/Mem/Net) — Instructions
**Updated:** 2025-08-11

## Files
- `assets/js/index/metrics.zoom.js`
- `assets/css/index_metrics.zoom.css`

## What it does
- Plots CPU %, Memory %, and Net KB/s on three canvases, with zoom/pan.
- Overlays show: CPU (Uptime + Load Avg), Memory (used/total), Net (current KB/s).

## Endpoints
- Polls `api/metrics_summary.php` every `POLL_MS` (default 5000ms).

## Tuneables (edit in JS)
- `POLL_MS` — poll period; lower for faster updates.
- Color thresholds — in `colorForPct(p)` (50/80 by default).
- Zoom minimum window — `minCount` inside `onWheel` (default 30).
- Network dynamic Y scale — edit `yMax`/`dynamicY` in the Net chart options.

## JSON compatibility
The module is tolerant to different field names/units (bytes vs GB, fractions vs percents). If you add fields, update the extractor helpers near the top: `getCpuPercent`, `getMemPercent`, `getNetKbs`.
