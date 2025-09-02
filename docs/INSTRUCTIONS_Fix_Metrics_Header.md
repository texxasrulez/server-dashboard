# Fix: metrics.js 404 and header dropdown not opening

## 1) Replace the shim
Copy `assets/js/index/metrics.js` from this zip into your project. It now rewrites its own URL to `metrics.zoom.js` so it won't 404 as `/metrics.zoom.js` at the site root.

## 2) Ensure header IDs exist
In `includes/header.php`, replace the block from `<div class="userbox">` … `</div>` with `tools/header_userbox_block.html` (from this zip).
Required:
- button: `id="userbtn"`
- menu:   `id="usermenu"`

## 3) (Optional) Load a tiny sanity check
In your footer (after other scripts), include:
<script defer src="<?= h(project_url('/assets/js/header/header_sanity.js')) ?>?v=<?= h(BUILD) ?>"></script>

On refresh, the console will warn if the IDs are missing.

## 4) Hard refresh
Disable cache in DevTools, then hard reload. Console should show no 404 for metrics, and the dropdown should open. Test:
- `typeof __UserMenu` → "object"
- `__UserMenu.toggle()` → opens/closes menu
