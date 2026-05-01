# Audit Logging

Structured admin audit records are written to:

- `state/logs/admin_audit.log`

Legacy source logs remain in place:

- `data/logs/security.log`
- `state/logs/diag_audit.log`
- `state/logs/privileged_log_access.log`

## Structured audit scope

The unified audit log records:

- config saves
- config export/import actions
- privileged log reads
- service create/update/delete/toggle/probe actions
- backup actions
- restore verification runs
- support bundle generation

## Record fields

Audit lines follow the existing `dashboard_log_append()` format:

- `Time`
- `Category`
- `Message`
- `User`
- `IP`
- `Context`
- `PID`

Sensitive metadata is redacted before persistence.

## Viewing audit data

- `tools/admin_audit.php`
- incident timeline detail
- service detail pages
