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

$id = $_POST["id"] ?? ($_GET["id"] ?? null);
if (!$id) {
    http_response_code(422);
    echo json_encode(["error" => "id required"]);
    exit();
}

$source = strtolower(
    trim((string) ($_POST["source"] ?? ($_GET["source"] ?? "local"))),
);
if ($source === "rounddav") {
    http_response_code(422);
    echo json_encode([
        "error" =>
            "RoundDAV folder management is not available from this page yet.",
    ]);
    exit();
}

if (!csrf_check_request()) {
    http_response_code(403);
    echo json_encode(["error" => "CSRF failed"]);
    exit();
}

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
$items = array_values(
    array_filter($items, function ($x) use ($id) {
        return ($x["id"] ?? "") !== $id;
    }),
);

$bmFile = $dataDir . "/bookmarks.json";
if (file_exists($bmFile)) {
    $bmPayload = json_decode(@file_get_contents($bmFile), true);
    $bmItems =
        isset($bmPayload["items"]) && is_array($bmPayload["items"])
            ? $bmPayload["items"]
            : [];
    for ($i = 0; $i < count($bmItems); $i++) {
        if (($bmItems[$i]["category_id"] ?? "") === $id) {
            unset($bmItems[$i]["category_id"]);
        }
    }
    write_json_atomic($bmFile, ["items" => $bmItems]);
}

write_json_atomic($file, ["items" => $items]);

echo json_encode(["ok" => true], JSON_UNESCAPED_SLASHES);
