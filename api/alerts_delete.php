<?php

require_once __DIR__ . "/../includes/init.php";
require_once __DIR__ . "/../includes/auth.php";
require_once __DIR__ . "/../includes/paths.php";
require_once __DIR__ . "/_alerts_store.php";
require_admin();
header("Content-Type: application/json");
if (!csrf_check_request()) {
    http_response_code(403);
    echo json_encode(["error" => "CSRF failed"]);
    exit();
}

$id = $_GET["id"] ?? ($_POST["id"] ?? null);
if (!$id) {
    http_response_code(422);
    echo json_encode(["error" => "id required"]);
    exit();
}

$dataDir = __DIR__ . "/../data";
$stateDir = __DIR__ . "/../state";
@mkdir($dataDir, 0775, true);
@mkdir($stateDir, 0775, true);
$dataPath = $dataDir . "/alerts.json";
$statePath = $stateDir . "/alerts.json";

if (!file_exists($dataPath) && file_exists($statePath)) {
    @copy($statePath, $dataPath);
}
if (!file_exists($dataPath)) {
    write_json_atomic($dataPath, ["items" => []]);
}

$store = alerts_store_read($dataPath);
$payload = $store["payload"];
$items = $store["items"];

$items = array_values(
    array_filter($items, function ($it) use ($id) {
        return !isset($it["id"]) || $it["id"] !== $id;
    }),
);

alerts_store_write($dataPath, $payload, $items);

echo json_encode(["ok" => true]);
