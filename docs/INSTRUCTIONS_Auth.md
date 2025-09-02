# Authentication (Login/Logout)

This adds a simple session-based login with a JSON user store.

## Files
- `includes/auth.php` — helpers and guards (`require_login()`, `auth_login()`, CSRF)
- `auth/login.php` — sign-in form (creates a default admin on first run)
- `auth/logout.php` — ends the session
- `data/users.json` — user database (created automatically), default admin when empty
- `users.php` — current-user password change

## How it works
- All normal pages include `includes/head.php`, which now calls `require_login()`. Unauthenticated visitors are redirected to `/auth/login.php`.
- On first visit, if `data/users.json` is empty, a default `admin` with a temporary random password is created and shown on the login screen *once*.

## First-time setup
1. Visit `/auth/login.php` in your browser.
2. Use the temporary password shown, then go to **Users** to change it.

## Add more users (manual)
Edit `data/users.json` to include additional users with `password_hash` from `password_hash()`, or extend `users.php` to add an admin UI.
