<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/paths.php';
require_once __DIR__ . '/../includes/logger.php';
header('Content-Type: application/json');

$dataPath = DATA_DIR . '/services.json';
ensure_dir(DATA_DIR);
if (!file_exists($dataPath)) write_json_atomic($dataPath, ['items'=>[]]);

$payload = json_decode(file_get_contents('php://input'), true);
if (!$payload) { http_response_code(400); echo json_encode(['error'=>'Invalid JSON']); exit; }

$id      = $payload['id']      ?? null;
$name    = trim($payload['name'] ?? '');
$type    = strtolower(trim($payload['type'] ?? 'other'));
$host    = trim($payload['host'] ?? '');
$port    = (int)($payload['port'] ?? 0);
$check   = strtolower(trim($payload['check'] ?? 'tcp'));
$path    = trim($payload['path'] ?? '/');
$timeout = (int)($payload['timeout_ms'] ?? 800);
$enabled = !empty($payload['enabled']);

// --- Validation ---
$errors = [];
if ($name === '') { $errors[] = 'Name is required.'; }
$is_ip  = filter_var($host, FILTER_VALIDATE_IP) !== false;
$is_dns = (bool)filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);
if (!$is_ip && !$is_dns && strtolower($host) !== 'localhost') {
  $errors[] = 'Host must be IPv4/IPv6 or a hostname.';
}
if ($port < 1 || $port > 65535) { $errors[] = 'Port must be 1–65535.'; }
if (!in_array($check, ['tcp','http','ping'], true)) { $check = 'tcp'; }
if ($check !== 'http') { $path = '/'; }
if ($check === 'http') {
  if ($path === '') $path = '/';
  if ($path[0] !== '/') $path = '/' . $path;
}
if (!empty($errors)) {
  http_response_code(422);
  echo json_encode(['error'=>implode(' ', $errors)]);
  exit;
}
if (!in_array($type, ['app','db','mail','cache','other'], true)) $type = 'other';

$store = json_decode(@file_get_contents($dataPath), true);
if (!is_array($store)) $store = ['items'=>[]];
$items =& $store['items'];

$entry = [
  'id' => $id ?: ('svc_'.bin2hex(random_bytes(6))),
  'name' => $name,
  'type' => $type,
  'host' => $host,
  'port' => $port,
  'check'=> $check,
  'path' => $path,
  'timeout_ms'=> $timeout,
  'enabled'=> $enabled ? true : false
];

$found = false;
foreach ($items as &$it) {
  if (($it['id'] ?? null) === $entry['id']) { $it = $entry; $found = true; break; }
}
unset($it);
if (!$found) $items[] = $entry;

write_json_atomic($dataPath, $store);
echo json_encode(['ok'=>true,'item'=>$entry,'items'=>$items], JSON_UNESCAPED_SLASHES);
