
# Drop-in Update (v31)

This package contains:
- `assets/css/components/chips.css` — sitewide glassy chips that restyle `.chip`, `.badge`, and `.pill` (no HTML changes needed).
- `assets/css/pages/server_tests.css` — compact grid, two-column KV tables, extensions multi-column grid, report-card styles.
- `assets/js/pages/server_tests.js` — Extensions grid renderer + PHP card compactor + header report card injector.
- `assets/css/themes/_append_to_nord.css` — one-liner you should append to the end of your `assets/css/themes/nord.css`.

## Install Steps (exact)
1. Copy the files into your project preserving folder structure.
2. **Open** `assets/css/themes/nord.css` and append the single line:
   ```css
   @import url('../components/chips.css');
   ```
   Put it at the **end** of the file so it can win on specificity.
3. Ensure `server_tests.php` already includes `assets/css/pages/server_tests.css` and `assets/js/pages/server_tests.js`.  
   If not, add these just for the server tests page:
   ```html
   <link rel="stylesheet" href="assets/css/pages/server_tests.css?v=<?php echo h(BUILD); ?>">
   <script defer src="assets/js/pages/server_tests.js?v=<?php echo h(BUILD); ?>"></script>
   ```

## What changed
- **Extensions** card now lays out as a responsive **multi-column** grid of glassy chips.
- **PHP** card is compacted: short **two-column** KV layout at normal (1/4) width.
- **Report Card** appears **above the action buttons (right)** showing a grade (A/A-/B/C) with a glassy pill. Grade is computed heuristically from up/warn/down counts already on the page. No server changes.
- **Chips sitewide** unify visually: `.badge` and `.pill` pick up the new glassy style automatically.

## Notes
- The report card will not appear if the page cannot find the "Run all" button container; it fails gracefully.
- If you have huge numbers of extensions, uncomment the optional containment in `server_tests.css` to give just that card an internal scroll.
