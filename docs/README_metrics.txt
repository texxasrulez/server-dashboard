Zoomable System Metrics bundle
=============================
Files:
  - assets/js/index/metrics.zoom.js
  - assets/css/index_metrics.zoom.css

How to wire (replace the old metrics include):
  <!-- old -->
  <!-- <script defer src="assets/js/index/metrics.js?v=<?= h(BUILD) ?>"></script> -->

  <!-- new -->
  <link rel="stylesheet" href="assets/css/index_metrics.zoom.css?v=<?= h(BUILD) ?>" />
  <script defer src="assets/js/index/metrics.zoom.js?v=<?= h(BUILD) ?>"></script>

What it does:
  - Polls api/metrics_summary.php every 5s.
  - Plots CPU load %, Memory used %, and Net KB/s on the canvases:
      #chartLoad, #chartMem, #chartNet
  - Zoom (wheel), Pan (drag), Reset (double click).
  - CPU and Memory lines: green → yellow → red by value; Network: auto-scaled blue.

Note: if your JSON fields differ, tell me and I'll adjust extraction lines.
