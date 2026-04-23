<?php

require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../lib/Speedtest.php';

require_admin();
header('Content-Type: application/json; charset=utf-8');

try {
    $filters = \App\Speedtest::filterOptions([
        'range' => $_GET['range'] ?? '24h',
        'server' => $_GET['server'] ?? '',
        'include_failed' => (int)($_GET['include_failed'] ?? 0) === 1,
    ]);
    $payload = \App\Speedtest::buildPayload($filters);
    echo json_encode([
        'ok' => true,
        'filters' => $payload['filters'],
        'summary' => $payload['summary'],
        'charts' => $payload['charts'],
        'rows' => $payload['rows'],
        'servers' => $payload['servers'],
        'invalid_lines' => $payload['invalid_lines'],
    ], JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
