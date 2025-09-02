<?php
require_once __DIR__ . '/../includes/init.php';
header('Content-Type: application/json');

$dataPath = __DIR__ . '/../data/services.json';
if (!file_exists($dataPath)) { echo json_encode(['ok'=>true,'items'=>[]]); exit; }

$id = $_GET['id'] ?? ($_POST['id'] ?? null);
if (!$id) { http_response_code(400); echo json_encode(['error'=>'Missing id']); exit; }

$store = json_decode(@file_get_contents($dataPath), true);
if (!is_array($store)) $store = ['items'=>[]];

$items = array_values(array_filter($store['items'] ?? [], fn($it)=> ($it['id']??'') !== $id));
$store['items'] = $items;
file_put_contents($dataPath, json_encode($store, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), LOCK_EX);
echo json_encode(['ok'=>true,'items'=>$items], JSON_UNESCAPED_SLASHES);
