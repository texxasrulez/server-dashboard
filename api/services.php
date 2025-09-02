<?php
$__t0 = microtime(true);

// Simple file-backed service registry API
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/paths.php';
header('Content-Type: application/json');

$store = __DIR__ . '/../data/services.json';
if (!file_exists($store)) file_put_contents($store, json_encode(['items'=>[]], JSON_PRETTY_PRINT));

function read_services($store){
  $raw = file_get_contents($store);
  $data = json_decode($raw, true);
  if (!$data || !isset($data['items']) || !is_array($data['items'])) $data = ['items'=>[]];
  return $data;
}
function write_services($store, $data){
  $tmp = $store . '.tmp';
  file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT));
  rename($tmp, $store);
}

$fn = $_GET['fn'] ?? $_POST['fn'] ?? 'list';

if ($fn === 'list') {
  $data = read_services($store);
  echo json_encode($data + ['trace'=>['elapsed_ms'=>round((microtime(true)-$__t0)*1000,1)]]);
  exit;
}

if ($fn === 'upsert') {
  $data = read_services($store);
  $id   = trim($_POST['id'] ?? '');
  $item = [
    'id'      => $id ?: (string)bin2hex(random_bytes(6)),
    'name'    => trim($_POST['name'] ?? ''),
    'type'    => trim($_POST['type'] ?? 'app'),
    'host'    => trim($_POST['host'] ?? '127.0.0.1'),
    'port'    => (int)($_POST['port'] ?? 80),
    'check'   => trim($_POST['check'] ?? 'tcp'),
    'path'    => trim($_POST['path'] ?? ''),
    'enabled' => isset($_POST['enabled']) && $_POST['enabled'] ? true : false,
    'updated' => date('c')
  ];
  // replace or append
  $found = false;
  foreach ($data['items'] as &$svc) {
    if ($svc['id'] === $item['id']) { $svc = $item; $found = true; break; }
  }
  if (!$found) $data['items'][] = $item;
  write_services($store, $data);
  echo json_encode(['ok'=>true, 'id'=>$item['id'], 'trace'=>['elapsed_ms'=>round((microtime(true)-$__t0)*1000,1)]]);
  exit;
}

if ($fn === 'delete') {
  $id = trim($_POST['id'] ?? '');
  $data = read_services($store);
  $data['items'] = array_values(array_filter($data['items'], fn($s) => $s['id'] !== $id));
  write_services($store, $data);
  echo json_encode(['ok'=>true, 'trace'=>['elapsed_ms'=>round((microtime(true)-$__t0)*1000,1)]]);
  exit;
}

http_response_code(400);
echo json_encode(['error'=>'Unknown fn', 'trace'=>['elapsed_ms'=>round((microtime(true)-$__t0)*1000,1)]]);
