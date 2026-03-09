# Server Dashboard

![Downloads](https://img.shields.io/github/downloads/texxasrulez/server-dashboard/total)
[![GitHub Downloads](https://img.shields.io/github/downloads/texxasrulez/server-dashboard/total?style=plastic&logo=github&logoColor=white&label=Downloads&labelColor=blue&color=aqua)](https://github.com/texxasrulez/server-dashboard/releases)
[![Github License](https://img.shields.io/github/license/texxasrulez/server-dashboard?style=plastic&logo=github&label=License&labelColor=blue&color=coral)](https://github.com/texxasrulez/server-dashboard/LICENSE)
[![GitHub Stars](https://img.shields.io/github/stars/texxasrulez/server-dashboard?style=plastic&logo=github&label=Stars&labelColor=blue&color=deepskyblue)](https://github.com/texxasrulez/server-dashboard/stargazers)
[![GitHub Issues](https://img.shields.io/github/issues/texxasrulez/server-dashboard?style=plastic&logo=github&label=Issues&labelColor=blue&color=aqua)](https://github.com/texxasrulez/server-dashboard/issues)

Here is a Server Dashboard to have an overall view of server health and status in just a few pages to check.
It has lots of features, too many to list. One day I will have a better README.md

---

## Install / Update

1. Drop the files into your project root.

2. Bootstrap first admin from CLI:
   - `php bin/bootstrap-admin.php`
   - Sign in as `admin` with the printed password and rotate it immediately in `users.php`.

3. Verify file permissions so PHP can create/replace:
   - There are several files that get written to. Ensure proper permissions are in place.
   - There is a .sh script for log monitoring and copying. Check assets/scripts/install.sh and Log_watcher_README.md for instructions.

4. (Optional) Set environment variables for deploy-wide defaults:
   - `DASH_MAIL_TRANSPORT`, `DASH_MAIL_FROM`, `DASH_MAIL_REPLYTO`, `DASH_SMTP_HOST`, `DASH_SMTP_PORT`, `DASH_SMTP_SECURE`, `DASH_SMTP_USER`, `DASH_SMTP_PASS`, `DASH_SMTP_TIMEOUT`
   - `DASH_ALERT_EMAILS`
   - `DASH_CRON_TOKEN` (if you don’t want to set `CRON_TOKEN` in code)
   - `BACKUP_HISTORY_KEEP_DAYS`, `BACKUP_CONFIG_KEEP_COUNT`, `BACKUP_ROTATE_KEEP_DAYS` (optional backup-prune overrides)

5. Generate script environment defaults (recommended):
   - `php bin/install-scripts.php`
   - Or one-step system install (requires root): `php bin/install-scripts.php --install-system`
   - This writes `state/generated/dashboard-scripts.env` from your current dashboard config.
   - For cron/systemd jobs, either:
     - set `DASHBOARD_SCRIPTS_ENV=/path/to/dashboard-scripts.env`, or
     - copy helper/env files to `/etc/server-dashboard/`:
       - `scripts/lib/dashboard_env.sh` -> `/etc/server-dashboard/dashboard_env.sh`
       - `state/generated/dashboard-scripts.env` -> `/etc/server-dashboard/scripts.env`

---

## Configuration

- **Web UI (recommended):** visit `config.php` (admin only). Every save writes `config/local.json`, keeps `data/security_config.json` in sync for legacy pages, and offers inline backups (Config → Site → “Create backup”). Backups now live under `config/backups/` and can be pruned/downloaded from that tab.
- **Security rotation:** use `php bin/rotate-runtime-secrets.php --apply` to rotate app-managed tokens/secrets, then rotate external provider credentials manually. Full guide: `docs/SECURITY_SECRETS.md`.

- **CLI helper:** use `bin/config-cli.php` for automation/headless changes.

  ```bash
  php bin/config-cli.php get site.name
  php bin/config-cli.php set mail.smtp_host smtp.example.net
  php bin/config-cli.php set-json mail.sec_email '["ops@example.com","oncall@example.com"]'
  php bin/config-cli.php unset mail.smtp_host
  php bin/config-cli.php dump
  ```

  The CLI loads the exact same schema/validation as the UI, so secrets/cron tokens replicate into `data/security_config.json` automatically.

- **API compatibility:** `api/security_get.php` / `api/security_set.php` now proxy through the same Config engine. They remain available for headless integrations but no longer require the standalone `security.php` page.

- **Backups tab:** configure filesystem roots and the log watcher from Config → Backups. The UI lists the folders the Backups dashboard monitors and generates ready-to-run helpers (install command + env file snippet) for `assets/scripts/install.sh` based on your saved paths/owner/service name.
- **Script portability:** root scripts under `scripts/` now resolve dashboard paths/email defaults from:
  1. `DASHBOARD_SCRIPTS_ENV` (if set),
  2. `/etc/server-dashboard/dashboard_env.sh` + `/etc/server-dashboard/scripts.env`,
  3. local `scripts/lib/dashboard_env.sh`,
  4. repository-relative fallback.
- **Ops scripts:**
  - `bash bin/security-policy-check.sh` (single security gate: static auth/CSRF checks + hardening regressions)
  - `bash bin/security-hardening-check.sh` (runs only the hardening regression subset)
  - `bash bin/report-largest-files.sh --top 20` (storage growth visibility)
  - Web server deny rules: `docs/WEBSERVER_HARDENING.md`
  - First admin bootstrap: `php bin/bootstrap-admin.php`

- **Internet-facing hardening (recommended defaults):**
  - Keep `security.allow_web_bootstrap_admin=false` (default).
  - Set `security.trusted_proxies` if running behind reverse proxy/CDN.
  - Keep `security.favicon_require_admin=true` (default) and set `security.favicon_allowed_hosts` if needed.
  - Tune `security.login_rate_limit` for your environment.

---

## Pages

### `processes.php` (read-only)

- Shows live process data from `/proc` with a web table (PID, USER, CPU%, RSS, STATE, CMD).
- Uses `api/processes.php` and auto-refreshes every 2 seconds (pauses while tab is hidden).
- Includes sort/limit/filter controls and a read-only details modal (full escaped cmdline + metadata).
- No process control actions are exposed in this iteration (no kill/renice).

### `history.php`

- Toolbar: Range select (1h/24h/7d/30d), `Probe now`, `Refresh`, `Export JSON`
- Loads from `api/history_export.php?type=probes&limit=1000&start=0`, filters client-side by range
- Sparklines render via `<canvas>` with theme-friendly colors
- `Probe now` calls `api/probe_now.php` (admin session or token) — no stray output, always JSON

**Note:** History export endpoint is assumed to exist from your original codebase.

---

## APIs

- `api/processes.php` — read-only process snapshot API (auth required)
  - Query params (validated + allowlisted): `sort=cpu|mem|pid|user|cmd`, `limit=10..200`, `filter`, `user`
  - Returns: `ts`, `host`, `loadavg`, `uptime`, `processes[]`
  - Security/perf: `/proc`-based collection, no user shell args, short server-side cache (APCu if available, file fallback)

- `api/security_get.php` — returns current settings (admin session OR token)
  - Accepts `alert_emails` or aliases: `email`, `emails`, `alerts_emails`, `alert_email`
- `api/probe_now.php` — runs the underlying probe script and returns `{"ok":true}` on success
  - Auth: admin session OR token (`X-CRON-TOKEN`, `Authorization: Bearer`, or `?token=`)

All three support the common token resolution chain:

1. `CRON_TOKEN` constant (from config)
2. `DASH_CRON_TOKEN` environment
3. `state/cron_token.txt` file

---

## 1) Quick Orientation

**Structure:**

```
/
  api/                    # JSON endpoints (admin session or token)
  assets/
    css/
      pages/              # per-page CSS
      components/         # shared components (toasts, nav highlight, etc.)
    js/
      pages/              # per-page JS
      nav-active.js       # active tab glow (no PHP changes required)
  data/                   # editable configuration data (e.g., alerts.json)
  includes/
    auth.php              # auth helpers (no redeclared symbols)
    config.inc.php        # single source of truth for config (defines only)
    foot.php              # page foot (global scripts)
    head.php              # page head (global CSS, header UI)
    init.php              # bootstrap (loads config.inc.php first)
  state/                  # generated runtime data (history, audit, etc.)
  *.php                   # pages (history.php, services.php, alerts_admin.php, ...)
```

**Key convention:** all page‑specific JS/CSS live under `assets/js/pages/*` and `assets/css/pages/*`.

---

### A. Alerts (rules) admin

- **Rule model**: `service_id`, `service_name`, `metric` (status|latency_ms|http_code|packet_loss_pct), `op` (`> >= == <= < !=`), `threshold`, `consecutive`, `cooldown_min`, `notify.email`, `notify.webhook_url`, `enabled`.
- **Consecutive & cooldown** support to reduce noise.
- **Notify targets**: email + webhook (JSON POST of event).
- **Counters** (`times_triggered`, `last_triggered`) persisted back into `data/alerts.json`.
- **Form toasts & validation** in the Larry style.

**Files/State:**

```
data/alerts.json               # rules
state/alerts_events.jsonl      # fired events history
api/alerts_eval.php            # evaluator (admin session OR token)
```

### B. Security (mailer settings)

- **GET/POST APIs** to **load/save** mailer settings without page reloads:
  - `api/security_get.php` – returns current (sanitized) config view
- **Transports**: `phpmail` (default), `sendmail` (path configurable), `smtp` (host/port/secure/user/pass/timeout).
- **Auth**: require admin session; for headless calls, you can use an **X‑CRON‑TOKEN** header (equal to `CRON_TOKEN`) as a second path.
- **Larry toasts** for “loaded/saved/failed.”
- **Mailer smoke test** available: `api/mail_test.php?to=you@domain` (403 unless admin or header token).

**Files/State:**

```
includes/config.inc.php        # one bootstrap source of truth
api/security_get.php
api/security_set.php
api/mail_test.php
```

### C. Bookmarks

- Bookmarks page & API; store and fetch bookmark entries; basic UI wired.
- The **Categories** feature (add/edit/delete/sort) is planned; see Roadmap.

---

## 2) Data & State Files

- `data/alerts.json` — alert rules. Example:
  ```json
  {{ "items": [ {{ "id":"rule1","name":"Web slow","service_id":"svc_9005fc84a361",
      "service_name":"Frontdoor","metric":"latency_ms","op":">","threshold":400,
      "consecutive":3,"cooldown_min":30,"severity":"warn",
      "notify":{{"email":"ops@example.com","webhook_url":""}},"enabled":true }} ] }}
  ```
- `state/services_status.json` — latest probe snapshot per service (`items: [...]`).
- `data/services_status_history.jsonl` — rolling history (line‑delimited JSON).
- `state/alerts_events.jsonl` — fired alert events (line‑delimited JSON).
- (removed) no local PHP overrides; use `includes/config.inc.php` only.

> _Tip:_ rotate/backup `*.jsonl` with `logrotate` or a cron script if you expect large volume.

---

## 3) API Cheatsheet

All endpoints default to **admin session required**. For headless jobs use header:

```
X-CRON-TOKEN: <your CRON_TOKEN>
```

or a `token=` query where supported (see below).

### Probes & Alerts

- **Trigger evaluator (optionally probe first)**
  - `GET api/alerts_eval.php?probe=1&dry=0&token=CRON_TOKEN`
    - `probe=1` best effort probe refresh before evaluation
    - `dry=1` test without persisting counters/history (History UI auto‑flips to `dry=0`)
- **Export history**
  - `GET api/history_export.php?type=probes&limit=1000&start=0[&token=CRON_TOKEN]`
  - `GET api/history_export.php?type=alerts&limit=1000&start=0[&token=CRON_TOKEN]`
  - Controlled by `ALLOW_HISTORY_EXPORT_WITH_TOKEN` (defaults to **1**).

### Security (Mailer)

- **Get settings**
  - `GET api/security_get.php`
- **Save settings**
  - `POST api/security_set.php`
    ```json
    {{
      "mail_transport":"phpmail|sendmail|smtp",
      "mail_from":"Domain Alerts <alerts@domain>",
      "mail_replyto":"ops@domain",
      "sendmail_path":"/usr/sbin/sendmail",
      "smtp_host":"smtp.domain",
      "smtp_port":587,
      "smtp_secure":"tls|ssl|none",
      "smtp_user":"user",
      "smtp_pass":"secret-or-empty-to-clear",
      "smtp_timeout":12
    }}
    ```
- **Mail test**
  - `GET api/mail_test.php?to=you@domain`

### Bookmarks (current)

- `GET api/bookmarks_get.php` → list
- `POST api/bookmarks_set.php` → upsert/delete
  - _Categories/sorting coming soon; see Roadmap._

---

## 4) Configuration

Single source of truth:

```
includes/config.inc.php
```

**Notables:**

- `CRON_TOKEN` — shared secret for non‑session API access (also accepted via `X-CRON-TOKEN`).
- `MAIL_TRANSPORT` / `MAIL_FROM` / `MAIL_REPLYTO` / `SENDMAIL_PATH` / `SMTP_*`
- `ALLOW_HISTORY_EXPORT_WITH_TOKEN` (1/0)
- `DISK_METRICS_PATH` (optional override for disk stats)
- `THEME_DEFAULT` — default theme slug
- `CLIENT_DEBUG_LOG` — enable JS console debug
- `BUILD` — cache‑busting tag
- `processes.default_limit` — default API/page limit (10..200)
- `processes.cache_ttl_sec` — process sample cache TTL (1..10, default 2)
- `capabilities.panel|web|mta|db` — optional capability hints (`auto` by default)

### Capability adapters

- New scaffolding under `Adapters/` introduces capability-driven behavior:
  - `GenericLinuxAdapter`
  - `HestiaAdapter`
- Factory detection uses config hints first, then safe filesystem checks and strict allowlisted `systemctl is-active` probes.
- Existing pages now render capability chips using the adapter interface (minimal integration, no large refactor).

---

## 5) Cron Examples

**Evaluate alerts every 5 minutes:**

```
*/5 * * * * curl -fsS 'https://YOUR_HOSTapi/alerts_eval.php?probe=1&dry=0&token=YOUR_CRON_TOKEN' >/dev/null
```

**Export a nightly snapshot:**

```
0 2 * * * curl -fsS 'https://YOUR_HOSTapi/history_export.php?type=probes&limit=50000&start=0&token=YOUR_CRON_TOKEN' -o /var/backups/probes_$(date +\%F).json
```

---

## 6) Known Quirks (tracking)

## 7) Quick Manual Verification Plan

1. Open `processes.php`, verify table loads and updates every ~2 seconds.
2. Call `api/processes.php?sort=cpu&limit=25` and verify JSON fields `ts/host/loadavg/uptime/processes`.
3. Verify CPU% changes over successive refreshes for active processes.
4. Verify sort/limit/filter behavior (`cpu`, `mem`, `pid`, `user`, `cmd`).
5. Verify API input clamping (`limit=1` -> 10, `limit=9999` -> 200, unknown sort -> `cpu` fallback).
6. Verify graceful failure by testing on a host/environment without `/proc` (expect JSON error response).
7. Refresh page repeatedly and confirm server load does not spike (cache active).

---

## 7) Roadmap (near term)

1. **Bookmarks v2**
   - Categories CRUD (add/edit/delete), sort order, assign items to categories.
   - Per‑category drag‑sort and collapsed sections.
   - Favicon fetcher with local caching.
2. **History UX**
   - Multi‑series overlay by tag/service‑group.
   - Zoom presets (last outage, last probe window).
   - PNG export of a card.
3. **Process Manager**
   - `processes.php` page: ps/ss snapshots, restart hooks (pluggable), editable config for whitelisted daemons, health statuses, and alert hooks.
4. **Alerts**
   - Rule templates (e.g., “HTTP >= 400 for 3x in 5m”).
   - Escalation chains and quiet hours.
   - Per‑rule enable/disable toggles on History card headers.
5. **Security**
   - Token management UI (rotate CRON token, view audit trail).
   - SMTP STARTTLS test & TLS version display.
6. **Exports & Reporting**
   - Bulk ZIP export (alerts, probes, logs).
   - SLA/uptime monthly report generator (HTML/CSV).
7. **Hardening & QA**
   - Minimal PHPUnit suite for core helpers.
   - Smoke tests for API endpoints; CI job for lint + basic HTTP probes.

---

## 3) Configuration Model

Single-source configuration in **`includes/config.inc.php`**. Reading order:

1. **Environment variables** (with optional `DASH_` prefix). Example: `DASH_CRON_TOKEN`.
2. Sensible defaults (defined once).

Important keys:

- `CRON_TOKEN` — shared secret for headless calls.
- `MAIL_TRANSPORT` — `phpmail` | `sendmail` | `smtp`
- `MAIL_FROM`, `MAIL_REPLYTO`
- `SENDMAIL_PATH`
- `SMTP_HOST`, `SMTP_PORT`, `SMTP_SECURE` (`tls|ssl|none`), `SMTP_USER`, `SMTP_PASS`, `SMTP_TIMEOUT`
- `ALLOW_HISTORY_EXPORT_WITH_TOKEN` — allow `token=` in export URLs (default **1**)
- `DISK_METRICS_PATH` — optional override for disk stats
- `THEME_DEFAULT` — default theme slug
- `CLIENT_DEBUG_LOG` — `1` enables client console logging
- `BUILD` — version string for cache busting (from env, `state/build.txt`, or timestamp)

---

## 4) Authentication & Tokens

- **Browser**: login as admin (standard session cookie).
- **Headless/cron**: send header `X-CRON-TOKEN: <CRON_TOKEN>` or use `token=<CRON_TOKEN>` on endpoints that support it (exports/eval).  
  403 means missing/invalid token or not an admin session.

---

## 5) Pages & Features

### 5.1 History (`history.php`)

Sparkline grid of service probes with interactive tools.

- **Range selector**: `1h / 24h / 7d / 30d`
- **Baseline toggle**: draws a neutral baseline
- **Tooltips**: timestamp, latency, HTTP code, up/down
- **Zoom & Pan**:
  - Drag-select to zoom
  - Wheel to zoom; Shift+wheel to pan
  - “Clear zoom” micro-button appears while zoomed
- **Probe now**: fires evaluator with `probe=1&dry=0` and refreshes twice
- **Refresh**: fetches latest series and events
- **“Last probe” chip**: never regresses after a manual probe; reconciles when new rows arrive
- **Exports**: full **Export JSON** (modal preview/copy/download) and **per-card CSV**

Files:

```
assets/js/pages/history.js
assets/css/pages/history.css
api/history_export.php            # export (probes/alerts)
api/alerts_eval.php               # probe+evaluate endpoint (see API)
state/services_status.json        # latest sample per service
data/services_status_history.jsonl# long history (JSONL)
```

### 5.2 Alerts Admin (`alerts_admin.php`)

Rule builder with cool-down and consecutive failure logic.

- **Rule fields**: name, service, metric (`status|latency_ms|http_code|packet_loss_pct`), operator (`> >= == <= < !=`), threshold, consecutive, cooldown minutes, severity, notify targets
- **Notify**: email + webhook (JSON POST event)
- **Counters**: `times_triggered`, `last_triggered` persisted to `data/alerts.json`
- **Actions**: Enable/Disable/Delete/Silence; JSON preview
- **Larry toasts** for success/fail

Files:

```
data/alerts.json                  # rules
state/alerts_events.jsonl         # fired events
api/alerts_eval.php               # evaluator
```

### 5.4 Services (`services.php`)

- Themed action buttons (`.btn`, `.btn.secondary`, `.btn.warning`, `.btn.danger` for Delete)
- Consistent UI chips and cards

### 5.5 Bookmarks (`bookmarks.php`)

- First pass CRUD and listing
- **Roadmap**: categories with sorting, collapsible sections, favicon caching

### 5.6 Logs / Audit / Diagnostics / Config

- Logs styled with unified buttons
- Audit trail entries to `state/audit.log.jsonl`

---

## 6) APIs

All endpoints require admin session; headless calls can use the token header.

### 6.1 Probes & Alerts

- **Evaluate (optionally probe first)**

  ```
  GET api/alerts_eval.php?probe=1&dry=0&token=CRON_TOKEN
  ```

  - `probe=1` tries to refresh probe data
  - `dry=0` persists counters & history (`history.js` forces this on “Probe now”)

- **Export history**
  ```
  GET api/history_export.php?type=probes&limit=1000&start=0[&token=CRON_TOKEN]
  GET api/history_export.php?type=alerts&limit=1000&start=0[&token=CRON_TOKEN]
  ```

### 6.2 Mailer (Security)

- **Get settings**
  ```
  GET api/security_get.php
  ```
- **Save settings**

  ```http
  POST api/security_set.php
  Content-Type: application/json

  {{
    "mail_transport":"phpmail|sendmail|smtp",
    "mail_from":"Domain Alerts <alerts@domain>",
    "mail_replyto":"ops@domain",
    "sendmail_path":"/usr/sbin/sendmail",
    "smtp_host":"smtp.domain",
    "smtp_port":587,
    "smtp_secure":"tls|ssl|none",
    "smtp_user":"user",
    "smtp_pass":"secret-or-empty-to-clear",
    "smtp_timeout":12
  }}
  ```

- **Test mail**
  ```
  GET api/mail_test.php?to=you@domain
  ```

### 6.3 Bookmarks

```
GET  api/bookmarks_get.php
POST api/bookmarks_set.php
```

---

## 7) Cron Examples

**Evaluate alerts every 5 minutes:**

```
*/5 * * * * curl -fsS 'https://YOUR_HOSTapi/alerts_eval.php?probe=1&dry=0&token=YOUR_CRON_TOKEN' >/dev/null
```

**Nightly export of probe history:**

```
0 2 * * * curl -fsS 'https://YOUR_HOSTapi/history_export.php?type=probes&limit=50000&start=0&token=YOUR_CRON_TOKEN' -o /var/backups/probes_$(date +\%F).json
```

**Preload security form:**

```
curl -fsS 'https://HOSTapi/security_get.php'   -H 'X-CRON-TOKEN: YOUR_CRON_TOKEN' | jq .
```

**Save mailer (phpmail):**

```
curl -fsS 'https://HOSTapi/security_set.php'   -H 'Content-Type: application/json'   -H 'X-CRON-TOKEN: YOUR_CRON_TOKEN'   --data '{"mail_transport":"phpmail","mail_from":"Domain Alerts <alerts@host>","mail_replyto":"ops@host"}' | jq .
```

**Run a probe + evaluate:**

```
curl -fsS 'https://HOSTapi/alerts_eval.php?probe=1&dry=0&token=YOUR_CRON_TOKEN' | jq .
```

**Export latest probes:**

```
curl -fsS 'https://HOSTapi/history_export.php?type=probes&limit=1000&start=0&token=YOUR_CRON_TOKEN' | jq .
```

---

## 8) Data & State Files

- `data/alerts.json` — alert rules (JSON)
- `state/alerts_events.jsonl` — fired alerts (one JSON object per line)
- `state/services_status.json` — latest status per service (JSON)
- `data/services_status_history.jsonl` — long probe history (JSONL)
- `state/audit.log.jsonl` — audit events (JSONL)

Rotate large `*.jsonl` via `logrotate` or a cron script.

---

## 9) Security & Reliability Notes

- Write config/state **atomically** (`.tmp` → rename), keep `*.bak` when applicable
- All token‑accepting endpoints check `X-CRON-TOKEN` or `token=` and return 403 otherwise
- Client debug logging guarded by `CLIENT_DEBUG_LOG`

---

## **Screenshots**

![Alt text](/assets/images/screenshots/main.png?raw=true "Server Dashboard Main Screenshot")

![Alt text](/assets/images/screenshots/history.png?raw=true "Server Dashboard History Screenshot")

![Alt text](/assets/images/screenshots/logs.png?raw=true "Server Dashboard Log Viewer Screenshot")

![Alt text](/assets/images/screenshots/services.png?raw=true "Server Dashboard Services Screenshot")

![Alt text](/assets/images/screenshots/server-tests.png?raw=true "Server Dashboard Server Tests Screenshot")

![Alt text](/assets/images/screenshots/alerts.png?raw=true "Server Dashboard Alerts Screenshot")

![Alt text](/assets/images/screenshots/config.png?raw=true "Server Dashboard Config Page Screenshot")

![Alt text](/assets/images/screenshots/backups.png?raw=true "Server Dashboard Backups Screenshot")
