# Services Page (CRUD + Probe) — Instructions
**Updated:** 2025-08-11

## Files (typical)
- `services.php` (UI)
- `assets/js/pages/services.js` (binder, validation, CRUD)
- APIs: `/api/services_*` (list, upsert, toggle, delete, import/export), `/api/services_probe_all.php`

## Behaviors
- Modal add/edit with validation (name, host, port).
- Toggle enabled/disabled persists to store and reflects on Index after refresh.
- "Test on Save" optional probe.
- Import/Export JSON/CSV.

## Tuneables
- Validation rules: see `services.js` (`validate()`).
- Default types/checks: modal `<select>` options.
- Auto-probe frequency and write path: server-side in `services_probe_all.php`.
