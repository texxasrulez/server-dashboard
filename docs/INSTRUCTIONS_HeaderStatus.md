# Header Status Chip — Instructions
**Updated:** 2025-08-11

## Files
- `assets/css/header/status.css`
- `assets/js/header/status.js`

## What it does
- Renders a status pill in the header and summarizes overall services health.
- Sources status from `api/services_status.php` **or** the `services:probeUpdate` event (whichever arrives).

## Markup
Place once in your shared header (e.g., `includes/header.php`):
```html
<div id="header-status" data-show-count="1" data-pulse="1"></div>
```

## Wiring (loaded globally in `includes/foot.php`)
```html
<link rel="stylesheet" href="assets/css/header/status.css?v=<?= h(BUILD) ?>" />
<script defer src="assets/js/header/status.js?v=<?= h(BUILD) ?>"></script>
```

## Options (data-* attributes)
- `data-refresh="60"` — poll fallback interval (seconds) when no bus/probe events.
- `data-show-count="0|1"` — show/hide the `x/y up` counter.
- `data-pulse="0|1"` — subtle pulse when WARN/DOWN.

## Events it listens to
- `services:probeUpdate` — `{ results: [...], ts }` (from auto-probe or manual probe)
- `Dashboard.Bus` (optional) `dashboard:tick` — prompts a refresh.

## Theming
Uses CSS custom properties; follows the active theme. To tweak colors in a theme, override:
```css
:root{ --pill-bg:..., --up:..., --warn:..., --down:..., --neutral:... }
```
