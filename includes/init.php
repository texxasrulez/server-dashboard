<?php
// includes/init.php — canonical bootstrap (no UI includes).
// Safe to include multiple times.
require_once __DIR__ . '/config.inc.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

if (!defined('BUILD')) { define('BUILD', date('Ymd.His')); }
if (!function_exists('h')) {
  function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('project_url')) {
  function project_url(string $p = ''): string {
    $p = (string)$p;
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $base = rtrim(dirname($script), '/');
    if ($p === '' || $p === '/') return $base . '/';
    if (preg_match('#^https?://#i', $p)) return $p;
    if ($p[0] === '/') return $base . $p;
    return $base . '/' . $p;
  }
}

if (!function_exists('asset_href')) {
  function asset_href(string $rel): string {
    $rel = ltrim((string)$rel, '/');
    return project_url('/assets/' . $rel);
  }
}

// === Themes: support BOTH legacy assets/css/themes/*.css and assets/css/themes/* ===
if (!function_exists('theme_list')) {
  function theme_list(): array {
  $names = [];
  $dir = __DIR__ . '/../assets/css/themes';
  if (is_dir($dir)) {
    foreach (@scandir($dir) ?: [] as $f) {
      if ($f === '.' || $f === '..') continue;
      $fs = $dir . '/' . $f;
      if (is_dir($fs)) {
        // accept folder-based theme only if it contains theme.css
        if (is_file($fs . '/theme.css')) $names[] = $f;
      } elseif (substr($f, -4) === '.css') {
        $name = basename($f, '.css');
        // skip mobile variants
        if (preg_match('/\.mobile$/i', $name)) continue;
        $names[] = $name;
      }
    }
  }
  if (!$names) $names = ['nord'];
  $names = array_values(array_unique($names));
  sort($names, SORT_NATURAL|SORT_FLAG_CASE);
  return $names;
}
}

// Determine current theme (session → cookie → THEME_DEFAULT → 'default')
if (!function_exists('theme_current')) {
  function theme_current(): string {
    $t = null;
    if (!empty($_SESSION['theme'])) {
      $t = (string) $_SESSION['theme'];
    } elseif (!empty($_COOKIE['dash_theme'])) {
      $t = preg_replace('/[^a-z0-9_\-]/i', '', (string)$_COOKIE['dash_theme']);
    } elseif (defined('THEME_DEFAULT')) {
      $t = (string) THEME_DEFAULT;
    } else {
      $t = 'default';
    }
    $list = theme_list();
    if (!in_array($t, $list, true)) $t = 'default';
    return $t;
  }
}

$GLOBALS['THEME'] = theme_current();

if (!function_exists('theme_href')) {
  function theme_href(string $rel = ''): string {
    if ($rel !== '') {
      $rel = ltrim($rel, '/');
      return project_url('/assets/css/' . $rel);
    }
    $theme = $GLOBALS['THEME'] ?? 'default';
    // Legacy single-file theme wins if present
    $legacy = __DIR__ . '/../assets/css/themes/' . $theme . '.css';
    if (is_file($legacy)) return project_url('/assets/css/themes/' . $theme . '.css');
    // Modern candidates
    $candidates = [
      ['fs' => __DIR__ . '/../assets/css/themes/' . $theme . '.css',        'web' => '/assets/css/themes/' . $theme . '.css'],
      ['fs' => __DIR__ . '/../assets/css/themes/' . $theme . '/theme.css',  'web' => '/assets/css/themes/' . $theme . '/theme.css'],
      ['fs' => __DIR__ . '/../assets/css/themes/default.css',               'web' => '/assets/css/themes/default.css'],
      ['fs' => __DIR__ . '/../assets/css/theme.css',                        'web' => '/assets/css/theme.css'],
    ];
    foreach ($candidates as $c) {
      if (is_file($c['fs'])) return project_url($c['web']);
    }
    return project_url('/assets/css/theme.css');
  }
}
