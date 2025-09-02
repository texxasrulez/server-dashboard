# Diagnostics Page

URL: `/diag.php` (admin only)

Shows:
- Build, user, theme
- Server-resolved metrics URL
- Whether `api/metrics_summary.php` exists
- Runs the API server-side and reports JSON decode status
- First 400 chars of raw output (helps catch warnings before JSON)
- Users storage path and writability snapshot

Use this when index cards show no data, or when API endpoints might be mis-resolved.
