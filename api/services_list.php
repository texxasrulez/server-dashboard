<?php
$__t0 = microtime(true);

require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/paths.php';
header('Content-Type: application/json');

$dataDir = __DIR__ . '/../data';
$stateDir = __DIR__ . '/../state';
@mkdir($dataDir, 0775, true);
$dataPath  = $dataDir . '/services.json';
$statePath = $stateDir . '/services.json';

if (!file_exists($dataPath) && file_exists($statePath)) { @copy($statePath, $dataPath); }
if (!file_exists($dataPath)) { file_put_contents($dataPath, json_encode(['items'=>[]], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)); }

$data = json_decode(@file_get_contents($dataPath), true);
if (!is_array($data)) $data = ['items'=>[]];
$items = $data['items'] ?? [];
if (!is_array($items)) $items = [];

$changed = false;
foreach ($items as &$it) {
  if (!isset($it['id']) || !$it['id']) { $it['id'] = 'svc_'.bin2hex(random_bytes(6)); $changed = true; }
  if (!isset($it['type'])) { $it['type'] = 'other'; $changed = true; }
  if (!isset($it['check'])) { $it['check'] = 'tcp'; $changed = true; }
  if (!isset($it['path'])) { $it['path'] = '/'; $changed = true; }
  if (!isset($it['timeout_ms'])) { $it['timeout_ms'] = 800; $changed = true; }
  if (!isset($it['enabled'])) { $it['enabled'] = true; $changed = true; }
}
unset($it);
if ($changed) file_put_contents($dataPath, json_encode(['items'=>$items], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), LOCK_EX);

echo json_encode(['items'=>$items], JSON_UNESCAPED_SLASHES);
