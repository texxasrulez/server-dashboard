<?php

require_once __DIR__ . "/../includes/init.php";
require_once __DIR__ . "/../includes/auth.php";
require_once __DIR__ . "/../includes/paths.php";
require_once __DIR__ . "/../lib/RoundDavBookmarks.php";
require_admin();
header("Content-Type: application/json");

$source = strtolower(trim((string) ($_GET["source"] ?? "local")));
if ($source === "rounddav") {
    try {
        $result = RoundDavBookmarks::listBookmarks();
        echo json_encode(
            [
                "items" => $result["items"],
                "source" => "rounddav",
                "visibility" => $result["visibility"],
                "capabilities" => [
                    "categories" => true,
                    "category_mutations" => false,
                ],
            ],
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
echo json_encode(
    [
        "items" => $items,
        "source" => "local",
        "capabilities" => [
            "categories" => true,
            "category_mutations" => true,
        ],
    ],
    JSON_UNESCAPED_SLASHES,
);
