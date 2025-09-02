
Fix bundle: restore service-card colors + safe cleanup plan
==========================================================

1) Add the stylesheet
   -------------------
   Include this after your theme/core CSS so it can win:

     <link rel="stylesheet" href="assets/css/index_services.colors.css?v=<?= h(BUILD) ?>" />

   Recommended: put it once in `includes/head.php` after your theme stylesheet.

2) Safe removals (optional)
   -------------------------
   If you're now using the modular index:
   - REMOVE (or stop including) these older files to avoid double-binding:
       assets/js/index/services.js         (replaced by assets/js/index/services.fix.js)
       assets/js/index/disks.js            (replaced by assets/js/index/disks.render.js)
       assets/js/index/index_process_bars.js (legacy)
       assets/js/index/index_refresh.js      (legacy)
       assets/js/header_status.js            (replaced by assets/js/header/status.js)
       assets/js/index_autoprobe_bind.js     (rolled into header/status + services.fix)

   Only delete if they're not referenced anywhere. If uncertain, just remove the
   <script> tags from pages and keep the files for now.

3) About metrics/zoom
   -------------------
   I didn't change your metrics module in this bundle. Once you share which API
   the charts use (the exact endpoint and JSON shape), I'll drop in a zoomable
   replacement that preserves your colors and icons and adds wheel + drag zoom.
