<?php

require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/auth.php';
require_admin();
header('Content-Type: application/json');
if (!csrf_check_request()) {
    http_response_code(403);
    echo json_encode(['error' => 'CSRF failed']);
    exit;
}

$id = $_POST['id'] ?? $_GET['id'] ?? null;
if (!$id) {
    http_response_code(422);
    echo json_encode(['error' => 'id required']);
    exit;
}

$dataDir = __DIR__ . '/../data';
@mkdir($dataDir, 0775, true);
$file = $dataDir . '/bookmarks.json';
if (!file_exists($file)) {
    file_put_contents($file, json_encode(['items' => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}
$payload = json_decode(@file_get_contents($file), true);
$items = isset($payload['items']) && is_array($payload['items']) ? $payload['items'] : [];

$items = array_values(array_filter($items, function ($it) use ($id) {
    return ($it['id'] ?? '') !== $id;
}));

$tmp = $file.'.tmp';
file_put_contents($tmp, json_encode(['items' => $items], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
@rename($tmp, $file);

echo json_encode(['ok' => true], JSON_UNESCAPED_SLASHES);
