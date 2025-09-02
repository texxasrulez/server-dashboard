# Index — Hard Drive Cards

Location:
- CSS: `assets/css/index_disks.cards.css`
- JS:  `assets/js/index/disks.render.js`
- Markup container: `#diskGrid` in `index.php`

## Features
- 5 cards: Usage, Total, Used, Free, Temp
- Color states (`ok|warn|bad`) using theme tokens `--up`, `--warn`, `--down`
- Stale indicator (LED dims) if no API update for **30s**
- Live polling every **5s**
- Slide‑in intro + smooth progress animations

## Data
Pulled from `api/metrics_summary.php` → object `disk`:
```json
{ "mount": "/", "total_bytes": 123, "free_bytes": 45, "used_bytes": 78, "used_percent": 63.2, "temp_c": null }
```
The JS is tolerant of field names (`used_percent` or `usage`, `*_bytes` or `*_gb`).

## Tuning
- Thresholds in JS:
  - Usage/Used: warn ≥ 75, bad ≥ 90
  - Free: warn ≤ 25, bad ≤ 10
  - Temp: warn ≥ 60°C, bad ≥ 80°C
- Poll rate: edit `POLL_MS` inside `disks.render.js`.
- Staleness: 30s window in `disks.render.js`.

## Debug
- Open DevTools → **Console**; first update logs `HDD JSON` once.
- API: visit `/api/metrics_summary.php?debug=1` to see chosen disk path and tried paths.
