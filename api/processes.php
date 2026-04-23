<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../lib/Api/ProcessesApi.php';
require_login();

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');

$response = proc_api_response($_GET);
http_response_code((int) ($response["status"] ?? 200));
echo json_encode($response["payload"] ?? ["ok" => false, "error" => "processes api failed"], JSON_UNESCAPED_SLASHES);
