<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/auth.php';
require_admin();
require_once __DIR__ . '/_state_path.php';
header('Content-Type: application/json; charset=utf-8');

$legacy = dirname(__DIR__) . '/state/history';
$targetDir = dirname(dashboard_state_path('history/.keep')) . '/history';
@mkdir($targetDir, 0775, true);

if (!is_dir($legacy)) {
  echo json_encode(['ok'=>true, 'migrated'=>0, 'message'=>'No legacy /state/history dir']); exit;
}

$files = array_values(array_filter(scandir($legacy), function($f){
  return $f !== '.' && $f !== '..';
}));

$count = 0; $moved = []; $errors = [];
foreach ($files as $f) {
  $src = $legacy . '/' . $f;
  $dst = $targetDir . '/' . $f;
  if (!is_file($src)) continue;
  if (@rename($src, $dst)) { $count++; $moved[] = $f; }
  else { $errors[] = $f; }
}

echo json_encode(['ok'=>true, 'migrated'=>$count, 'moved'=>$moved, 'errors'=>$errors, 'target'=>$targetDir]);
