<?php
// api/cron_mark.php — token-protected write of last-run timestamps by cron
require __DIR__.'/_guard.php'; guard_api(['key'=>'cron_mark','require_token'=>true,'type'=>'json']);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/_state_path.php';

$what = isset($_GET['what']) ? strtolower($_GET['what']) : '';
if (!in_array($what, ['alerts','history'], true)) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'invalid what']); exit;
}
$map = ['alerts'=>'cron_last_alert.txt', 'history'=>'cron_last_history.txt'];
$primary = dashboard_state_path($map[$what]);
$legacy_state = dirname(__DIR__) . '/state'; @mkdir($legacy_state, 0775, true);
$legacy = $legacy_state . '/' . $map[$what];
$ts = time();
$ok1 = @file_put_contents($primary, (string)$ts) !== false;
$ok2 = @file_put_contents($legacy,  (string)$ts) !== false;
$ok = $ok1 || $ok2;
echo json_encode(['ok'=>$ok, 'what'=>$what, 'ts'=>$ts]);
