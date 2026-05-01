<?php

require_once __DIR__ . "/../includes/init.php";
require_once __DIR__ . "/../includes/auth.php";
require_once __DIR__ . "/../includes/paths.php";
require_admin();
header("Content-Type: application/json");
$payload = json_decode(file_get_contents("php://input"), true);
if (
    !csrf_check_request(
        (string) ($payload["_csrf"] ?? ($payload["csrf"] ?? "")),
    )
) {
    http_response_code(403);
    echo json_encode(["error" => "CSRF failed"]);
    exit();
}
$enabled = !empty($payload["enabled"]);
$interval = (int) ($payload["interval"] ?? 60);
if ($interval < 5) {
    $interval = 60;
}
$cfgPath = __DIR__ . "/../config/autoprobe.json";
@mkdir(dirname($cfgPath), 0775, true);
write_json_atomic($cfgPath, ["enabled" => $enabled, "interval" => $interval]);
echo json_encode([
    "ok" => true,
    "enabled" => $enabled,
    "interval" => $interval,
]);
