# Legacy asset references & dropdown failsafe

This patch provides:
- **Compatibility shim** `assets/js/index/metrics.js` → loads `metrics.zoom.js` using the same query string.
- **Scanner** `tools/find_legacy_refs.php` → finds files that still reference legacy names like `header_status.js` or `metrics.js`.
- **Audit enhancement** → shows if legacy filenames are present in the live DOM.
- **Header dropdown baseline assets** (safe to overwrite).

## Why
A 404 on a script (e.g., `metrics.js`) can disrupt other JS on the page or mask real errors. The shim resolves it immediately while you replace legacy references at your pace.

## How to use
1. Drop the zip into your project root (preserve paths).
2. Visit `tools/find_legacy_refs.php` → replace or remove any matches it reports:
   - `assets/js/header_status.js` → use `assets/js/header/status.js`
   - `assets/js/index/metrics.js` → use `assets/js/index/metrics.zoom.js`
   - `index_refresh.js`, `index_process_bars.js`, `header_status.js` → remove/replace per cleanup docs
3. Open `/tools/assets_audit.php` → **Scan now** → see “Legacy references present in DOM”.
4. Hard reload (disable cache) and test the header dropdown.

## Notes
- The shim is temporary; once all pages reference `metrics.zoom.js`, you can remove `assets/js/index/metrics.js`.
- The dropdown uses theme tokens only (`--menu-bg/fg/border/shadow`) and won’t affect other components.
