<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/auth.php';
require_admin();
header('Content-Type: application/json');

$id = $_GET['id'] ?? $_POST['id'] ?? null;
if (!$id) { http_response_code(422); echo json_encode(['error'=>'id required']); exit; }

$dataDir  = __DIR__ . '/../data';
$stateDir = __DIR__ . '/../state';
@mkdir($dataDir, 0775, true);
@mkdir($stateDir, 0775, true);
$dataPath  = $dataDir . '/alerts.json';
$statePath = $stateDir . '/alerts.json';

if (!file_exists($dataPath) && file_exists($statePath)) { @copy($statePath, $dataPath); }
if (!file_exists($dataPath)) { file_put_contents($dataPath, json_encode(['items'=>[]], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)); }

$payload = json_decode(@file_get_contents($dataPath), true);
$items = isset($payload['items']) && is_array($payload['items']) ? $payload['items'] : [];

$items = array_values(array_filter($items, function($it) use ($id){
  return !isset($it['id']) || $it['id'] !== $id;
}));

$tmp = $dataPath.'.tmp';
file_put_contents($tmp, json_encode(['items'=>$items], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), LOCK_EX);
@rename($tmp, $dataPath);

echo json_encode(['ok'=>true]);
