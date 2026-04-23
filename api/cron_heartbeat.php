<?php

require __DIR__ . '/_guard.php';
guard_api(['key' => 'cron_heartbeat', 'require_token' => true, 'type' => 'json']);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../lib/CronRunners.php';

$tsParam = isset($_GET['ts']) ? (int)$_GET['ts'] : null;
$result = dashboard_cron_heartbeat_run(
    (string)($_GET['id'] ?? $_GET['job'] ?? ''),
    (string)($_GET['heartbeat'] ?? ''),
    $tsParam,
);

if (!$result['ok']) {
    http_response_code((int)($result['status'] ?? 400));
}
unset($result['status']);
echo json_encode($result);
