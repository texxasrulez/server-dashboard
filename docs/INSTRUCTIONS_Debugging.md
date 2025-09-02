# Debugging Playbook

## Front‑end
- Use DevTools → **Console**. Most modules log their payload once (`HDD JSON`, `PROC JSON`).
- Network tab → verify `/api/*.php` return 200 and proper JSON.
- Cache bust: `includes/init.php` has `BUILD`; change it to force new assets.

## Back‑end
- PHP errors: check server error log; ensure `display_errors` is off in prod, on in dev.
- API debug flags:
  - `/api/metrics_summary.php?debug=1` — print extra fields
  - `/api/metrics_summary.php?disk=/path` — test a specific mount
- Permissions: ensure `data/` is writable by the web user (for `users.json`).

## Safe resets
- Delete old `<script>` tags from pages; rely on `/assets/js/*` modules only.
- If login redirects strangely, confirm pages include `includes/init.php` **before** using any helpers.
