# INSTRUCTIONS — Health Probe

**File:** `api/health.php`  
**Route:** `/api/health.php`  
**Purpose:** Minimal JSON endpoint for uptime/LB checks and smoke tests.

## Response
```json
{ "ok": true, "time": "<ISO8601>", "build": "<BUILD>", "state_dir": "<path or null>", "state_writable": true/false }
```

## Usage
- Point your load balancer/monitor to `/api/health.php` expecting HTTP 200 and `ok: true`.
- Not authenticated; keep it simple for infra.

## Notes
- Does not touch disk; only reports `state/` detectability and writability.
- Safe on Windows, Linux, macOS; no shell calls.

## Theme/layout
- None (pure JSON). No theme considerations.
