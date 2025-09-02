# Central Configuration System

This adds a single source of truth for **all** editable settings, with:
- **config/defaults.php** — version-controlled defaults
- **config/local.json** — machine-specific overrides (auto-created)
- **config/schema.php** — typed schema for validation + UI generation
- **lib/Config.php** — loader, validation, env var overrides, atomic save + backups
- **web-admin/config.php** + **assets/js/pages/config.js** — admin UI
- **bin/config-cli.php** — headless get/set

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

Every save writes `config/local.json` and also a timestamped copy to `config/backups/local-YYYYmmdd-HHMMSS.json`.

## Security

- CSRF protection on POST.
- The UI never echoes secrets back in plaintext once saved (you can improve the UX with "reveal" toggles).
- Limit access to `config.php` to admins only (reuse your existing auth gate).

## Position of Toasts

The config page uses your unified toast system. Position is bottom-center per your global setup.
