<?php
require_once __DIR__ . '/_state_path.php';
header('Content-Type: application/json; charset=utf-8');
$file = dashboard_state_path('services_status_history.jsonl');
$last = 0; $cnt = 0;
if (is_file($file)) {
  $fh = fopen($file, 'rb');
  if ($fh) {
    while (!feof($fh)) {
      $line = fgets($fh);
      if ($line === false) break;
      $cnt++;
      $j = json_decode($line, true);
      if (isset($j['ts'])) { $t = intval($j['ts']); if ($t > $last) $last = $t; }
    }
    fclose($fh);
  }
}
echo json_encode(['file'=>$file, 'exists'=>is_file($file), 'count'=>$cnt, 'last_ts'=>$last, 'last_iso'=>($last?date('c',$last):null)], JSON_UNESCAPED_SLASHES);
