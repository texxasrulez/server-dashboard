# Users Administration & Storage Debug

## Where users live
- File: `data/users.json` (constant `USERS_FILE` in `includes/auth.php`)

## Page
- `/users.php` — all users can **Change Password**
- Admins also see:
  - **User Administration** (add/delete, change role)
  - **Users Storage (debug)** — shows resolved path, writability, size, and Modified time
  - **Test write** button (round-trips the JSON to verify permissions)

## Tips
- If the storage test fails, fix permissions on `data/` and `users.json` so the PHP user can write.
- To replace the users database, upload a new `data/users.json` (make a backup first).
- To bootstrap, the system can create a default admin on first run (see `includes/auth.php`).

## URLs
- Users page: `/users.php`
