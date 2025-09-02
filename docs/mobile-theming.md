# Mobile Theming

- `assets/js/mobile.js` detects mobile by UA or `(max-width: 768px)` and:
  - adds `.is-mobile` to `<body>`
  - swaps header logo to `assets/images/mobile-header-logo.png`
  - enables the mobile theme CSS by flipping `#theme-mobile` media to `all`

- `assets/css/themes/nord.mobile.css` overrides sizes/paddings for smaller screens.

To add mobile variants for future themes: create `assets/css/themes/<theme>.mobile.css` and keep the same token names.
