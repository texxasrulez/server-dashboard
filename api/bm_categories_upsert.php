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
if (!is_array($body) || !count($body)) {
    $body = $_POST;
}
function v($k, $d = null)
{
    global $body;
    return isset($body[$k]) ? $body[$k] : $d;
}

$source = strtolower(trim((string) v("source", "local")));
if ($source === "rounddav") {
    http_response_code(422);
    echo json_encode([
        "error" =>
            "RoundDAV folder management is not available from this page yet.",
    ]);
    exit();
}

if (!csrf_check_request((string) ($body["_csrf"] ?? ($body["csrf"] ?? "")))) {
    http_response_code(403);
    echo json_encode(["error" => "CSRF failed"]);
    exit();
}

$id = v("id");
$name = trim((string) v("name", ""));
if ($name === "") {
    http_response_code(422);
    echo json_encode(["error" => "name required"]);
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

$now = time();
if (!$id) {
    $id = "cat_" . bin2hex(random_bytes(4));
    $sort = count($items)
        ? max(
                array_map(function ($x) {
                    return intval($x["sort"] ?? 0);
                }, $items),
            ) + 1
        : 1;
    $item = [
        "id" => $id,
        "name" => $name,
        "sort" => $sort,
        "created" => $now,
        "updated" => $now,
    ];
    $items[] = $item;
} else {
    $found = false;
    for ($i = 0; $i < count($items); $i++) {
        if (($items[$i]["id"] ?? "") === $id) {
            $items[$i]["name"] = $name;
            $items[$i]["updated"] = $now;
            $found = true;
            break;
        }
    }
    if (!$found) {
        http_response_code(404);
        echo json_encode(["error" => "not found"]);
        exit();
    }
}

write_json_atomic($file, ["items" => $items]);

echo json_encode(["ok" => true, "id" => $id], JSON_UNESCAPED_SLASHES);
