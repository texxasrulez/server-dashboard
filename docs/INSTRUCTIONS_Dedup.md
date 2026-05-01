## Code & Navigation Duplication Policy (read before you add stuff)

- Check for duplicate links/destinations in `includes/header.php` before adding nav items.
- Keep user actions (Profile, Logout) in the **user menu**; do not duplicate them in tabs.
- Gate admin tabs with `user_is_admin()` and only render links if the file exists.
- Reuse modules; prefer extending helpers to cloning.
- One CSS/JS module per feature; keep them page‑scoped; no inline CSS/JS.
- Use theme tokens (CSS variables); no hard-coded colors.
