<?php

require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../lib/Speedtest.php';

require_admin();
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method not allowed']);
    exit;
}

$csrf = csrf_request_token();
if (!csrf_check($csrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'CSRF']);
    exit;
}

function speedtest_finish_response(array $payload): void
{
    ignore_user_abort(true);
    @set_time_limit(0);
    if (function_exists('session_write_close')) {
        @session_write_close();
    }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        return;
    }
    @ob_flush();
    @flush();
}

try {
    speedtest_finish_response([
        'ok' => true,
        'queued' => true,
        'ran' => false,
        'message' => 'Speedtest run started',
    ]);
    \App\Speedtest::runCollector(['force' => true]);
    exit;
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
