<?php

declare(strict_types=1);

require_once __DIR__ . "/../includes/init.php";
require_once __DIR__ . "/../includes/auth.php";
require_once __DIR__ . "/../lib/SupportBundle.php";
require_admin();

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    header("Content-Type: application/json; charset=utf-8");
    if (!csrf_check_request()) {
        http_response_code(403);
        echo json_encode(["ok" => false, "error" => "CSRF failed"]);
        exit();
    }

    try {
        $bundle = SupportBundle::build();
        echo json_encode(["ok" => true, "bundle" => $bundle], JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(["ok" => false, "error" => $e->getMessage()]);
    }
    exit();
}

$file = trim((string) ($_GET["file"] ?? ""));
$path = SupportBundle::downloadPath($file);
if ($path === null) {
    http_response_code(404);
    echo "Bundle not found";
    exit();
}

header("Content-Type: application/zip");
header('Content-Disposition: attachment; filename="' . basename($path) . '"');
header("Content-Length: " . (string) filesize($path));
readfile($path);
