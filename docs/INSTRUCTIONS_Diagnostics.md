# Diagnostics Page

URL: `/diag.php` (admin only)

The diagnostics page is now an environment doctor rather than a raw debug dump.

## What It Checks

- PHP version and required extensions
- writable directories and files
- config presence and parse validity
- generated script env presence
- cron token presence
- mail transport sanity
- adapter detection result
- important state/data path presence and permissions
- dangerous or degraded conditions that should be surfaced to the operator

## Result Groups

- `PASS`
  The check is healthy.
- `WARN`
  The dashboard can probably still run, but something is missing, degraded, or risky.
- `FAIL`
  A core requirement is missing or broken and should be fixed before relying on the dashboard.

The point is to give an operator an actionable summary instead of forcing them to inspect raw arrays or endpoint output.

## Admin Shortcuts

The page includes modal-opened shortcuts for:

- `api/health.php`
- Assets Audit
- Admin Audit

These are loaded through the diagnostics modal system so you can inspect them without leaving the page.

## Related Pages

- `server_tests.php`
  Manual server probes and diagnostics actions
- `tools/assets_audit.php`
  Asset inventory, missing references, and orphan audit
- `tools/admin_audit.php`
  Security and diagnostics event log viewer

## When To Use It

- after install or update
- when probes, logs, or cron-driven actions stop updating
- when a writable-path or permissions problem is suspected
- before reporting a bug so you can confirm the local runtime is sane
