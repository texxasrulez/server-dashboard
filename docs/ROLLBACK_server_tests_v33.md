# v33 rollback kit (safe)

What changed:
- Replaced `assets/js/pages/server_tests.js` with a **no-op** to prevent DOM moves.
- Added conservative CSS: `assets/css/pages/server_tests.css` (no container overrides).
- Added `assets/css/components/chips.css` (glassy chips).

How to apply:
1) Upload these three files into your project keeping the same paths.
2) In `server_tests.php`:
   - Keep the `<link>` to `assets/css/pages/server_tests.css`.
   - You may keep the `<script>` include for `server_tests.js` — it's now a no-op.
3) If your Extensions card wraps items in `<div class="chip-list">…</div>`
   inside a container with class `st-extensions`, they will auto-flow in columns.

That’s it. No JS layout logic runs anymore, so content will not disappear.
