# User Menu (Header) — Drop‑in Contract

This bundle contains:
- `tools/header_userbox_block.html` → the exact markup the JS expects
- `assets/css/header/userbox.css` → theme‑token based styles
- `assets/js/header/userbox.js` → minimal toggle + `window.__UserMenu` for debugging
- `tools/usermenu_probe.php` → inline test page

## Install

1. **Replace the markup block** inside `includes/header.php` from `<div class="userbox">` … `</div>` with the contents of `tools/header_userbox_block.html`.
   - Required IDs: `id="userbtn"`, `id="usermenu"`
   - Container must have class `.userbox`.

2. Ensure your footer includes these two files **after** theme CSS:
```php
<link rel="stylesheet" href="<?= h(project_url('/assets/css/header/userbox.css')) ?>?v=<?= h(BUILD) ?>" />
<script defer src="<?= h(project_url('/assets/js/header/userbox.js')) ?>?v=<?= h(BUILD) ?>"></script>
```

3. Visit `/tools/usermenu_probe.php` and click the avatar/name. In console:
```js
__UserMenu.state()
```
Expected: `{ hidden:false, hasBtn:true, hasMenu:true }`.

4. On any app page, open DevTools Console:
```js
typeof __UserMenu, __UserMenu?.state?.()
```
If `__UserMenu` is defined but the button doesn’t work, run:
```js
document.getElementById('userbtn')?.click()
```
You should see the menu. If not, verify the IDs and that no overlay sits on top of the button.

## Theme safety
CSS uses tokens with fallbacks:
```
--menu-bg, --menu-fg, --menu-border, --shadow-4
```
Themes may override them; if not present, fallbacks ensure a readable dropdown.

## Common pitfalls
- Missing IDs (`userbtn`/`usermenu`)
- Another element covering the button (set `position:relative` on `.userbox`)
- JS not loaded (check Network for `userbox.js` 200, and console for errors)
