# Navigation & Admin Gating

- The header tabs are rendered dynamically in `includes/header.php`.
- **Non-admin users** only see public tabs (currently: History, Logs), and only if those files exist.
- **Admins** see all admin tabs (Services, Security, Alerts, Databases, Audit Log, Config, Users, Diagnostics) — again, only if the files exist on disk to avoid broken links.
- To add a new admin page, create the PHP file at the project root and it will show up automatically if added to the `$candidates_admin` list.
- Admin pages should call `require_admin();` right after loading `includes/auth.php` so access is enforced even if someone bookmarks a direct URL.
- Diagnostics lives at `/diag.php` and is admin-only.
