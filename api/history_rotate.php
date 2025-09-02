<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/auth.php';
require_admin();
require_once __DIR__ . '/_state_path.php';
header('Content-Type: application/json');

$files = [
  dashboard_state_path('services_status_history.jsonl'),
  dashboard_state_path('alerts_events.jsonl'),
];

$ts = date('Ymd_His');
$rotated = [];
foreach ($files as $f) {
  if (!is_file($f)) continue;
  $dst = $f.'.'.$ts;
  if (@rename($f, $dst)) $rotated[] = basename($f);
}
echo json_encode(['ok'=>true, 'rotated'=>$rotated]);
