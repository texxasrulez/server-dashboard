# Index — Services Panel (cards) — Instructions
**Updated:** 2025-08-11

## Files
- `assets/js/index/services.fix.js`
- `assets/css/index_services.colors.css`

## What it does
- Loads the services list (tries several common endpoints) and renders cards.
- Applies live status (UP/WARN/DOWN) and latency to each card.
- Hides disabled services automatically.

## Endpoints (first that responds wins)
- `api/services_list.php`
- `api/services.php`
- `api/services_get.php`

Status source: `api/services_status.php` or the `services:probeUpdate` event.

## Enable/Disable detection
A service is considered **enabled** when any of these are true:
- `enabled: true | 1 | "true" | "on" | "yes"`
- `active: true`  
…and not disabled if `disabled` is falsy.

## Markup generated per card
```html
<div class="card service-card" data-svc-id="..." data-svc-name="...">
  <div class="row between">
    <div class="svc-name">
      <span class="svc-state-icon neutral"></span>
      <span class="svc-title">Name</span>
    </div>
    <div class="row">
      <span class="pill neutral">--</span>
      <span class="svc-latency muted"></span>
    </div>
  </div>
  <div class="muted small"> ... tags ... </div>
</div>
```

## Tuneables (JS)
- Add/remove list endpoints: `LIST_CANDIDATES` in `services.fix.js`.
- Map non-standard status fields: edit `extractStatus()`.
- Filter/hide logic: `isEnabled()`.

## Styling hooks (CSS)
- `.pill.up|.warn|.down|.neutral`
- `.svc-state-icon.up|.warn|.down|.neutral`
- `.service-card .tag`

Override per-theme with CSS variables or selectors.
