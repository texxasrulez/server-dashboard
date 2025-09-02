<?php
require_once __DIR__ . '/../includes/init.php';
header('Content-Type: application/json');

$dataDir = __DIR__ . '/../data';
@mkdir($dataDir, 0775, true);
$file = $dataDir . '/bookmarks_categories.json';
if (!file_exists($file)) {
  file_put_contents($file, json_encode(['items'=>[]], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
}
$payload = json_decode(@file_get_contents($file), true);
$items = isset($payload['items']) && is_array($payload['items']) ? $payload['items'] : [];
usort($items, function($a,$b){
  $sa = isset($a['sort']) ? intval($a['sort']) : 0;
  $sb = isset($b['sort']) ? intval($b['sort']) : 0;
  if ($sa === $sb) return strcmp(strval($a['name']??''), strval($b['name']??''));
  return $sa - $sb;
});
echo json_encode(['items'=>$items], JSON_UNESCAPED_SLASHES);
