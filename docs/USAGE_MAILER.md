# Mailer — Unified Email Sending

This dashboard uses a single mailer for **alerts**, **tests**, and future notifications.

## Transports

Set one of:

- `MAIL_TRANSPORT=phpmail` (default; uses PHP `mail()`)
- `MAIL_TRANSPORT=sendmail` (uses `/usr/sbin/sendmail -t -i -f <envelope>` by default; override `SENDMAIL_PATH`)
- `MAIL_TRANSPORT=smtp` (direct SMTP with optional STARTTLS/SSL)

### Common settings

- `MAIL_FROM` — header sender, e.g. `Alerts <alerts@yourdomain>` or `alerts@yourdomain`
- `MAIL_ENVELOPE_FROM` — bounce/envelope sender used for PHP `mail() -f`, `sendmail -f`, and SMTP `MAIL FROM`
- `MAIL_REPLYTO` — optional Reply-To

### SMTP settings (when `MAIL_TRANSPORT=smtp`)

- `SMTP_HOST` — server hostname
- `SMTP_PORT` — port (defaults: 465 for `ssl`, otherwise 587)
- `SMTP_SECURE` — `tls` (default), `ssl`, or `none`
- `SMTP_USER`, `SMTP_PASS` — optional auth
- `SMTP_TIMEOUT` — seconds (default 12)

Settings can be defined as **constants** in `config.php` or as **environment variables**.
Both plain keys and `DASH_`-prefixed variants are read, e.g. `MAIL_TRANSPORT` or `DASH_MAIL_TRANSPORT`.

## Sender strategy

- `MAIL_FROM` controls the visible `From:` header.
- `MAIL_ENVELOPE_FROM` controls the transport envelope sender and should be a real mailbox on your domain.
- If `MAIL_ENVELOPE_FROM` is unset, the mailer falls back to the bare email extracted from `MAIL_FROM`.
- The project never intentionally relies on the PHP/web runtime user (`www-data`, `root`) to derive a sender. That pattern causes frozen Exim bounces and should stay disabled.

## Testing

- As admin (cookie session) **or** by token:
  ```bash
  curl -fsS 'https://<host>/api/mail_test.php?to=you@example.com&token=YOUR_CRON_TOKEN' | jq .
  ```
- Response includes `{ ok: true|false, transport, result }` and sends are logged to `state/mail.log.jsonl`.

## Alerts

`api/alerts_eval.php` now uses the unified Mailer:

- Subject: `[Alert] <rule name>`
- Plain text body with key fields and appended JSON dump
- On failure, appends a line to `state/mail_failures.log`
