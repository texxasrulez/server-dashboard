<?php
// log_viewer.php — UI shell for the Tail & Filters log viewer
require __DIR__.'/includes/head.php';
?>
<div id="logapp"></div>
<script src="assets/js/pages/logviewer.js?v=<?=htmlspecialchars(date('Ymd.His'))?>"></script>
<?php require __DIR__.'/includes/foot.php'; ?>
