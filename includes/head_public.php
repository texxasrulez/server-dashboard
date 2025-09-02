<?php require_once __DIR__ . '/init.php'; ?>
<?php
  // Site name + theme from central config if available
  try {
    $cfgLib = __DIR__ . '/../lib/Config.php';
    if (is_file($cfgLib)) {
      require_once $cfgLib;
      \App\Config::init(dirname(__DIR__));
      $SITE_NAME = \App\Config::get('site.name', 'Server Dashboard');
      $THEME = \App\Config::get('site.theme', $THEME ?? (defined('THEME_DEFAULT')? THEME_DEFAULT : 'default'));
    } else { $SITE_NAME = 'Server Dashboard'; }
  } catch (Throwable $e) { $SITE_NAME = 'Server Dashboard'; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= h(isset($PAGE_TITLE) && $PAGE_TITLE !== '' ? ($PAGE_TITLE . ' — ' . $SITE_NAME) : $SITE_NAME) ?></title>
  <?php
    // Assets base is relative to the *rendered page* (e.g., auth/login.php -> ../assets)
    $ASSETS_PREFIX = $ASSETS_PREFIX ?? 'assets';
    $theme_href_public = $ASSETS_PREFIX . 'css/themes/' . basename($THEME) . '.css?v=' . h(BUILD);
    $page_css_href = null;
    if (!empty($PAGE_CSS)) {
      if (strpos($PAGE_CSS, 'assets/') === 0) $page_css_href = $ASSETS_PREFIX . substr($PAGE_CSS, strlen('assets'));
      else $page_css_href = $PAGE_CSS;
    }
  ?>
  <link rel="stylesheet" href="<?= h($ASSETS_PREFIX) ?>/css/theme/core.css?v=<?= h(BUILD) ?>" />
  <link rel="stylesheet" href="<?= h($theme_href_public) ?>" />
  <?php if (!empty($page_css_href)) : ?>
    <link rel="stylesheet" href="<?= h($page_css_href) ?>" />
  <?php endif; ?>
  <script defer src="<?= h($ASSETS_PREFIX) ?>/js/app.js?v=<?= h(BUILD) ?>"></script>
</head>
<body class="theme-<?= h($THEME) ?>">
<div class="content">
