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
        echo json_encode(
            [
                "items" => RoundDavBookmarks::listFolders(),
                "source" => "rounddav",
                "category_mutations" => false,
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
$file = $dataDir . "/bookmarks_categories.json";
if (!file_exists($file)) {
    write_json_atomic($file, ["items" => []]);
}
$payload = json_decode(@file_get_contents($file), true);
$items =
    isset($payload["items"]) && is_array($payload["items"])
        ? $payload["items"]
        : [];
usort($items, function ($a, $b) {
    $sa = isset($a["sort"]) ? intval($a["sort"]) : 0;
    $sb = isset($b["sort"]) ? intval($b["sort"]) : 0;
    if ($sa === $sb) {
        return strcmp(strval($a["name"] ?? ""), strval($b["name"] ?? ""));
    }
    return $sa - $sb;
});
echo json_encode(
    [
        "items" => $items,
        "source" => "local",
        "category_mutations" => true,
    ],
    JSON_UNESCAPED_SLASHES,
);
