# Server Dashboard — Usage Manual (Living Doc)

This file documents the Services, Alerts, and History features. Keep it in the repo root; update as you iterate.

## Cron token (avoid 403 from curl)


### Cron markers (Cron Health card)
- The dashboard now **auto-updates** cron markers when you call `alerts_eval.php`:
  - Always marks `alerts` last run.
  - When `probe=1` is present, also marks `history` last append.
- You can still call `api/cron_mark.php?what=alerts|history` manually; it writes to the runtime dir and mirrors to legacy `state/` for compatibility.
Create a token at `state/cron_token.txt` (any random string). Use it with the evaluator:

```bash
echo "YOUR_LONG_RANDOM_TOKEN" > state/cron_token.txt
*/1 * * * * curl -fsS "https://YOUR-DASHBOARD/api/alerts_eval.php?probe=1&token=YOUR_LONG_RANDOM_TOKEN" >/dev/null
# Dry run:
# */1 * * * * curl -fsS "https://YOUR-DASHBOARD/api/alerts_eval.php?probe=1&dry=1&token=YOUR_LONG_RANDOM_TOKEN" >/dev/null
```

## Alerts
- Admin page: `alerts_admin.php`
- Data file: `data/alerts.json`
- Endpoints:
  - `GET api/alerts_list.php` → `{"items":[...]}`
  - `POST api/alerts_upsert.php` → upsert a rule (see shape below)
  - `POST api/alerts_delete.php?id=...` → delete by id
  - `GET api/alerts_eval.php?[probe=1]&[dry=1]&[token=...]` → evaluate, notify, append events

### Alert object
```json
{
  "id": "alert_xxxxxx",
  "name": "API latency too high",
  "service_id": "svc_...",
  "service_name": "API",
  "metric": "latency_ms",
  "op": ">=",
  "threshold": 600,
  "consecutive": 3,
  "cooldown_min": 30,
  "severity": "warn",
  "notify": { "email": "ops@example.com", "webhook_url": "" },
  "enabled": true,
  "last_triggered": 0,
  "times_triggered": 0,
  "consecutive_count": 0
}
```

## Services
- Manage in `services.php` (Add/Edit). Stored at `data/services.json`.
- Probing:
  - Manual: `GET api/services_probe_all.php`
  - Cron via evaluator: evaluator calls probe first when `probe=1`

Outputs:
- Latest snapshot: `state/services_status.json`
- History (JSONL): `state/services_status_history.jsonl` (one JSON per line)

## History
- Page: `history.php`
  - Range selector (1h/24h/7d/30d)
  - Service cards with latency sparklines
  - Alerts event table
  - Export buttons:
    - Probes CSV: `api/history_export.php?type=probes&format=csv`
    - Alerts CSV: `api/history_export.php?type=alerts&format=csv`
  - **Reset history**: `api/history_rotate.php` (renames history files with timestamp)

- API: `api/history_export.php`
  - Params: `type=probes|alerts`, `start`, `end`, `service_id`, `limit` (≤20000), `format=json|csv`

## Display Names
Throughout the UI (Alerts + History), service **names** are displayed when available. We map `service_id → name` from `data/services.json`. If no custom name exists, we fall back to host or id.

## Troubleshooting
- 403 from cron `curl`: use the token flow above.
- Empty history: ensure your cron runs evaluator with `probe=1` or run `api/services_probe_all.php` manually.
- CSV opens weird in Excel: import as UTF‑8, comma‑delimited.

## Cron token setup (auth for alerts_eval.php)

The evaluator accepts either an admin session **or** a shared token. For cron, use a token.

**Configure a token in ONE of these places (first non-empty wins):**
1. `config.php` — define a constant:
   ```php
   <?php define('CRON_TOKEN', 'YOUR_LONG_RANDOM_TOKEN');
   ```
2. Environment variable:
   ```bash
   export DASH_CRON_TOKEN=YOUR_LONG_RANDOM_TOKEN
   ```
3. File on disk (not web-exposed): `state/cron_token.txt`
   ```bash
   php -r 'echo bin2hex(random_bytes(16)), PHP_EOL;' > state/cron_token.txt
   chmod 640 state/cron_token.txt
   ```

**Cron example:**
```bash
*/1 * * * * curl -fsS "https://YOUR-DASHBOARD/api/alerts_eval.php?probe=1&token=YOUR_LONG_RANDOM_TOKEN" >/dev/null
```


## Bulk actions (Alerts)
- `POST /api/alerts_bulk.php` JSON body: `{ action: enable|disable|delete|silence, ids: ["alert_xxx"...], silence_minutes?: number }`
- Page UI: select rows → Bulk buttons (Enable/Disable/Delete/Silence). Silence sets `silenced_until` and the evaluator skips until the time passes.

## Notification templates
- In `alerts_upsert.php`, `notify.subject_tmpl`, `notify.body_tmpl`, and `notify.webhook_fmt` are accepted.
- Template tokens: `{name}`, `{service_name}`, `{service_id}`, `{metric}`, `{op}`, `{threshold}`, `{value}`, `{severity}`, `{alert_id}`, `{ts}`.
- `webhook_fmt: "slack"` will send a Slack-compatible payload; otherwise raw JSON event is sent.

## Evaluator silence logic
- Alerts with `silenced_until` greater than `now()` are skipped.



## Runtime/state location
History and status files are stored using the runtime directory resolver in `api/_state_path.php`.
- Snapshot: `services_status.json` (via `dashboard_state_path('services_status.json')`)
- Probe history: `services_status_history.jsonl` (via `dashboard_state_path('services_status_history.jsonl')`)
- Alert events: `alerts_events.jsonl` (via `dashboard_state_path('alerts_events.jsonl')`)

To override the default location, create `data/config.json`:
```json
{
  "runtime_dir": "/path/to/writable/dir"
}
```
