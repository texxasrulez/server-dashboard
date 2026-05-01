<?php

require_once __DIR__ . "/../includes/init.php";
require_once __DIR__ . "/../includes/auth.php";
require_once __DIR__ . "/../includes/paths.php";
require_once __DIR__ . "/../lib/RoundDavBookmarks.php";
require_admin();
header("Content-Type: application/json");
if (!csrf_check_request()) {
    http_response_code(403);
    echo json_encode(["error" => "CSRF failed"]);
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
    try {
        RoundDavBookmarks::deleteBookmark((string) $id);
        echo json_encode(
            ["ok" => true, "source" => "rounddav"],
            JSON_UNESCAPED_SLASHES,
        );
    } catch (Throwable $e) {
        http_response_code(422);
        echo json_encode([
            "error" => $e->getMessage(),
            "source" => "rounddav",
        ]);
    }
    exit();
}

$dataDir = __DIR__ . "/../data";
@mkdir($dataDir, 0775, true);
$file = $dataDir . "/bookmarks.json";
if (!file_exists($file)) {
    write_json_atomic($file, ["items" => []]);
}
$payload = json_decode(@file_get_contents($file), true);
$items =
    isset($payload["items"]) && is_array($payload["items"])
        ? $payload["items"]
        : [];

$items = array_values(
    array_filter($items, function ($it) use ($id) {
        return ($it["id"] ?? "") !== $id;
    }),
);

write_json_atomic($file, ["items" => $items]);

echo json_encode(["ok" => true, "source" => "local"], JSON_UNESCAPED_SLASHES);
