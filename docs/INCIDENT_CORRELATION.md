# Incident Correlation

Incident correlation is intentionally lightweight. The dashboard keeps raw alert events in `state/alerts_events.jsonl`, then derives incidents from those events instead of replacing them.

## Inputs

- `state/alerts_events.jsonl`
- `data/services.json`
- `state/services_status_history.jsonl`
- audit logs exposed through `lib/AdminAudit.php`
- `state/backup_actions.json`
- speedtest history through `lib/Speedtest.php`

## Correlation model

- Correlation window: `config/incident_rules.json` → `window_sec`
- Recent horizon: `config/incident_rules.json` → `recent_window_sec`
- Dependency hints: `config/incident_rules.json` → `dependencies`

Each incident has:

- deterministic `id`
- `title`
- `host`
- `root_service_id`
- `services`
- `severity`
- `first_ts` / `last_ts`
- `status`
- raw grouped `events`

Secondary events inside the same time window on the same host are marked as downstream when they match the current root service or dependency hints.

## State overrides

Operator state changes are stored in `state/incidents_state.json`.

Supported incident states:

- `open`
- `acknowledged`
- `resolved`
- `suppressed`

## UI

- Existing History page now shows `Recent Incidents`
- Incident detail lives at `incident.php?id=...`
- Services and service-detail views link into incident detail when correlation exists

No new top-level navigation was added.
