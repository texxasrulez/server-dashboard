# Auth — Login/Logout + Theme

Locations:
- `auth/login.php`, `auth/logout.php`
- `includes/head_public.php`, `includes/foot_public.php`
- `theme_set.php`, `includes/init.php`

## How it works
- **Relative paths only** (drop‑in anywhere).
- Theme comes from **session** or **cookie** (`theme`) so unauth pages match the site.
- Logout preserves the theme by saving/restoring `$_SESSION['theme']`.

## Debugging
- If `url_rel_to_root()` is missing: make sure `includes/init.php` is required **before** calling helpers.
- If assets 404 on login page: check `$ASSETS_PREFIX` in `includes/head_public.php` (for login it’s `../assets`).

## Tuning
- Theme cookie: set for 180 days in `theme_set.php`.
- To force a theme site‑wide for testing: set `$_SESSION['theme']` manually or set cookie `theme`.
