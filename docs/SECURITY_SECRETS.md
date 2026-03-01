# Secret Hygiene and Rotation

This project stores runtime config in `config/local.json`. Treat it as sensitive.

## 1) Rotate app-managed secrets

Dry run:

```bash
php bin/rotate-runtime-secrets.php
```

Apply:

```bash
php bin/rotate-runtime-secrets.php --apply
```

What this rotates:

- `security.api_tokens`
- `security.csrf_secret`
- `alerts.cron_token` (+ legacy cron token paths when present)
- `data/cron_token.txt` (and `state/cron_token.txt` if it exists)

The script writes a timestamped backup to `config/backups/`.

## 2) Rotate external credentials manually

The app cannot safely auto-rotate external provider credentials. Rotate these in their providers first, then update `config/local.json`:

- SMTP credentials
- Database credentials
- OAuth client secrets
- Mail account passwords

## 3) Redact config before sharing

Create a sanitized copy:

```bash
php bin/redact-local-config.php
```

Output file: `config/local.redacted.json`

## 4) File permissions

Recommended:

```bash
chmod 640 config/local.json data/cron_token.txt data/users.json
chmod 640 data/oauth/*.json 2>/dev/null || true
```

## 5) VCS safety

Sensitive local/runtime files are ignored in `.gitignore`:

- `config/local.json`
- `data/security_config.json`
- `data/users.json`
- `data/cron_token.txt`
- `state/cron_token.txt`
- `data/oauth/*.json`

## 6) Backup pruning

`scripts/backup_health_check.sh` now invokes `scripts/prune_config_backups.sh` automatically.

Retention behavior:

- `config/backups/services_status_history_YYYY-MM-DD.jsonl`: keep 30 days (by filename date)
- `config/backups/config-*.json`: keep latest `site.backup_keep` count (default 20)
- `config/backups/security-*.json`: keep latest `site.backup_keep` count (default 20)
- `config/backups/local.pre-rotate-*.json`: keep 30 days

Optional env overrides:

- `BACKUP_HISTORY_KEEP_DAYS`
- `BACKUP_CONFIG_KEEP_COUNT`
- `BACKUP_ROTATE_KEEP_DAYS`
