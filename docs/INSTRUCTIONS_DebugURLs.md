# Debug URLs & Tracing

## Toggle the admin debug panel
- Press **Shift+D** (admin only). Use "Trace APIs" to add `?trace=1` to API calls.

## Useful API endpoints
- Metrics: `/api/metrics_summary.php?trace=1&debug=1`
- Services (list): `/api/services_list.php?trace=1`
- Services (status file): `/api/services_status.php?trace=1`
- Services (CRUD): `/api/services.php?fn=list&trace=1`
- Debug ping: `/api/debug_ping.php?trace=1`

## Notes
- `trace=1` adds `{"trace":{"elapsed_ms":...}}` to responses where supported.
- `debug=1` (metrics only) adds extra details such as disk path selection.
- If your site is deployed in a nested path, the front-end resolves APIs relative to the project root automatically.
