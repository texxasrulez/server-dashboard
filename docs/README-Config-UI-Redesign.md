# Web Admin Dashboard — Baseline 2025-09-01

This README documents the **current, working baseline** of the admin dashboard (as provided in *Current Working.zip*).  
Use this as your known-good reference for future drop-ins and upgrades.

---

## What this baseline includes

- Modular **Config** UI (`assets/js/pages/config.*.js`) with tabbed sections (Site, Features, Mail, Integrations, Security, UI, Server Tests, Alerts, History, Logs, Email).
- **Email notifier** in header using `assets/js/header/email-indicator.loader.js` → loads the canonical `email-indicator.js`.
- **Multiple account** indicator mode supported (per-account badges in header). Configuration is saved under `email.accounts` as a JSON string.
- **Cron/History health** panel showing last run and next due times (uses the `cron_mark.php` endpoints).
- **Send Test Email** support via mailer endpoints (`api/mail_test.php`, `api/alerts_test.php`, etc.).
- Clean, dark theme with one-line field layout across most tabs.

> Note: This baseline is **right before** reconnect-button and token-checker features. Those will be reintroduced as small, separate drop‑ins.

---

## Directory layout (relevant)

```
web-admin/
├─ assets/
│  └─ js/
│     ├─ header/
│     │  ├─ email-indicator.js
│     │  └─ email-indicator.loader.js
│     └─ pages/
│        ├─ config.boot.js
│        ├─ config.js
│        ├─ config.site.js
│        ├─ config.email.js
│        └─ … (other config.*.js modules)
├─ api/
│  ├─ email_status.php
│  ├─ cron_mark.php
│  ├─ mail_test.php
│  └─ alerts_test.php
├─ config.php
├─ includes/
└─ data/
   └─ local.json  (persisted settings)
```

---

## Key behaviors & assumptions

### Config data model
- Settings persist to `data/local.json` keyed by top-level sections (`site`, `security`, `email`, `alerts`, etc.).
- **Email accounts** persist under `email.accounts` as a **JSON stringified array** of objects, e.g.
  ```json
  "[{"address":"user@example.com","password":"app-pass","provider":"imap","poll_seconds":300}]"
  ```
  This format is consumed by `api/email_status.php` and the header notifier.

### Email notifier
- Header badge is mounted by `assets/js/header/email-indicator.loader.js`.  
- Unread counts are fetched from `api/email_status.php` which reads `email.accounts` and stored OAuth tokens/IMAP creds.
- For Google accounts, OAuth tokens must exist under `data/oauth/` in the format used by your existing backend.

### Cron / History
- Health UI is derived from the data in `local.json` and the `cron_mark.php` endpoints:
  - Alerts mark: `api/cron_mark.php?what=alerts&token=…`
  - History mark: `api/cron_mark.php?what=history&token=…`
- Run those via crontab. The UI interprets the last mark and expected interval to show OK/FAIL.

### Mail testing
- The **Send Test** control hits `api/mail_test.php` first, then falls back to `api/alerts_test.php`.
- Transports supported by your backend: `phpmail`, `smtp`, `sendmail` (requires `popen()` enabled).

---

## Install / Deploy

1. Copy the contents of the baseline zip into your `` root, keeping paths intact.
2. Clear cache / hard refresh the browser after replacing assets.
3. Open **Config → Email**, verify accounts are present and enabled, then **Save** once to ensure `email.accounts` is written canonically.

---

## Recovery playbook (UI seems blank)

1. **Open Console** (F12) and check for the first red error and which JS file it points to.
2. Verify `assets/js/pages/config.*.js` files are the baseline versions (dates/sizes match the baseline zip).
3. Ensure `config.php` injects the globals before scripts:
   - `__CONFIG_SCHEMA__`, `__CONFIG_DATA__`, `__CONFIG_CSRF__`
4. Confirm the page is not serving a stale cached file (disable caching in DevTools → Network and reload).

If still stuck, temporarily load a minimal loader that logs any parse errors before loading the core (I can provide one on demand).

---

## Safe-to-remove strays (if present)

Remove any earlier experimental files to avoid double-init and conflicts:

- `assets/js/pages/config.page.js` (only if it’s **not** this baseline’s modular version)
- `assets/js/pages/config.page.v19.js` (old loader test)
- `assets/js/email-indicator.js` (root-level; keep the **header/** versions)
- `api/email_token_info.php` (only used by future token-check feature)

---

## Changelog (admin-only)

- **2025‑09‑01** — Baseline pinned from “Current Working.zip” (pre‑Reconnect).

---

## License / Attribution

Internal admin utility. Keep this header in derivative builds.
