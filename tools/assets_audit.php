<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: text/html; charset=utf-8');
require_login();
?>
<!DOCTYPE html>
<html lang="en">



  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Assets Audit</title>
  <base href="<?= h(project_url('/')) ?>">
  <link rel="stylesheet" href="<?= h(project_url('/assets/css/app.css')) ?>?v=<?= h(BUILD) ?>" />
  <link rel="stylesheet" href="<?= h(project_url('/assets/css/themes/core.css')) ?>?v=<?= h(BUILD) ?>" />
  <link rel="stylesheet" href="<?= h(project_url('/assets/css/tools/assets_audit.css')) ?>?v=<?= h(BUILD) ?>" />
  <script defer src="<?= h(project_url('/assets/js/tools/assets_audit.js')) ?>?v=<?= h(BUILD) ?>"></script>
</head>
<body>
  <main class="container">
    <div class="card">
      <div class="card-head">
        <div class="card-title">Assets Audit</div>
        <div class="audit-toolbar"></div>
      </div>
      <div class="card-body audit-body">
        <p>This tool lists loaded scripts and styles to help track down path/version issues. Use the buttons above.</p>
        <pre id="aa-output" class="muted">Ready.</pre>
      </div>
    </div>
  </main>
</body>
</html>
