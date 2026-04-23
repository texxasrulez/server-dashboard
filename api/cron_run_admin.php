<?php

require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../lib/CronRunners.php';

require_admin();
header('Content-Type: application/json; charset=utf-8');

if ((string)($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!csrf_check_request()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'CSRF failed']);
    exit;
}

$kind = strtolower(trim((string)($_GET['kind'] ?? $_POST['kind'] ?? '')));
if (!in_array($kind, ['alerts', 'history'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid kind']);
    exit;
}

$result = dashboard_alerts_eval_run($kind === 'history', false);
$status = (int)($result['status'] ?? 200);
unset($result['status']);

if (empty($result['ok'])) {
    http_response_code($status > 0 ? $status : 500);
    echo json_encode($result, JSON_UNESCAPED_SLASHES);
    exit;
}

$ts = isset($result['now']) ? (int)$result['now'] : time();
echo json_encode(
    [
        'ok' => true,
        'kind' => $kind,
        'ts' => $ts,
    ] + $result,
    JSON_UNESCAPED_SLASHES
);
