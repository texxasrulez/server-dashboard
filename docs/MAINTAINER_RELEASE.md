# Maintainer Notes

This document is the release-facing map of the current `server-dashboard` codebase. It is intended for maintainers working in the laptop workspace checkout, not for target-host deployment automation.

## Architecture Boundaries

- Root-level PHP pages are the public web entrypoints and deployed URLs.
- `api/` contains JSON endpoints and export/report actions.
- `includes/` contains bootstrap, auth, logging, header/footer chrome, and other shared page glue.
- `lib/` contains application logic, refactored helpers, and domain code.
- `assets/` contains shipped CSS, JS, images, and support scripts.
- `bin/` contains CLI-oriented validation, admin, and maintenance helpers.
- `config/` contains defaults, schema, and local override storage.
- `data/` and `state/` are runtime writable areas and must be treated as host data, not source-controlled application code.

## Runtime Writable Paths

These paths are intentionally runtime-managed and should be writable where the related feature is enabled:

- `config/local.json`
- `config/backups/`
- `data/`
- `data/history/`
- `data/heartbeats/`
- `data/favicons/`
- `data/oauth/`
- `data/speedtest/`
- `state/`
- `state/auth/`
- `state/cache/`
- `state/generated/`
- `state/heartbeats/`
- `state/history/`
- `state/logs/`
- `state/logs_mirror/`
- `state/ratelimit/`
- `state/reports/`
- `state/speedtest/`
- `state/support_bundles/`

Writable-path assumptions should remain visible in docs and diagnostics. Do not hide host permission issues in code.

## Compatibility Shims And Legacy Behavior

- Root pages remain the stable deployed routes. No framework router is required.
- `\App\Config` keeps the public config API stable while delegating internal work to focused helpers under `lib/Config/`.
- `includes/auth.php` keeps the existing global auth helper names stable while delegating to `lib/Auth/`.
- NVMe public entry classes remain `\App\NvmeCollector` and `\App\NvmeHealth`, with parsing/history/insight internals split under `lib/Nvme/`.
- `data/security_config.json` is still synced as a legacy compatibility artifact for existing workflows.
- Legacy feature keys such as `enable_server_tests`, `enable_bookmarks`, and `enable_diagnostics` are still normalized on read.

## Security-Sensitive Paths To Recheck Before Release

- `includes/auth.php`, `lib/Auth/`, and auth-gated pages:
  Verify admin-only pages still require login/admin as intended.
- CSRF-protected POST handlers:
  Confirm mutating routes still reject missing or invalid CSRF tokens.
- Cron/token flows:
  Verify token readers still honor config, env, and token files without broadening acceptance.
- Privileged logs:
  Confirm the UI still only references allowlisted keys and the sudo bridge remains the only privileged execution path.
- Config mutation:
  Validate schema constraints, hidden-field preservation, and legacy security sync behavior.

## Test And Lint Workflow

Minimum release verification:

```bash
bash bin/php-lint.sh
npm run format:check:baseline
vendor/bin/phpunit --configuration phpunit.xml.dist
php bin/smoke-admin.php
bash bin/browser-smoke.sh
```

Notes:

- `phpunit` covers config mutation, smoke checks, NVMe parsing/normalization, path helpers, uptime reporting, and selected page behavior.
- `smoke-admin` is the fast endpoint/page boot check.
- `browser-smoke` is the highest-signal regression check for page wiring and JS behavior, but may skip in restricted environments.

## Release Checklist

1. Run the full verification set above.
2. Review any changes touching `includes/init.php`, `includes/auth.php`, `lib/Auth/`, `lib/Config/`, `lib/Nvme/`, and log-bridge code with extra care.
3. Confirm docs still match the real commands and repo layout.
4. Confirm no local-only scaffolding or partial-refactor files are left behind.
5. If deployment is manual, upload all files required by the current runtime path together rather than assuming partial rollback safety.
6. After deployment, spot-check:
   `config.php`, `history.php`, `logs.php`, `drive_health.php`, `diag.php`
7. Review server logs for PHP fatals or missing-file errors immediately after deploy.

## Remaining Debt

- Several large root pages still contain mixed controller/rendering logic and would need a safer extraction plan than the reverted Stage 3 route shim approach.
- Some docs in `docs/` remain historical or draft material and are not canonical maintainer references.
- The maintained formatting baseline is intentionally narrower than the whole repo, so historical files outside the baseline may still be inconsistent.
