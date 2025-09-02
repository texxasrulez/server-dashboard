<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/paths.php';
header('Content-Type: application/json');

$dataPath = __DIR__ . '/../data/services.json';
@mkdir(dirname($dataPath), 0775, true);
if (!file_exists($dataPath)) file_put_contents($dataPath, json_encode(['items'=>[]], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));

$raw = file_get_contents('php://input');
$format = strtolower($_GET['format'] ?? '');
$payload = null;

if ($format === 'csv') {
  $rows = array_map('str_getcsv', preg_split('/\r?\n/', trim($raw)));
  if (!$rows || count($rows) < 2) { http_response_code(400); echo json_encode(['error'=>'CSV has no rows']); exit; }
  $hdr = array_map('strtolower', array_shift($rows));
  $items = [];
  foreach ($rows as $r) {
    if (count($r) === 1 && $r[0] === '') continue;
    $row = array_combine($hdr, $r);
    $row['enabled'] = !empty($row['enabled']);
    $row['port'] = (int)($row['port'] ?? 0);
    $row['timeout_ms'] = (int)($row['timeout_ms'] ?? 800);
    $items[] = $row;
  }
  $payload = ['items'=>$items];
} else {
  $payload = json_decode($raw, true);
}

if (!$payload || !isset($payload['items']) || !is_array($payload['items'])) {
  http_response_code(400);
  echo json_encode(['error'=>'Invalid import payload']);
  exit;
}

$store = json_decode(file_get_contents($dataPath), true);
if (!is_array($store)) $store = ['items'=>[]];
$items =& $store['items'];

foreach ($payload['items'] as $it) {
  if (!isset($it['id']) || !$it['id']) $it['id'] = 'svc_'.bin2hex(random_bytes(6));
  if (!isset($it['type'])) $it['type'] = 'other';
  if (!isset($it['check'])) $it['check'] = 'tcp';
  if (!isset($it['path'])) $it['path'] = '/';
  if (!isset($it['timeout_ms'])) $it['timeout_ms'] = 800;
  if (!isset($it['enabled'])) $it['enabled'] = true;

  $found = false;
  foreach ($items as &$cur) {
    if (($cur['id']??'') === $it['id'] ||
        (strcasecmp($cur['name']??'', $it['name']??'')===0 && ($cur['host']??'')===($it['host']??'') && (int)($cur['port']??0)===(int)($it['port']??0))) {
      $cur = $it; $found = true; break;
    }
  }
  unset($cur);
  if (!$found) $items[] = $it;
}

file_put_contents($dataPath, json_encode($store, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), LOCK_EX);
echo json_encode(['ok'=>true,'imported'=>count($payload['items']),'items'=>$items]);
