# Index — Hard Drive Status — Instructions
**Updated:** 2025-08-11

## Files
- `assets/js/index/disks.render.js`

## What it does
- Renders 5 disk mini-cards and fills percent bars + temperature.
- Reads from `api/metrics_summary.php` (fields: `disks|disk` object).

## Expected fields (tolerant)
- `usage` (0–100), `used_gb`, `free_gb`, `total_gb`, `temp_c`

## Tuneables
- Adjust which cards render: `ensure()` array in `disks.render.js`.
- Change units/labels in `setDisk()` and `tick()` calculations.
