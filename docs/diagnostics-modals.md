# Diagnostics Modals

`diag.php` uses `assets/js/pages/diag.js` to intercept selected admin-shortcut links and open them inside an in-page modal.

## Current Modal Targets

- `api/health.php`
  Loaded as JSON and rendered in a formatted viewer.
- `tools/assets_audit.php`
  Loaded as HTML in an embedded tool view.
- `tools/admin_audit.php`
  Loaded as HTML in an embedded tool view.

## Rendering Behavior

- JSON responses are shown in a themed modal body with readable formatting.
- Tool pages open as embedded standalone pages without the main app header/footer duplicated inside the modal.
- The modal preserves the dashboard theme so the embedded content still matches the current UI.

## Why This Exists

- lets operators inspect health and audit details without losing page context
- avoids opening admin tools in separate tabs for common checks
- keeps diagnostics workflow centered on `diag.php`
