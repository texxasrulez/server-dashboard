<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/auth.php';
require_admin();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error'=>'Method not allowed']); exit; }

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!$body) $body = $_POST;

$action = $body['action'] ?? null;
$ids    = $body['ids'] ?? [];
if (!$action || !is_array($ids) || !count($ids)) { http_response_code(422); echo json_encode(['error'=>'action and ids required']); exit; }

$dataPath = __DIR__ . '/../data/alerts.json';
@mkdir(dirname($dataPath), 0775, true);
if (!file_exists($dataPath)) file_put_contents($dataPath, json_encode(['items'=>[]], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
$payload = json_decode(@file_get_contents($dataPath), true);
$items = isset($payload['items']) && is_array($payload['items']) ? $payload['items'] : [];

$now = time();
$changed = false;

foreach ($items as &$it) {
  if (!isset($it['id'])) continue;
  if (!in_array($it['id'], $ids, true)) continue;
  if ($action === 'enable')  { $it['enabled'] = true;  $changed = true; }
  if ($action === 'disable') { $it['enabled'] = false; $changed = true; }
  if ($action === 'delete')  { $it['__delete'] = true; $changed = true; }
  if ($action === 'silence') {
    $mins = (int)($body['silence_minutes'] ?? 60);
    $it['silenced_until'] = $now + max(1,$mins)*60;
    $changed = true;
  }
}
unset($it);
if ($changed) {
  if ($action === 'delete') {
    $items = array_values(array_filter($items, function($x){ return empty($x['__delete']); }));
  }
  $tmp = $dataPath.'.tmp';
  file_put_contents($tmp, json_encode(['items'=>$items], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), LOCK_EX);
  @rename($tmp, $dataPath);
}

echo json_encode(['ok'=>true, 'count'=>count($ids)]);
