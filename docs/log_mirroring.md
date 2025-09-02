
# Log Mirroring & Viewer

- Mirrors `.log`, `.log.N`, and `.log.gz` from `/var/log` into `state/logs_mirror` on page load.
- Pulldown auto-populates from the mirror (recursive).
- Viewer tails last N lines, streams `.gz` without full decompress.
- **Manage Logs** modal lets you add presets, list/delete mirror items, and unzip `.zip` archives into `state/logs_mirror/unzipped/`.
- Mirror operations are recorded in `state/mirror_activity.log`.

## Category colors
Security, Warning, Database, Network, Performance, Info → styled via `.badge.<category>` in `assets/css/pages/logs.css`.
