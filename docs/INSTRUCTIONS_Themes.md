# Themes & CSS Conventions — Instructions
**Updated:** 2025-08-11

- Per-page CSS is minimized; components use classes and CSS custom properties.
- Each theme should define your color tokens once. Example:
```css
/* assets/css/themes/dark.css */
:root{
  --bg: #111418;
  --fg: #e7eef6;
  --muted: #8aa0b3;
  --pill-bg: rgba(255,255,255,0.03);
  --pill-br: rgba(255,255,255,0.14);
  --up: #26d07c; --warn: #f1c40f; --down: #e74c3c; --neutral: #8aa0b3;
}
```
- Components (header chip, service cards, pills) automatically pick up these variables.
- To add a new theme: create `assets/css/themes/<name>.css`, register it in the picker, no JS changes required.
