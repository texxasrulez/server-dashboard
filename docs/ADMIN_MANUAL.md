# Server Dashboard — Admin & User Manual

This manual captures everything needed to install, operate, and extend the Genesworld Server Dashboard. It combines the scattered quick notes, how‑tos, and README fragments into one canonical reference for administrators and power users.

---

## 1. Overview

The dashboard is a PHP/JavaScript application designed for a self‑hosted LAMP stack. It provides:

- Real‑time status of services, disks, processes, logs, and history probes.
- Cron health, alerts, and automation tooling.
- Backup orchestration and restore helpers.
- Multi‑tenant configuration with CSRF protection and per‑page admin gating.

Most functionality lives in `web-admin/`; persistent data is written to `data/` and `state/`. The app expects PHP 8+, MySQL (for your apps, not for the dashboard), and the ability to run shell utilities for cron/backups.

---

## 2. Prerequisites & Installation

1. **Clone/Unpack** the repository into `/home/<user>/web/<domain>/public_html/web-admin` (or equivalent).
2. Ensure the following directories are writable by the web user:
   - `data/` — configuration exports, backup files, cron tokens.
   - `state/` — runtime JSON streams (`backup_status.json`, services status, alerts).
3. Create `config/local.json` by copying `config/defaults.php` values through the Config UI (see §4).
4. Protect the directory with HTTPS (recommended) and optional HTTP auth.

> **Tip:** The application auto‑creates a cron token (`data/cron_token.txt`) the first time it runs. Keep this secret.

---

## 3. Authentication & Roles

- The dashboard uses the built‑in auth layer (`includes/auth.php`). Sessions are PHP native.
- Roles: `admin` can access everything; `user` can only view safe pages.
- Avatar, role, and username appear in the header menu. Use `users.php` to edit user details and force password resets.

---

## 4. Configuration Hub

Navigate to **Config** to edit `data/local.json`. Each tab corresponds to a top‑level key:

- **Site**: name, base URL, timezone, theme. These values drive the header brand and time formatting.
- **Features**: enable bookmarks/diagnostics/server tests.
- **Mail**: SMTP/sendmail settings. Saved credentials sync with `data/security_config.json`.
- **Integrations**: MySQL/Redis connection info for server tests.
- **Security**: admin emails, CSRF secret, API tokens, IP allow list.
- **Alerts**: email/webhook recipients, cron token, mute presets, service defaults.
- **History/Logs**: retention limits, log watcher settings.
- **Email**: header indicator accounts (stored as a JSON string).

Saving pushes updates to both `data/local.json` and `data/security_config.json` so legacy scripts stay in sync. Use the **“Send Test Email”** button to validate SMTP once credentials are in place.

### CLI Access

`bin/config-cli.php` mirrors the UI schema. Example:

```bash
php bin/config-cli.php get alerts.cron_token
php bin/config-cli.php set site.theme twilight
php bin/config-cli.php dump > backup-config.json
```

---

## 5. Services Monitoring

### Services Page (`services.php`)

- Add/edit entries, export JSON/CSV, import definitions.
- Each row shows the latest probe metadata (latency, HTTP code, uptime %).
- “History” opens a deep‑linked `history.php?service=<id>` tab.
- “Mute alerts” POSTs to `api/alerts_bulk.php` so you can silence noisy rules.
- Auto‑probe interval is coordinated with `assets/js/autoprobe.js` so background checks stay consistent.

### Dashboard (`index.php`)

- `assets/js/index/services.fix.js` renders cards with status pills, type tags, and inline actions.
- Each card exposes:
  - **View history**: opens a filtered view.
  - **Silence alerts**: prompts for minutes and mutes associated alert rules.
- The header sys‑badge also mirrors service health and now incorporates cron status (see §6).

---

## 6. History, Alerts & Cron

### History Page (`history.php`)

- Range selector (1h/24h/7d/30d) down‑samples to stay below ~1,000 points.
- New query params (`?service=ID`) preselect services for deep links.
- Cards provide sparklines, uptime %, CSV export, and inline actions (view history, silence alerts).
- Alerts table shows severity badges, latency/value metadata, and zoom overlays.
- Auto‑probe controls allow immediate probe execution and interval tuning.

### Cron Health (`cron.php`)

1. **Core status cards** show alert/history evaluator freshness, next due time, and allow “Ping now” (calls `api/cron_mark.php`).
2. **Custom jobs**: define JSON under `History → cron.jobs` (or use the wizard). Each job displays last heartbeat, stale threshold, and buttons to copy cron lines or heartbeat URLs.
3. **Crontab helper**: fill in intervals, job IDs, and it generates:
   - `curl` commands for alerts/history.
   - Remote heartbeat URLs (`api/cron_heartbeat.php?id=job&token=...`).
   - Ready‑made cron entries (`crontab -e`) with masked token previews.
4. **Server crontab**: calls `api/cron_list.php` (admin‑only) to read your user’s `crontab -l`. Each entry has a copy button and errors are surfaced if the shell can’t read the file.
5. **Token toolbox**: shows the active cron token (masked) with Copy/Show toggles. URLs update automatically if the token is rotated.

#### Heartbeat API

Use this endpoint for remote hosts or long‑running jobs to signal completion:

```
GET https://<host>/web-admin/api/cron_heartbeat.php?id=JOB_ID&token=<CRON_TOKEN>
```

Optional query parameters:

- `heartbeat=/custom/path/file.txt` to override the default `state/heartbeats/JOB_ID.txt`.
- `ts=<epoch>` to record a custom timestamp.

The Cron Health page reads these files to compute status.

---

## 7. Backups Control Center

### Live Status

- `state/backup_status.json` drives all metrics (disk usage, mount health, snapshot/Hestia/micro ages, warnings/errors).
- Charts (Chart.js) show disk utilization and backup ages; the donut switches to per‑folder view if `disk.folders` is present in JSON.
- `backup_actions.json` (if present) lists the last few backup events with timestamps, job IDs, and logs.

### Action Buttons

- “Run OS Snapshot”, “Run Micro Backup”, “Run Hestia Backup”, “Run ALL Backups” map to `backups_action.php` which triggers your `backupctl` scripts.
- “Clear logs” variants keep `backup_health`, prune, and integrity logs tidy.
- Buttons are CSRF protected via the meta token.

### Backup Orchestration Wizard

At the top of the page (dragged lower if you prefer) you’ll find a generator card:

1. **Inputs**: backup root, exclude paths (`BACKUP_EXCLUDES`), script paths, `backupctl` path, log file, service/timer names, cron time, toggles for `health`, `integrity`, `prune`, and suspend.
2. **Outputs** (auto‑updated):
   - Nightly shell script with logging and stage echos.
   - Cron line (`minute hour * * * user script >> log 2>&1`).
   - Systemd `.service`.
   - Systemd `.timer`.
3. Use the copy buttons to paste the script into `/usr/local/bin/backup-nightly.sh`, and the unit files into `/etc/systemd/system/backup-nightly.service`/`.timer`.
4. Enables you to run **either** cron or systemd timers from the same config.

### Quick Restore Helpers

- Pre‑fills `v-restore-user` and `rsync` commands based on the latest JSON metadata.
- “Run restore (DANGER)” buttons call `backups_action.php` to start restoration (confirmations included).
- Additional CLI reference blocks cover Hestia backup creation, listing, delete, selective restore, and `backupctl` commands.

---

## 8. Diagnostics, Logs & Misc Pages

- **Logs Viewer**: tail files listed in Config → Logs. Supports filters, grep, and live polling.
- **Diagnostics**: server tests, disk/process gauges (index cards), and manual API testers are accessible from `diag.php` and `server_tests.php`.
- **Bookmarks**: quick links to frequently accessed admin URLs.
- **Users**: manage dashboard users, reset passwords, set roles.

Every page respects the global CSRF token and uses `assets/js/app.js` for toasts, hotkeys, theme switching, and mobile nav toggles.

---

## 9. Automation & Scripts

- **backupctl** (documented near the bottom of `backups.php`) is the canonical CLI wrapper for snapshots, micro backups, Hestia commands, health, integrity, prune, and nightly pipelines.
- **Cron tokens**: place them in `site.cron_token`, `alerts.cron_token`, or `security.cron_token`; `includes/init.php` aggregates them with `data/cron_token.txt`.
- **Services probes**: `api/services_probe_all.php` and `api/service_probe.php` accept the cron token so you can trigger health checks from cron or external monitors.
- **History export**: `api/history_export.php?token=...` returns JSON/CSV with optional `limit` and `start` parameters. Use `per_service=1` for multi‑series exports.

---

## 10. Troubleshooting & Tips

| Symptom | Resolution |
| --- | --- |
| `403` from cron `curl` | Confirm the `token=` parameter matches the Cron Health token and that IP allow lists allow the request. |
| History cards empty | Run `api/alerts_eval.php?probe=1&token=...` manually and check `state/services_status_history.jsonl` for corruption. |
| Backups status stuck | Verify `backup_health_check.sh` writes to `state/backup_status.json`. Use `backups_action.php?action=health_check` if scripted. |
| Cron list error | Web user lacks permission to run `crontab -l`. Either run the dashboard under the same user who owns the crontab or wrap the endpoint with `sudo crontab -l -u <user>`. |
| Large log files | Tune `logs.tail_bytes` in Config and leverage log pruning via the Backups page. |

---

## 11. Maintenance Checklist

- **Daily**: glance at Dashboard/History, check cron cards, review backup freshness, address warnings.
- **Weekly**: download a config backup (`config.php` → “Download backup”), verify backup orchestration logs, rotate API tokens if needed.
- **Monthly**: update `backupctl` scripts, prune stale services, archive `state/*.jsonl` (history grows quickly).
- **Before changes**: export `config/local.json` and `data/security_config.json` and snapshot your VPS.

---

## 12. Appendix — Useful Paths & Files

| File/Dir | Purpose |
| --- | --- |
| `config/local.json` | Primary persisted configuration edited via UI. |
| `data/security_config.json` | Legacy compat file for mail + cron tokens. |
| `data/cron_token.txt` | Auto‑generated cron token (share with remote crons). |
| `state/backup_status.json` | Backup health JSON consumed by Backups page. |
| `state/backup_actions.json` | Rolling log of actions triggered from UI. |
| `state/services_status.json` / `.jsonl` | Probe results for services. |
| `state/heartbeats/*.txt` | Cron job heartbeat timestamps. |
| `backups_action.php` | API endpoint invoked by UI buttons; call directly (`action=`) for automation. |
| `api/cron_heartbeat.php` | Heartbeat ingest endpoint. |
| `api/cron_mark.php` | Alert/history cron marker. |
| `api/services_list.php` | Service definitions with metadata. |

Keep this manual updated as features evolve so future administrators have a single source of truth.
