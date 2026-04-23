Header Status (modular, pretty, theme-safe)
==========================================

Files
-----
assets/js/header/status.js   # self-contained header chip (no global CSS churn)
assets/css/header/status.css # minimal styles, theme-friendly; subtle pulse on WARN/DOWN

How it works
------------
- Finds (or creates) a container with id="header-status".
- Renders a single chip: dot + label + x/y count.
- Updates from:
  1) services:probeUpdate (if your autoprobe emits it), or
  2) fetch('api/services_status.php') on an internal timer,
  3) plus listens to Dashboard.Bus 'dashboard:tick' if available.
- Computes overall state: DOWN if any down; WARN if none down but any warn; UP if >=1 up; NEUTRAL otherwise.
- No flicker: only swaps classes/text when state changes.

Install (without bundles)
-------------------------
1) Place both files in your project preserving folders.
2) In includes/foot.php (global), add near the end, before </body>:
   <link rel="stylesheet" href="assets/css/header/status.css?v=<?= h(BUILD) ?>" />
   <script defer src="assets/js/header/status.js?v=<?= h(BUILD) ?>"></script>

3) Remove/disable older header_status.js includes to avoid double-binding.

Install (with your PHP bundles)
-------------------------------
- In assets/bundles.php add:
  'js'  => [ 'core' => [ 'assets/js/header/status.js', /* ...existing */ ] ]
  'css' => [ 'core' => [ 'assets/css/header/status.css', /* ...existing */ ] ]

Options
-------
- Add data attributes on #header-status to tweak behavior:
  data-refresh="30"   -> poll every 30s if Dashboard.Bus isn't available
  data-show-count="0" -> hide the x/y count text
  data-pulse="1"      -> enable subtle pulse on WARN/DOWN

Expected Markup (if you want to place it manually)
--------------------------------------------------
<div id="header-status"></div>

If absent, the script will try to append a container to '.topbar, header, body' in that order.
