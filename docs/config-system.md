# Central Configuration System

This adds a single source of truth for **all** editable settings, with:

- **config/defaults.php** — version-controlled defaults
- **config/local.json** — machine-specific overrides (auto-created)
- **config/schema.php** — typed schema for validation + UI generation
- **lib/Config.php** — loader, validation, env var overrides, atomic save + backups
- **server-dashboard/config.php** + **assets/js/pages/config.page.js** — admin UI
- **bin/config-cli.php** — headless get/set (`php bin/config-cli.php get site.name`)

## Usage

```php
\App\Config::init(__DIR__);
$cfg = \App\Config::all();
$host = \App\Config::get('integrations.mysql.host');
```

## ENV Overrides

Any `APP__` env var overrides config at runtime. Example:

```
APP__INTEGRATIONS__MYSQL__HOST=10.0.0.12
APP__FEATURES__ENABLE_DIAGNOSTICS=false
```

## Backups

Use the Config → Site → “Create backup” action (or `api/config_backup.php`) to snapshot `config/local.json` into `config/backups/config-YYYYmmdd-HHMMSS.json`. Retention + download/prune all operate on that directory.

## Security

- CSRF protection on POST.
- The UI never echoes secrets back in plaintext once saved (you can improve the UX with "reveal" toggles).
- Limit access to `server-dashboard/config.php` to admins only (reuse your existing auth gate).
- Saves automatically sync `mail.*`, `alerts.cron_token`, and the Security tab into `data/security_config.json` so legacy `api/security_*` integrations stay updated.

## CLI helper

Use `bin/config-cli.php` for quick automation:

```
php bin/config-cli.php get site.name
php bin/config-cli.php set mail.smtp_host smtp.example.com
php bin/config-cli.php set-json mail.sec_email '["ops@example.com","oncall@example.com"]'
php bin/config-cli.php unset mail.smtp_host
php bin/config-cli.php dump
```

## Position of Toasts

The config page uses your unified toast system. Position is bottom-center per your global setup.
