<?php
require_once __DIR__ . '/../includes/init.php';
header('Content-Type: application/json');

$dataPath = __DIR__ . '/../data/services.json';
if (!file_exists($dataPath)) { echo json_encode(['ids'=>[], 'names'=>[], 'triples'=>[]]); exit; }
$store = json_decode(file_get_contents($dataPath), true);
$items = is_array($store) ? ($store['items'] ?? []) : [];
$ids = []; $names = []; $triples = [];
foreach ($items as $it){
  if (!empty($it['enabled'])){
    $id   = $it['id']   ?? null;
    $name = isset($it['name']) ? strtolower(trim($it['name'])) : null;
    $host = isset($it['host']) ? strtolower(trim($it['host'])) : '';
    $port = (string)($it['port'] ?? '');
    if ($id)   $ids[] = $id;
    if ($name) $names[] = $name;
    $triples[] = ($name?:'').'|'.$host.'|'.$port;
  }
}
echo json_encode(['ids'=>$ids,'names'=>$names,'triples'=>$triples], JSON_UNESCAPED_SLASHES);
