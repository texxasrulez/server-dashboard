<?php
require_once __DIR__ . '/../includes/init.php';
header('Content-Type: application/json');

$id = $_POST['id'] ?? ($_GET['id'] ?? '');
if (!$id) { http_response_code(400); echo json_encode(['error'=>'Missing id']); exit; }

$dataPath = __DIR__ . '/../data/services.json';
if (!file_exists($dataPath)) { http_response_code(404); echo json_encode(['error'=>'No data']); exit; }
$store = json_decode(file_get_contents($dataPath), true) ?: ['items'=>[]];
$items =& $store['items'];

$updated = null;
foreach ($items as &$it) {
  if (($it['id'] ?? '') === $id) {
    $it['enabled'] = empty($it['enabled']) ? true : false;
    $updated = $it; break;
  }
}
unset($it);

if (!$updated) { http_response_code(404); echo json_encode(['error'=>'Not found']); exit; }

file_put_contents($dataPath, json_encode($store, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), LOCK_EX);
echo json_encode(['ok'=>true,'item'=>$updated]);
