<?php

require_once __DIR__ . "/../includes/init.php";
require_once __DIR__ . "/../includes/auth.php";
require_once __DIR__ . "/../includes/paths.php";
header("Content-Type: application/json");
require_admin();
$csrf = $_GET["_csrf"] ?? ($_POST["_csrf"] ?? "");
if (!csrf_check($csrf)) {
    http_response_code(403);
    echo json_encode(["error" => "CSRF failed"]);
    exit();
}
$payload = json_decode(file_get_contents("php://input"), true);
$interval = (int) ($payload["interval"] ?? 60);
if ($interval < 5) {
    $interval = 60;
}
$cfgPath = __DIR__ . "/../config/index_refresh.json";
@mkdir(dirname($cfgPath), 0775, true);
write_json_atomic($cfgPath, ["interval" => $interval]);
echo json_encode(["ok" => true, "interval" => $interval]);
