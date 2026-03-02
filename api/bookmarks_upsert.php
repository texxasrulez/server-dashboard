<?php
// api/bookmarks_upsert.php (category-aware)
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/auth.php';
require_admin();
header('Content-Type: application/json');

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body) || !count($body)) { $body = $_POST; }
function v($k,$d=null){ global $body; return isset($body[$k]) ? $body[$k] : $d; }
if (!csrf_check_request((string)($body['_csrf'] ?? $body['csrf'] ?? ''))) { http_response_code(403); echo json_encode(['error'=>'CSRF failed']); exit; }

$id = v('id');
$title = trim((string)v('title',''));
$url = trim((string)v('url',''));
$tags = v('tags', []);
if (is_string($tags)) { $tags = array_values(array_filter(array_map('trim', explode(',', $tags)))); }
if (!is_array($tags)) { $tags = []; }
$category_id = v('category_id', null);
if ($category_id === '') $category_id = null;

if ($url === '') { http_response_code(422); echo json_encode(['error'=>'url required']); exit; }
if (!filter_var($url, FILTER_VALIDATE_URL)) { http_response_code(422); echo json_encode(['error'=>'invalid url']); exit; }
$u = parse_url($url);
$host = isset($u['host']) ? $u['host'] : '';

$dataDir = __DIR__ . '/../data';
@mkdir($dataDir, 0775, true);
$file = $dataDir . '/bookmarks.json';
if (!file_exists($file)) { file_put_contents($file, json_encode(['items'=>[]], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)); }
$payload = json_decode(@file_get_contents($file), true);
$items = isset($payload['items']) && is_array($payload['items']) ? $payload['items'] : [];

$now = time();
if (!$id) { $id = 'bm_'.bin2hex(random_bytes(6)); $created = $now; }
else {
  $created = $now;
  foreach ($items as $it) { if (($it['id'] ?? null) === $id) { $created = $it['created'] ?? $now; break; } }
}

$item = [
  'id'=>$id,
  'title'=>$title ?: ($host ?: $url),
  'url'=>$url,
  'tags'=>$tags,
  'host'=>$host,
  'favicon'=>$host ? ('favicon_proxy.php?host=' . rawurlencode($host) . '&v=' . $now) : '',
  'category_id'=>$category_id,
  'created'=>$created,
  'updated'=>$now
];

$replaced = false;
for ($i=0;$i<count($items);$i++) {
  if (($items[$i]['id'] ?? null) === $id) { $items[$i] = $item; $replaced=true; break; }
}
if (!$replaced) $items[] = $item;

$tmp = $file.'.tmp';
file_put_contents($tmp, json_encode(['items'=>$items], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), LOCK_EX);
@rename($tmp, $file);

echo json_encode(['ok'=>true, 'item'=>$item], JSON_UNESCAPED_SLASHES);
