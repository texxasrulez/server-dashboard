<?php

require_once __DIR__ . '/../lib/CronRunners.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

if (!dashboard_alerts_eval_is_authorized()) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

$result = dashboard_alerts_eval_run(
    ((isset($_GET['probe']) && $_GET['probe'] == '1') || (isset($_POST['probe']) && $_POST['probe'] == '1')),
    ((isset($_GET['dry']) && $_GET['dry'] == '1') || (isset($_POST['dry']) && $_POST['dry'] == '1')),
);

if (!$result['ok']) {
    http_response_code((int)($result['status'] ?? 500));
}
unset($result['status']);
echo json_encode($result, JSON_UNESCAPED_SLASHES);
