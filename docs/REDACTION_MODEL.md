# Redaction Model

The dashboard now separates:

- display-visible paths
- persisted or exported paths

Sensitive values may remain visible in authenticated UI where that is intentional. Persisted artifacts are redacted by default.

## Central helper

`lib/Redaction.php`

It is used for:

- config exports
- audit metadata
- persisted backup action metadata
- support bundle contents
- copied command or log snippets that should not keep raw tokens

## Default redaction targets

- keys containing `token`, `secret`, `password`, `pass`, `api_key`, `apikey`, `authorization`
- query parameters like `?token=...`
- headers such as `Authorization: Bearer ...`, `X-CRON-TOKEN: ...`, `X-API-TOKEN: ...`
- CLI flags like `--token`, `--password`, `--secret`

## Important boundary

- Authenticated UI can still show live secrets where explicitly designed to do so.
- Logs, JSON exports, support bundles, persisted command traces, and audit metadata should not.
