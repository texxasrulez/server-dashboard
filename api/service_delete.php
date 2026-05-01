<?php

require_once __DIR__ . "/../includes/init.php";
require_once __DIR__ . "/../includes/auth.php";
require_once __DIR__ . "/../includes/paths.php";
require_once __DIR__ . "/../lib/AuditLog.php";
require_admin();
header("Content-Type: application/json");
if (!csrf_check_request()) {
    http_response_code(403);
    echo json_encode(["error" => "CSRF failed"]);
    exit();
}

$dataPath = __DIR__ . "/../data/services.json";
if (!file_exists($dataPath)) {
    echo json_encode(["ok" => true, "items" => []]);
    exit();
}

$id = $_GET["id"] ?? ($_POST["id"] ?? null);
if (!$id) {
    http_response_code(400);
    echo json_encode(["error" => "Missing id"]);
    exit();
}

$store = json_decode(@file_get_contents($dataPath), true);
if (!is_array($store)) {
    $store = ["items" => []];
}

$items = array_values(
    array_filter($store["items"] ?? [], fn($it) => ($it["id"] ?? "") !== $id),
);
$store["items"] = $items;
write_json_atomic($dataPath, $store);
AuditLog::record(
    "service.delete",
    (string) $id,
    true,
    ["remaining_items" => count($items)],
    "service delete ok",
    "service",
);
echo json_encode(["ok" => true, "items" => $items], JSON_UNESCAPED_SLASHES);
