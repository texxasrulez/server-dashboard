# Testing And Smoke Checks

This project now has a small but real verification floor. It is designed to catch obvious breakage in core helpers, key admin pages, and critical endpoints without introducing a heavy test framework.

## Test Layers

- `bash bin/php-lint.sh`
  Lints the PHP tree and fails on syntax errors.
- `vendor/bin/phpunit --configuration phpunit.xml.dist`
  Runs the maintained unit and smoke-oriented PHPUnit suite.
- `php bin/smoke-admin.php`
  Executes a lightweight CLI smoke pass against key admin endpoints and pages.
- `bash bin/browser-smoke.sh`
  Runs a browser-level smoke pass for selected admin pages through a local `php -S` server.
- `npm run format:check:baseline`
  Enforces formatting only on the maintained file baseline introduced in the hardening pass.

## Recommended Local Sequence

Run these after application changes:

```bash
bash bin/php-lint.sh
vendor/bin/phpunit --configuration phpunit.xml.dist
php bin/smoke-admin.php
npm run format:check:baseline
```

Run browser smoke when you changed page rendering, JS behavior, modals, or page-specific controls:

```bash
bash bin/browser-smoke.sh
```

## `php bin/smoke-admin.php`

This command boots selected endpoints in a controlled local worker process. It does not need a web server.

Current checks:

- `api/health.php`
- `api/cron_token_admin.php?action=status`
- `api/report_uptime.php?month=YYYY-MM`
- `diag.php`
- `tools/assets_audit.php`
- `tools/admin_audit.php`

Typical successful output:

```text
[OK] api/health.php
[OK] api/cron_token_admin.php?action=status
[OK] api/report_uptime.php?month=2026-04
[OK] diag.php
[OK] tools/assets_audit.php
[OK] tools/admin_audit.php
```

Failure behavior:

- Any `[FAIL]` line causes exit code `1`.
- Invalid JSON from an expected JSON endpoint aborts immediately with a descriptive error.
- This script validates application behavior, not visual styling.

## `bash bin/browser-smoke.sh`

This command starts a temporary local PHP server and drives a headless browser against real rendered pages.

Current checks:

- `diag.php` renders the doctor UI and admin shortcuts
- `config.php` renders the Security tab and the cron token controls, including `Rotate Token`
- `history.php` opens the HTML uptime report in a modal
- `speedtest.php` changes rendered row count when `Rows per page` changes

Typical successful output:

```text
browser smoke passed: diag
browser smoke passed: config
browser smoke passed: history
browser smoke passed: speedtest
```

Expected skip conditions:

- `browser smoke skipped: no Chrome/Chromium binary found`
- `browser smoke skipped: local TCP listener unavailable in this environment`

Those skip cases return success because they reflect host-environment limits, not app failure. This is intentional for restricted CI and sandboxed environments.

Failure behavior:

- `browser smoke failed: <check>` means the page loaded but the expected rendered behavior was not observed.
- The dumped HTML in the failure output is the primary debugging artifact.

## PHPUnit Scope

The PHPUnit suite is intentionally small. It currently covers:

- configuration bootstrap and validation assumptions
- config mutation and compatibility sync
- atomic write helpers
- cron token maintenance helpers
- uptime report interval math
- page and API smoke checks for maintained endpoints

It is not a full integration or browser suite.

## CI Behavior

GitHub Actions runs:

- `composer install --no-interaction --prefer-dist`
- `npm ci`
- PHP lint
- maintained-file format baseline
- PHPUnit
- browser smoke

If browser smoke is skipped because the runtime lacks the required browser or local listener capability, the job still passes. If the browser is available and a check fails, CI fails.

## Interpreting Results

- `php-lint` failing usually means a broken deploy candidate. Fix this first.
- `phpunit` failing usually means helper logic, config behavior, or a smoke assertion regressed.
- `smoke-admin` failing usually means a key endpoint or admin tool is not booting correctly.
- `browser-smoke` failing usually means page wiring, selectors, modals, or JS behavior regressed.
- `format:check:baseline` failing means a maintained file changed without being normalized to the repo baseline.

## Operational Notes

- Browser smoke is the only layer that checks real page interactions.
- `smoke-admin` is safe to run on a development checkout because it uses local worker sessions and synthetic request context.
- These checks are a floor. They do not replace manual verification for auth boundaries, deployment permissions, or environment-specific integrations such as SMTP and external probes.
