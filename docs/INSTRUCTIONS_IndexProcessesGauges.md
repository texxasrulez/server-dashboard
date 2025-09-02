# Index — Server Processes Gauges (Half‑Moon)

Location:
- CSS: `assets/css/index_proc.gauges.css`
- JS:  `assets/js/index/proc.render.js`
- Markup container: `#procGrid` in `index.php`

## Features
- Half‑moon SVG gauges for: CPU, Memory Used, Memory Free, Buffers, Cache
- Smooth arc sweep + LED color transitions; slide‑in intro
- Live polling every **5s**
- Stale indicator (LED dims) if no API update for **30s**

## Data
Pulled from `api/metrics_summary.php`:
- `cpu`: `{ cores, load1, load5, load15 }`  
  Gauge % = `load1 / cores * 100` (falls back to 1 core if unknown)
- `memory`: `total_bytes`, `used_bytes`, `free_bytes`, `buffers_bytes`, `cached_bytes`

## Tuning
- Thresholds in JS:
  - CPU/Mem Used: warn ≥ 75, bad ≥ 90
  - Mem Free: warn ≤ 25, bad ≤ 10
- Poll rate: edit `POLL_MS` inside `proc.render.js`

## Debug
- Open DevTools → **Console**; first update logs `PROC JSON` once.
- API: visit `/api/metrics_summary.php?debug=1` to see any extra debug fields.
