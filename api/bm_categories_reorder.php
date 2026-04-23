<?php

require_once __DIR__ . "/../includes/init.php";
require_once __DIR__ . "/../includes/auth.php";
require_once __DIR__ . "/../includes/paths.php";
header("Content-Type: application/json");

$admin_ok = false;
if (function_exists("user_is_admin")) {
    $admin_ok = user_is_admin();
} elseif (function_exists("is_admin")) {
    $admin_ok = is_admin();
} elseif (
    !empty($_SESSION["user"]) &&
    ($_SESSION["user"]["role"] ?? "") === "admin"
) {
    $admin_ok = true;
}
if (!$admin_ok) {
    http_response_code(403);
    echo json_encode(["error" => "forbidden"]);
    exit();
}

$raw = file_get_contents("php://input");
$body = json_decode($raw, true);
if (!is_array($body)) {
    $body = [];
}

$source = strtolower(trim((string) ($body["source"] ?? "local")));
if ($source === "rounddav") {
    http_response_code(422);
    echo json_encode([
        "error" =>
            "RoundDAV folder reordering is not available from this page yet.",
    ]);
    exit();
}

if (!csrf_check_request((string) ($body["_csrf"] ?? ($body["csrf"] ?? "")))) {
    http_response_code(403);
    echo json_encode(["error" => "CSRF failed"]);
    exit();
}
$ids = isset($body["ids"]) && is_array($body["ids"]) ? $body["ids"] : [];

$dataDir = __DIR__ . "/../data";
@mkdir($dataDir, 0775, true);
$file = $dataDir . "/bookmarks_categories.json";
if (!file_exists($file)) {
    write_json_atomic($file, ["items" => []]);
}
$payload = json_decode(@file_get_contents($file), true);
$items =
    isset($payload["items"]) && is_array($payload["items"])
        ? $payload["items"]
        : [];

if ($ids && count($ids) === count($items)) {
    $pos = 1;
    $index = [];
    foreach ($items as $it) {
        $index[$it["id"]] = $it;
    }
    $out = [];
    foreach ($ids as $id) {
        if (!isset($index[$id])) {
            continue;
        }
        $it = $index[$id];
        $it["sort"] = $pos++;
        $out[] = $it;
    }
    $items = $out;
}

write_json_atomic($file, ["items" => $items]);

echo json_encode(["ok" => true], JSON_UNESCAPED_SLASHES);
