<?php
require_once __DIR__ . '/_state_path.php';
header('Content-Type: application/json; charset=utf-8');
$paths = [
  'services_status.json' => dashboard_state_path('services_status.json'),
  'services_status_history.jsonl' => dashboard_state_path('services_status_history.jsonl'),
  'alerts_events.jsonl' => dashboard_state_path('alerts_events.jsonl'),
  'cron_last_history.txt' => dashboard_state_path('cron_last_history.txt'),
  'cron_last_alert.txt' => dashboard_state_path('cron_last_alert.txt')
];
$out = [];
foreach ($paths as $k=>$p) {
  $out[$k] = ['path'=>$p, 'exists'=>is_file($p), 'size'=>is_file($p)?filesize($p):0];
}
echo json_encode($out, JSON_UNESCAPED_SLASHES);
