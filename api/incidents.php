<?php

declare(strict_types=1);

require_once __DIR__ . "/../includes/init.php";
require_once __DIR__ . "/../includes/auth.php";
require_once __DIR__ . "/../lib/IncidentManager.php";
require_admin();
header("Content-Type: application/json; charset=utf-8");

$id = trim((string) ($_GET["id"] ?? ""));
if ($id !== "") {
    $incident = IncidentManager::get($id);
    if ($incident === null) {
        http_response_code(404);
        echo json_encode(["ok" => false, "error" => "Incident not found"]);
        exit();
    }
    echo json_encode(["ok" => true, "incident" => $incident], JSON_UNESCAPED_SLASHES);
    exit();
}

echo json_encode(["ok" => true, "items" => IncidentManager::recent(40)], JSON_UNESCAPED_SLASHES);
