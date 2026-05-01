<?php

// api/cron_mark.php — token-protected write of last-run timestamps by cron
require __DIR__ . '/_guard.php';
guard_api(['key' => 'cron_mark', 'require_token' => true, 'type' => 'json']);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../lib/CronRunners.php';

$result = dashboard_cron_mark_run((string)($_GET['what'] ?? ''));
if (!$result['ok']) {
    http_response_code((int)($result['status'] ?? 400));
}
unset($result['status']);
echo json_encode($result);
