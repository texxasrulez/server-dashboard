# Server Dashboard

Server Dashboard is a lightweight PHP admin console for operators who want one place to watch server health, services, probes, alerts, logs, cron health, and related maintenance tasks without introducing a framework-heavy stack.

It is aimed at small VPS, homelab, agency, and single-host production environments where a pragmatic dashboard is more useful than a large observability platform. The project already has broad surface area; the current focus is making that surface safer to operate and easier to maintain.

## What It Includes

- Service and process dashboards
- History and probe exports
- Alerts administration and alert event history
- Config UI backed by `config/local.json`
- Cron health helpers and headless endpoints
- User management and session auth
- Log viewer, bookmarks, diagnostics, and server tests
- Adapter-based environment detection for generic Linux and Hestia-style installs

## Production-Readiness Baseline

Recent foundation work adds:

- An actionable admin doctor at [`diag.php`](./diag.php)
- Cron token management with reveal authorization, rotation, and audit logging
- Monthly uptime/SLA reporting with JSON, CSV, and HTML export
- A minimal PHP lint + PHPUnit baseline
- A GitHub Actions CI workflow for lint, formatting, and test smoke checks
- Admin-facing audit and asset audit tools in the diagnostics area
- A left-sidebar navigation layout option without adding more header tabs
- Live privileged log reads inside the existing Logs page
- Correlated incidents, incident timelines, and per-service drill-down pages
- Backup restore verification plus redacted troubleshooting bundle export
- Expanded browser smoke coverage for core operator flows

## Project Layout

```text
api/         JSON endpoints and export/report actions
assets/      CSS, JS, images, install scripts
Adapters/    Environment adapter detection
bin/         Admin and maintenance CLI tools
config/      Defaults, schema, local overrides, backups
data/        Writable JSON state and secrets-compatible data files
docs/        Operator notes, migration notes, and hardening docs
includes/    Bootstrap, auth, logging, mailer, page chrome
lib/         Shared application logic
state/       Runtime state, caches, generated artifacts
tests/       PHPUnit unit and smoke tests
```

## Install / Update

1. Place the project under your web root or subdirectory.
2. Create the first admin from CLI:
   `php bin/bootstrap-admin.php`
3. Sign in and immediately rotate the temporary password in [`users.php`](./users.php).
4. Verify the PHP user can write to:
   - `data/`
   - `state/`
   - `config/` when using config backups/UI saves
5. Generate script environment helpers if you plan to run bundled cron/systemd helpers:
   `php bin/install-scripts.php`

For updates, replace the code, keep your writable `data/`, `state/`, and `config/local.json`, then rerun the validation steps in the Testing section below.

## Configuration Model

The project keeps the current compatibility model intact:

- Primary editable config: `config/local.json`
- Schema/defaults: `config/schema.php` and `config/defaults.php`
- Compatibility sync: `data/security_config.json`
- Runtime fallbacks: environment variables and token files

Important configuration paths:

- Cron token: `alerts.cron_token`, `DASH_CRON_TOKEN`, `data/cron_token.txt`
- Mail transport: `mail.*`
- Security controls: `security.*`
- History and cron expectations: `history.*`, `alerts.*`, `cron.*`

Admin configuration is done primarily through [`config.php`](./config.php). CLI/headless changes can use [`bin/config-cli.php`](./bin/config-cli.php).

## Cron / Headless Usage

The cron helper flow no longer adds tokens to generated cron jobs. Use plain `curl` lines for the built-in cron endpoints:

```bash
curl -fsS "https://example.test/api/cron_mark.php?what=alerts"
curl -fsS "https://example.test/api/alerts_eval.php?probe=1"
curl -fsS "https://example.test/api/cron_heartbeat.php?id=custom_job"
```

Some other automation endpoints may still support header-based auth for compatibility, but the cron jobs generated from `cron.php` no longer require cron tokens.

If you use the bundled scripts:

- Generate script env:
  `php bin/install-scripts.php`
- Review:
  [`scripts/lib/dashboard_env.sh`](./scripts/lib/dashboard_env.sh)
- Generated env file:
  [`state/generated/dashboard-scripts.env`](./state/generated/dashboard-scripts.env)

### NVMe Collector

Install `smartmontools` on the host so `smartctl` is available, then run:

```bash
php scripts/nvme_collect.php
```

The collector reads `/dev/nvme0n1` and `/dev/nvme1n1`, writes the latest snapshot to [`state/nvme_status.json`](./state/nvme_status.json), and appends one JSON object per run to [`data/nvme_history.jsonl`](./data/nvme_history.jsonl).

The admin UI now includes a top-level [`drive_health.php`](./drive_health.php) page backed by [`api/drive_health_status.php`](./api/drive_health_status.php) for the latest snapshot, [`api/drive_health_history.php`](./api/drive_health_history.php) for filtered JSONL history and chart data, and [`api/drive_health_export.php`](./api/drive_health_export.php) for JSON/CSV export.

## Logs: Copied And Live Privileged

The existing Logs page still keeps the copied-log mirror under [`state/logs_mirror`](./state/logs_mirror). That workflow is unchanged and remains the default view.

The same page now also includes an in-page switcher for `Copied Logs` and `Live Privileged Logs`. No new header tab was added. The live mode is for admin users only and reads selected protected logs on demand without making `/var/log` world-readable and without running PHP or the web server as root.

For the copied-log workflow, the separate log-watcher mirror should run as `root` and then write mirrored files back with the configured destination owner. That preserves the quick copied-log view for current root-owned service and vhost logs without changing permissions on the source files.

### Security Model

- Browser input is limited to an allowlisted logical key such as `syslog` or `nginx_error`.
- [`logs.php`](./logs.php) validates the admin session, applies CSRF checks, clamps line counts, and calls one exact sudo target.
- [`scripts/log_bridge.sh`](./scripts/log_bridge.sh) is the only root-executed entrypoint. It accepts only `--key`, `--mode`, `--lines`, and `--search`.
- The bridge resolves keys from [`config/privileged_logs.json`](./config/privileged_logs.json), allows only configured file or journal sources, treats search as a literal string, and never accepts raw paths or arbitrary commands from the web app.
- Sudo is scoped to the exact bridge script through a sudoers drop-in. There is no general shell execution path from the dashboard.

### Allowed Sources

The default allowlist currently includes:

- `exim_main`
- `exim_reject`
- `nginx_error`
- `nginx_access`
- `syslog`
- `auth`
- `fail2ban`

Each entry defines its label, internal key, source, whether literal search is allowed, and default/max line counts in [`config/privileged_logs.json`](./config/privileged_logs.json).

### Sudoers Setup

The live privileged-log mode only works after an admin explicitly allows the web user to run the exact bridge script through `sudo`.

Do not place the helper script itself in `/etc/sudoers.d/`. The helper only prints the sudoers rule. The file in `/etc/sudoers.d/` must contain sudoers syntax, not shell code.

1. Confirm the actual PHP/web user on the host.
   On Hestia-style single-user installs this may be your account name, for example `gene:gene`.
   On more typical setups it may be `www-data`, `apache`, or similar.

2. Generate an example drop-in for that exact web user:

```bash
bash scripts/render_log_bridge_sudoers.sh www-data
```

3. Copy the printed rule into `/etc/sudoers.d/server-dashboard-log-bridge`.

Example content:

```sudoers
www-data ALL=(root) NOPASSWD: /path/to/server-dashboard/scripts/log_bridge.sh
```

4. Set the correct permissions and validate it before enabling it:

```bash
chmod +x scripts/log_bridge.sh
sudo chmod 440 /etc/sudoers.d/server-dashboard-log-bridge
sudo visudo -cf /etc/sudoers.d/server-dashboard-log-bridge
```

5. Test the bridge directly from shell before trying the UI:

```bash
sudo -n /path/to/server-dashboard/scripts/log_bridge.sh --key syslog --mode tail --lines 50
sudo -n /path/to/server-dashboard/scripts/log_bridge.sh --key auth --mode tail --lines 50
sudo -n /path/to/server-dashboard/scripts/log_bridge.sh --key syslog --mode tail --lines 50 --search "CRON"
```

6. Open the existing Logs page, switch to `Live Privileged Logs`, pick an allowlisted source, and refresh.

The expected rule should allow only the exact bridge script path. It should not allow arbitrary shell commands, arbitrary arguments, or a directory wildcard. Example:

```sudoers
www-data ALL=(root) NOPASSWD: /path/to/server-dashboard/scripts/log_bridge.sh
```

For a Hestia-style install where the PHP user is `gene`, the practical commands still follow the same pattern:

```bash
bash /path/to/server-dashboard/scripts/render_log_bridge_sudoers.sh gene

sudo tee /etc/sudoers.d/server-dashboard-log-bridge >/dev/null <<'EOF'
gene ALL=(root) NOPASSWD: /path/to/server-dashboard/scripts/log_bridge.sh
EOF

sudo chmod 440 /etc/sudoers.d/server-dashboard-log-bridge
sudo visudo -cf /etc/sudoers.d/server-dashboard-log-bridge
chmod +x /path/to/server-dashboard/scripts/log_bridge.sh
sudo -n /path/to/server-dashboard/scripts/log_bridge.sh --key syslog --mode tail --lines 50
```

If the direct `sudo -n ...log_bridge.sh ...` command fails, fix that first. The web UI depends on the same exact bridge invocation.

### Extending The Allowlist

To add another protected log:

1. Add a new entry to [`config/privileged_logs.json`](./config/privileged_logs.json).
2. Use a new logical `key`; do not expose raw file paths in the browser.
3. Set `source.type` to `file` with an absolute path, or `journal` with a fixed unit name.
4. Keep `max_lines` conservative and only enable `allow_search` when it is operationally useful.

### Troubleshooting

- If live reads fail with a sudo error, the sudoers drop-in is missing, invalid, or uses the wrong web user.
- If the shell says `syntax error near unexpected token '('`, you pasted a sudoers rule into `bash` instead of saving it into `/etc/sudoers.d/...`.
- If `visudo -cf` fails with `input in flex scanner failed`, the sudoers file likely contains the helper shell script or stray markdown/prompt text instead of a single sudoers rule.
- If a log key is denied, verify the key exists in [`config/privileged_logs.json`](./config/privileged_logs.json) and matches the UI selection.
- If a source is missing, confirm the underlying host actually has that log file or journal unit.
- If copied logs work but live logs do not, that usually means the new bridge path is not executable or not allowed in sudoers.
- If the UI reports `This privileged log request was denied.`, test the exact same bridge command directly with `sudo -n ...scripts/log_bridge.sh ...`. If that direct call fails, the problem is in the bridge or host log source, not the page wiring.
- If the bridge rejects every request with `Denied: invalid search literal.`, make sure you are using the current repo version of [`scripts/log_bridge.sh`](./scripts/log_bridge.sh). Older copies had a bad null-byte check that falsely rejected empty searches.

## Security Notes

- Keep `security.allow_web_bootstrap_admin=false` after the first admin exists.
- Treat `config/local.json`, `data/cron_token.txt`, and generated env files as sensitive.
- Cron helper jobs shown in [`cron.php`](./cron.php) no longer embed a cron token in their URLs.
- Rotation events for legacy cron-token compatibility are logged to [`data/logs/security.log`](./data/logs/security.log).
- If the app sits behind a reverse proxy, set `security.trusted_proxies`.
- Persisted audit metadata, support bundles, and exported config payloads now redact sensitive values by default through [`lib/Redaction.php`](./lib/Redaction.php).

## Incident And Service Drill-Down

The dashboard now correlates related alert floods into incidents instead of forcing operators to interpret each alert in isolation.

- Correlation rules live in [`config/incident_rules.json`](./config/incident_rules.json).
- The existing History page now includes a `Recent Incidents` section.
- Incident detail lives at [`incident.php`](./incident.php) and merges alerts, service state changes, audit events, backup actions, and speedtest anomalies into one timeline.
- Services now link to [`service_detail.php`](./service_detail.php), which aggregates current status, recent failures, restart/recovery history, related incidents, relevant logs, and recent admin actions.

This is an in-place operational drill-down. No new top-level nav item was added.

## Audit Logging

The structured admin audit stream now lives in:

- [`state/logs/admin_audit.log`](./state/logs/admin_audit.log)

It complements the existing security and diagnostics logs and records:

- config changes
- config export/import actions
- privileged log reads
- service actions
- backup actions
- restore verification runs
- support bundle generation

The admin-facing view remains [`tools/admin_audit.php`](./tools/admin_audit.php).

## Restore Verification And Support Bundles

The existing Backups page now contains a compact `Restore Verification & Support` section.

- `Run Verification` performs conservative artifact checks through [`api/backup_verify.php`](./api/backup_verify.php) and stores results in [`state/backup_restore_verification.json`](./state/backup_restore_verification.json).
- `Build Support Bundle` creates a redacted ZIP through [`api/support_bundle.php`](./api/support_bundle.php) and stores it under [`state/support_bundles`](./state/support_bundles).

Current verification is intentionally conservative: it reports `integrity-verified`, not a destructive restore test.

## Documentation

Additional design notes:

- [`docs/INCIDENT_CORRELATION.md`](./docs/INCIDENT_CORRELATION.md)
- [`docs/REDACTION_MODEL.md`](./docs/REDACTION_MODEL.md)
- [`docs/AUDIT_LOGGING.md`](./docs/AUDIT_LOGGING.md)
- [`docs/RESTORE_VERIFICATION.md`](./docs/RESTORE_VERIFICATION.md)
- [`docs/TROUBLESHOOTING_BUNDLE.md`](./docs/TROUBLESHOOTING_BUNDLE.md)

More hardening guidance:

- [`docs/SECURITY_SECRETS.md`](./docs/SECURITY_SECRETS.md)
- [`docs/WEBSERVER_HARDENING.md`](./docs/WEBSERVER_HARDENING.md)

## Diagnostics And Reporting

- Environment doctor:
  [`diag.php`](./diag.php)
- Basic health endpoint:
  [`api/health.php`](./api/health.php)
- Assets audit:
  [`tools/assets_audit.php`](./tools/assets_audit.php)
- Admin audit:
  [`tools/admin_audit.php`](./tools/admin_audit.php)
- Cron token admin API:
  [`api/cron_token_admin.php`](./api/cron_token_admin.php)
- Monthly uptime summary export:
  [`api/report_uptime.php`](./api/report_uptime.php)

The uptime report uses stored probe history and currently focuses on:

- time-weighted up/down intervals
- per-service uptime percentage with coverage percentage
- average/max latency
- CSV/HTML export for handoff or archive

The diagnostics page groups checks into `PASS`, `WARN`, and `FAIL` so environment problems are actionable instead of just raw dumps. Admin shortcuts open the health endpoint and audit tools in modals.

Top-level feature pages are controlled from `Config -> Features`. Unchecking a feature removes its header tab and returns a disabled response for direct page requests until it is re-enabled.

## Testing

Full reference:

- [`docs/TESTING_AND_SMOKE.md`](./docs/TESTING_AND_SMOKE.md)

Recommended local verification sequence:

```bash
bash bin/php-lint.sh
vendor/bin/phpunit --configuration phpunit.xml.dist
php bin/smoke-admin.php
npm run format:check:baseline
```

Add browser smoke after JS or page-behavior changes:

```bash
bash bin/browser-smoke.sh
```

Current browser smoke covers:

- dashboard load
- config UI load
- history load
- logs page mode switching
- incident list/detail flow
- service detail drill-down
- backups verification/support panel render
- speedtest page render

PHP lint:

```bash
bash bin/php-lint.sh
```

Install test dependencies:

```bash
composer install --no-interaction --prefer-dist
```

Run PHPUnit:

```bash
vendor/bin/phpunit --configuration phpunit.xml.dist
```

Run the lightweight admin smoke probe:

```bash
php bin/smoke-admin.php
```

Expected result:

- one `[OK]` line for each maintained endpoint/page check
- exit code `0` on success
- any `[FAIL]` line or invalid JSON causes a non-zero exit

Run the browser smoke checks for the key admin pages:

```bash
bash bin/browser-smoke.sh
```

Expected result:

- `browser smoke passed: diag`
- `browser smoke passed: config`
- `browser smoke passed: history`
- `browser smoke passed: speedtest`

Expected skip result in restricted environments:

- `browser smoke skipped: no Chrome/Chromium binary found`
- `browser smoke skipped: local TCP listener unavailable in this environment`

Those skip cases are environment limitations, not app failures.

Format check:

```bash
npm ci
npm run format:check:baseline
```

The PHPUnit baseline is intentionally small. It covers:

- shared helper behavior
- config bootstrap assumptions
- config mutation and compatibility sync
- uptime report interval math
- smoke checks for critical JSON endpoints
- page rendering for maintained admin tools

The browser smoke script loads the real pages through a local `php -S` server and verifies:

- `diag.php` renders the doctor UI
- `config.php` renders the Security tab token controls
- `history.php` opens the HTML report modal
- `speedtest.php` changes visible rows when the page-size selector changes

There is also an admin-facing audit viewer at [`tools/admin_audit.php`](./tools/admin_audit.php) for recent security and diagnostic actions already written to:

- [`data/logs/security.log`](./data/logs/security.log)
- [`state/logs/diag_audit.log`](./state/logs/diag_audit.log)

Security log entries are created by token-management actions such as reveal authorization and token rotation. Diagnostic log entries are created by server-test actions and related manual diagnostics.

There is still a legacy full-repo formatting backlog. CI enforces a maintained-file formatting baseline introduced in this hardening pass instead of gating on untouched historical files.

## CI

The repository now includes [`.github/workflows/ci.yml`](./.github/workflows/ci.yml) with:

- `composer install --no-interaction --prefer-dist`
- `npm ci`
- PHP lint
- maintained-file Prettier baseline
- PHPUnit smoke/unit tests
- browser smoke checks for key admin pages

This is a floor, not a full release pipeline.

## Screenshots

Existing screenshots live under:

- [`assets/images/screenshots/main.png`](./assets/images/screenshots/main.png)
- [`assets/images/screenshots/config.png`](./assets/images/screenshots/config.png)
- [`assets/images/screenshots/history.png`](./assets/images/screenshots/history.png)
- [`assets/images/screenshots/services.png`](./assets/images/screenshots/services.png)
- [`assets/images/screenshots/logs.png`](./assets/images/screenshots/logs.png)

## Roadmap Summary

Near-term work that still makes sense:

- broader token and secret inventory management
- more probe/alert reporting views
- additional PHPUnit coverage around config mutation and auth flows
- smoke probes for more admin APIs
- stronger release/update docs
- follow-up hardening on writable state and backup flows
