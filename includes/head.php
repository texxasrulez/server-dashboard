<?php
require_once __DIR__ . '/init.php';
require_once __DIR__ . '/auth.php'; require_login(); if (!empty($REQUIRE_ADMIN)) require_admin();

// Site name + theme from central config if available
try {
  $cfgLib = dirname(__DIR__) . '/lib/Config.php';
  if (is_file($cfgLib)) {
    require_once $cfgLib;
    \App\Config::init(dirname(__DIR__));
    $SITE_NAME = \App\Config::get('site.name', 'Server Dashboard');
    $THEME = \App\Config::get('site.theme', $THEME ?? (defined('THEME_DEFAULT')? THEME_DEFAULT : 'default'));
  } else {
    $SITE_NAME = 'Server Dashboard';
  }
} catch (Throwable $e) { $SITE_NAME = 'Server Dashboard'; }

?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= h(isset($PAGE_TITLE) && $PAGE_TITLE !== '' ? ($PAGE_TITLE . ' — ' . $SITE_NAME) : $SITE_NAME) ?></title>  
  <link rel="stylesheet" href="<?= h(project_url('/assets/css/app.css')) ?>?v=<?= h(BUILD) ?>" />
  <link rel="stylesheet" href="<?= h(project_url('/assets/css/themes/core.css')) ?>?v=<?= h(BUILD) ?>" />
  <link rel="stylesheet" href="<?= h(project_url('/assets/css/index_services.colors.css')) ?>?v=<?= h(BUILD) ?>" />
  <link rel="stylesheet" href="<?= h(project_url('/assets/css/components/modal.css')) ?>?v=<?= h(BUILD) ?>" />
  <link rel="stylesheet" href="<?= h(theme_href()) ?>?v=<?= h(BUILD) ?>" />
  <link rel="stylesheet" href="<?= h(project_url('/assets/css/themes/'.$THEME.'.mobile.css')) ?>?v=<?= h(BUILD) ?>" />
  <link rel="stylesheet" href="<?= h(project_url('/assets/css/components/chips.css')) ?>?v=<?= h(BUILD) ?>" />
  <link rel="stylesheet" href="assets/css/components/nav-active.css?v=<?= h(BUILD) ?>">
  <script src="assets/js/nav-active.js?v=<?= h(BUILD) ?>" defer></script>

  <?php if (!empty($PAGE_CSS)) : ?>
    <link rel="stylesheet" href="<?= h($PAGE_CSS) ?>?v=<?= h(BUILD) ?>" />
  <?php endif; ?>
  <script defer src="<?= h(project_url('/assets/js/app.js')) ?>?v=<?= h(BUILD) ?>"></script>
  <script defer src="assets/js/hotkeys.js?v=<?= h(BUILD) ?>"></script>
  <script defer src="assets/js/mobile.js?v=<?= h(BUILD) ?>"></script>
  <link rel="stylesheet" href="assets/css/components/drawer.css?v=<?= h(BUILD) ?>">
  <link rel="stylesheet" href="<?= h(project_url('/assets/css/components/sys-badge.css')) ?>?v=<?= h(BUILD) ?>">
  <script defer src="<?= h(project_url('/assets/js/sys-badge.js')) ?>"></script>
  <meta name="toast-position" content="<?= h(\App\Config::get('ui.toast_position', 'bottom-center')) ?>">
</head>
<body class="theme-<?= h($THEME) ?>"
      data-admin="<?= !empty($_SESSION['user']) && (($_SESSION['user']['role'] ?? '') === 'admin') ? '1' : '0' ?>"
      data-user="<?= h($_SESSION['user']['username'] ?? 'guest') ?>"
      data-role="<?= h($_SESSION['user']['role'] ?? 'user') ?>"
      data-build="<?= h(BUILD) ?>"
      data-api-metrics="<?= h(project_url('/api/metrics_summary.php')) ?>">
<link rel="stylesheet" href="<?= h(project_url('/assets/css/ui/buttons-core.css')) ?>?v=<?= h(BUILD) ?>">
<?php include __DIR__ . '/header.php'; ?>
<div class="content">
