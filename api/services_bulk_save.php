<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/paths.php';
require_once __DIR__ . '/../includes/logger.php';
header('Content-Type: application/json');

$dataPath = DATA_DIR . '/services.json';
ensure_dir(DATA_DIR);

$payload = json_decode(file_get_contents('php://input'), true);
if (!$payload || !isset($payload['items']) || !is_array($payload['items'])) {
  http_response_code(400);
  echo json_encode(['error'=>'Expected { items: [...] }']); exit;
}

foreach ($payload['items'] as &$it) {
  if (!isset($it['id']) || !$it['id']) $it['id'] = 'svc_'.bin2hex(random_bytes(6));
  $it['name'] = trim($it['name'] ?? '');
  $it['host'] = trim($it['host'] ?? '');
  $it['port'] = (int)($it['port'] ?? 0);
  $it['check']= strtolower($it['check'] ?? 'tcp');
  if (!in_array($it['check'], ['tcp','http','ping'])) $it['check'] = 'tcp';
  if ($it['check']!=='http') $it['path'] = '/';
  $it['enabled'] = !empty($it['enabled']);
}
unset($it);

write_json_atomic($dataPath, ['items'=>$payload['items']]);
echo json_encode(['ok'=>true]);
