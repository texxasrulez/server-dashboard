# Cleanup & Safe Bundling — Instructions
**Updated:** 2025-08-11

To avoid double-binding and regressions, keep includes lean and **load once** globally.

## Recommended global includes (`includes/foot.php`)
```html
<link rel="stylesheet" href="/assets/build_css.php?b=core&v=<?= h(BUILD) ?>" />
<script defer src="/assets/build_js.php?b=core&v=<?= h(BUILD) ?>"></script>

<link rel="stylesheet" href="assets/css/header/status.css?v=<?= h(BUILD) ?>" />
<script defer src="assets/js/header/status.js?v=<?= h(BUILD) ?>"></script>
```

## Index page includes (bottom of `index.php`)
```html
<script defer src="assets/js/index/bootstrap.js?v=<?= h(BUILD) ?>"></script>
<script defer src="assets/js/index/services.fix.js?v=<?= h(BUILD) ?>"></script>
<script defer src="assets/js/index/proc.render.js?v=<?= h(BUILD) ?>"></script>
<script defer src="assets/js/index/disks.render.js?v=<?= h(BUILD) ?>"></script>
<link rel="stylesheet" href="assets/css/index_metrics.zoom.css?v=<?= h(BUILD) ?>" />
<script defer src="assets/js/index/metrics.zoom.js?v=<?= h(BUILD) ?>"></script>
<link rel="stylesheet" href="assets/css/index_services.colors.css?v=<?= h(BUILD) ?>" />
```

## Files you can stop including (if switched to modules above)
- `assets/js/index/services.js` (replaced by `index/services.fix.js`)
- `assets/js/index/disks.js` (replaced by `index/disks.render.js`)
- `assets/js/header_status.js` (replaced by `header/status.js`)
- `assets/js/index_autoprobe_bind.js` (rolled into header/services modules)
- Legacy: `assets/js/index_process_bars.js`, `assets/js/index_refresh.js`

**Tip:** First remove `<script>` tags; after a day with no regressions, delete the files.
