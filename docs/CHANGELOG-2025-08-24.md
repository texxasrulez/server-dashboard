# Dashboard Theme Fix — pass on all pages (except bookmarks)
Date: 2025-08-24 23:58 UTC

## What changed
- **Theme core path fixed:** `includes/head.php` now loads `/assets/css/themes/core.css` (was `/assets/css/theme/core.css`), restoring base inputs/buttons layout.
- **Sticky layout:** `assets/css/app.css` now uses a 3-row CSS Grid (header / content / footer). The `.content` area scrolls; header & footer remain static.
- **Hide mobile hamburger on desktop:** Added a media rule in `assets/css/themes/core.css` to hide the hamburger icon at ≥1024px.
- **Full-width diagnostics pages:** 
  - `server_tests.php` uses `class="container full"` (was `container narrow`).
  - `diag.php` uses `class="container full diag"`.
- **Logs toolbar/layout:** left as in current build; added sticky toolbar styles for the top controls; dark inputs/selects already come from theme tokens.
- **Minor:** kept button palette unified; `.btn.danger` remains translucent red; no JS/PHP function changes.

## Files touched
- `includes/head.php`
- `assets/css/app.css`
- `assets/css/themes/core.css`
- `assets/css/base/30-layout.css`
- `assets/css/pages/logs.css`
- `server_tests.php`
- `diag.php`

## How to test
1. Clear browser cache / hard reload.
2. Visit each page from the header tabs; verify header/footer stay visible while the middle scrolls.
3. Confirm the theme selector only shows **nord**.
4. Confirm no hamburger icon at desktop widths (≥1024px); resize window to <1024px to see it return.
5. On **Server Tests** and **Diagnostics**, verify the main cards span the full width (with small side padding).
6. On **Logs**, confirm the "Regex filter" and date controls sit under the log picker and the table scrolls.

If anything looks off, capture a screenshot and note the viewport width; we’ll fine-tune the sticky offsets per page.


## 2025-08-25 00:06 UTC — Bookmarks page framing
- Keep header/footer static, keep top controls static.
- Only the bookmarks **list** scrolls (nested scroll area).
- Added `.content-locked` on this page to prevent parent scroll.
- CSS: `assets/css/pages/bookmarks.css` updated (flex + overflow rules).
- No function changes.


## 2025-08-25 00:25 UTC — Status badge dedupe (final)
- Removed auto-creation of `#header-status` in `assets/js/header/status.js`.
- `attach()` now bails when container is absent.


## 2025-08-25 00:55 UTC — Header badge DOM tidy
- Trimmed `renderShell()` template to avoid stray text node in `#header-status`.
