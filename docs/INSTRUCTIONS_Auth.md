# Authentication (Login/Logout)

This adds a simple session-based login with a JSON user store.

## Files

- `includes/auth.php` — helpers and guards (`require_login()`, `auth_login()`, CSRF)
- `auth/login.php` — sign-in form
- `auth/logout.php` — ends the session
- `data/users.json` — user database (created automatically)
- `bin/bootstrap-admin.php` — CLI bootstrap command for first admin creation
- `users.php` — current-user password change

## How it works

- All normal pages include `includes/head.php`, which now calls `require_login()`. Unauthenticated visitors are redirected to `/auth/login.php`.
- Login attempts are rate-limited (`security.login_rate_limit` in config).
- By default, web bootstrap is disabled; first admin should be created via CLI for internet-facing deployments.

## First-time setup

1. Run `php bin/bootstrap-admin.php` on the server.
2. Use the printed credentials at `/auth/login.php`, then go to **Users** to change the password.
3. Optional: set `security.allow_web_bootstrap_admin=true` only for temporary/local bootstrap workflows.

## Add more users (manual)

Edit `data/users.json` to include additional users with `password_hash` from `password_hash()`, or extend `users.php` to add an admin UI.
