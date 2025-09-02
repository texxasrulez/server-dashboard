# Mailer — Unified Email Sending

This dashboard uses a single mailer for **alerts**, **tests**, and future notifications.

## Transports
Set one of:
- `MAIL_TRANSPORT=phpmail` (default; uses PHP `mail()`)
- `MAIL_TRANSPORT=sendmail` (uses `/usr/sbin/sendmail -t -i` by default; override `SENDMAIL_PATH`)
- `MAIL_TRANSPORT=smtp` (direct SMTP with optional STARTTLS/SSL)

### Common settings
- `MAIL_FROM` — e.g. `Alerts <alerts@yourdomain>` (fallback: `no-reply@<host>`)
- `MAIL_REPLYTO` — optional Reply-To

### SMTP settings (when `MAIL_TRANSPORT=smtp`)
- `SMTP_HOST` — server hostname
- `SMTP_PORT` — port (defaults: 465 for `ssl`, otherwise 587)
- `SMTP_SECURE` — `tls` (default), `ssl`, or `none`
- `SMTP_USER`, `SMTP_PASS` — optional auth
- `SMTP_TIMEOUT` — seconds (default 12)

Settings can be defined as **constants** in `config.php` or as **environment variables**.
Both plain keys and `DASH_`-prefixed variants are read, e.g. `MAIL_TRANSPORT` or `DASH_MAIL_TRANSPORT`.

## Testing
- As admin (cookie session) **or** by token:
  ```bash
  curl -fsS 'https://<host>api/mail_test.php?to=you@example.com&token=YOUR_CRON_TOKEN' | jq .
  ```
- Response includes `{ ok: true|false, transport, result }` and sends are logged to `state/mail.log.jsonl`.

## Alerts
`api/alerts_eval.php` now uses the unified Mailer:
- Subject: `[Alert] <rule name>`
- Plain text body with key fields and appended JSON dump
- On failure, appends a line to `state/mail_failures.log`
