# Diagnostics Modals

- Links with `data-modal` on `diag.php` are intercepted by `assets/js/pages/diag.js`.
- `metrics_summary.php` is fetched as JSON and rendered in a themed modal with:
  - Pretty‑print toggle
  - Copy JSON button
- `tools/assets_audit.php` (and other links) are fetched as HTML and the header/footer/nav are stripped before injecting content into the modal.

No iframes; content inherits theme tokens.
