<?php
// theme_set.php — robust, no notices, no external dependencies
// Purpose: set current theme cookie from ?theme=... OR from config/local.json fallback.
// Safe on fresh installs (no local.json).

declare(strict_types=1);

// Determine project root (this file lives at project root)
$ROOT = __DIR__;

// Ensure $local exists and is an array
if (!isset($local) || !is_array($local)) {
  $local = [];
  // Try common locations for local config
  $candidates = [
    $ROOT . '/config/local.json',
    $ROOT . '/data/local.json',
    $ROOT . '/local.json',
  ];
  foreach ($candidates as $f) {
    if (is_file($f)) {
      $json = @file_get_contents($f);
      $data = @json_decode($json, true);
      if (is_array($data)) { $local = $data; break; }
    }
  }
}

// Determine theme (request override > config > default)
$theme = isset($_GET['theme']) ? preg_replace('~[^a-z0-9_-]+~i','', (string)$_GET['theme']) : '';
if ($theme === '' || $theme === null) {
  $theme = (string)($local['site']['theme'] ?? $local['theme'] ?? 'default');
  $theme = preg_replace('~[^a-z0-9_-]+~i','', $theme);
  if ($theme === '' || $theme === null) $theme = 'default';
}

// Apply & respond
if (!defined('THEME')) define('THEME', $theme);
@setcookie('theme', $theme, time()+86400*365, '/', '', false, true);
header('Content-Type: application/json; charset=UTF-8');
echo json_encode(['ok'=>true, 'theme'=>THEME], JSON_UNESCAPED_SLASHES);
