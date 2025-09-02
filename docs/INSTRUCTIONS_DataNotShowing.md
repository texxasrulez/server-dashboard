# Data Not Showing — Field Checklist

This page helps you debug when **index.php** cards show placeholders but no data.

---

## 0) Quick URLs
- Metrics API (with debug): `/api/metrics_summary.php?trace=1&debug=1`
- Diagnostics page (admin): `/diag.php`

---

## 1) Confirm the API returns JSON
Open `/api/metrics_summary.php?trace=1&debug=1` in a new tab. You should see keys:
- `disk`, `memory`, and (optionally) `cpu`
- a `trace.elapsed_ms` number

If it’s HTML or an error page, fix the backend first.

---

## 2) Confirm the front‑end is pointing at the right URL
Open DevTools → **Console** and refresh:
- You should see `API metrics endpoint https://…/api/metrics_summary.php` (one per module).

Open DevTools → **Network** and filter for `metrics_summary`:
- Requests should hit `/…/api/metrics_summary.php` and return **200**.

If you see `/api/api/…` or a 404:
- The project now embeds the absolute API URL in `<body data-api-metrics="…">` — ensure your page includes `includes/head.php` (which adds this attribute).

---

## 3) Look for hidden PHP output
If the API returns JSON but cards still don’t update, it may be invalid JSON due to notices/warnings.
- Visit `/diag.php` (admin) and check “Include-run JSON decode”. If it’s **fail**, scroll the “Raw output” for any warning text before `{`.

---

## 4) Disk path + permissions
If `disk.total_bytes` is null:
- Set a path in `config.php`:
```php
define('DISK_METRICS_PATH', '/');
```
- Recheck `/api/metrics_summary.php?debug=1` to confirm `"disk.mount"` and byte fields.

---

## 5) Users store (unrelated to cards, but common)
- `/users.php` (admin) → “Users Storage (debug)” → click **Test write**.
- Fix perms on `data/` or `users.json` if needed.

---

## 6) Still stuck?
Grab:
- The three “API metrics endpoint …” console lines
- The first 30 lines of `/api/metrics_summary.php?trace=1&debug=1`
- Any “HDD/PROC … parse failed” console errors

…and share them. We’ll pinpoint in one pass.
