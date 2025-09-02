# New Theme Pack (6 pairs)
Drop these files into your project at `assets/css/themes/`. No PHP changes required.

Themes included (desktop + mobile):
- onyx
- midnight
- oceanic
- forest
- amber
- rose

## How to test
1) Copy the `assets/css/themes/*.css` files into your project.
2) Switch THEME to one of the names above (your existing theme selector / env).
3) Reload desktop and mobile widths. Chips and toasts will auto-adapt via tokens.

Notes:
- These files set only CSS variables (`--bg`, `--fg`, `--card`, `--border`, `--accent`, `--accent-2`, `--danger`, `--warn`, `--ok`).
- Mobile files contain a safe guard so mobile-only chrome won’t appear on desktop.
- If any component still shows a hard-coded color, it’s from an older CSS file. Replace that color with a token or add a small override using the token values.
