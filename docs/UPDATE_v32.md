# v32 Hotfix — Server Tests blank page

**What changed**  
The previous script tried to *move* the card nodes into a new wrapper. On some pages the selector didn’t match your layout, so nodes were detached and nothing rendered.

**This fix**  
- Never moves nodes. It only **adds a class/id** to the first existing container it finds and applies CSS grid.
- Report card chip is inserted safely next to the title/buttons if present.

**Install**  
1. Add this import at the **end** of `assets/css/themes/nord.css`:

```css
@import url('../components/chips.css');
```

2. Ensure these files are present:
- `assets/css/components/chips.css`
- `assets/css/pages/server_tests.css`
- `assets/js/pages/server_tests.js`

3. In `server_tests.php`, load the page files (if not already):

```html
<link rel="stylesheet" href="assets/css/pages/server_tests.css?v=<?php echo h(BUILD); ?>">
<script defer src="assets/js/pages/server_tests.js?v=<?php echo h(BUILD); ?>"></script>
```

4. Optional: on the **Extensions** card container, add `class="st-extensions"` and wrap its chips with `<div class="chip-list">…</div>` for an auto multi-column layout.