<?php
declare(strict_types=1);
$B = $_GET['b'] ?? 'core';
$conf = require __DIR__ . '/bundles.php';
$bundles = $conf['css'] ?? [];
$files = $bundles[$B] ?? [];
header('Content-Type: text/css; charset=UTF-8');
header('Cache-Control: no-store'); // you already version with ?v=BUILD
$seen = [];
$base = dirname(__DIR__);
echo "/* css-bundle:$B generated " . gmdate('c') . " */\n";
foreach ($files as $rel) {
  $rel = ltrim($rel, '/');
  if (isset($seen[$rel])) continue;
  $seen[$rel] = true;
  $path = $base . '/' . $rel;
  if (!is_file($path)) {
    echo "/* [css-bundle:$B] missing file: " . addslashes($rel) . " */\n";
    continue;
  }
  $css = file_get_contents($path);
  echo "\n/* --- BEGIN " . addslashes($rel) . " --- */\n";
  echo $css;
  echo "\n/* --- END " . addslashes($rel) . " --- */\n";
}
