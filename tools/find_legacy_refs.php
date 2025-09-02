<?php
$ROOT = realpath(__DIR__ . '/..');
$legacy = [
  'assets/js/header_status.js',
  'assets/js/index/metrics.js',
  'index_refresh.js',
  'index_process_bars.js',
  'header_status.js',
];
$exts = ['php','html','htm','js','css'];
$found = [];
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ROOT, FilesystemIterator::SKIP_DOTS));
foreach ($rii as $file) {
  if (!$file->isFile()) continue;
  $rel = ltrim(str_replace($ROOT, '', $file->getPathname()), DIRECTORY_SEPARATOR);
  $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
  if (!in_array($ext, $exts, true)) continue;
  if (strpos($rel, 'vendor/') === 0) continue;
  $content = @file_get_contents($file->getPathname()); if ($content === false) continue;
  foreach ($legacy as $needle) {
    if (stripos($content, $needle) !== false) {
      $lines = explode("\n", $content);
      foreach ($lines as $ln => $line) {
        if (stripos($line, $needle) !== false) {
          $found[] = ['file'=>$rel, 'line'=>$ln+1, 'match'=>trim($line)];
        }
      }
    }
  }
}
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['root'=>$ROOT, 'count'=>count($found), 'hits'=>$found], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
