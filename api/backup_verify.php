<?php

declare(strict_types=1);

require_once __DIR__ . "/../includes/init.php";
require_once __DIR__ . "/../includes/auth.php";
require_once __DIR__ . "/../lib/BackupVerifier.php";
require_admin();
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (!csrf_check_request()) {
        http_response_code(403);
        echo json_encode(["ok" => false, "error" => "CSRF failed"]);
        exit();
    }
    echo json_encode(["ok" => true, "result" => BackupVerifier::verifyNow()], JSON_UNESCAPED_SLASHES);
    exit();
}

echo json_encode([
    "ok" => true,
    "latest" => BackupVerifier::latest(),
    "history" => BackupVerifier::history(12),
], JSON_UNESCAPED_SLASHES);
