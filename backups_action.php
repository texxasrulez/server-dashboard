<?php

// backups_action.php
// JSON API for dashboard backup buttons

require_once __DIR__.'/includes/init.php';
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/lib/Backups/BackupActionService.php';
require_login();

header('Content-Type: application/json');

set_exception_handler(function (Throwable $e): void {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }
    echo json_encode([
        'ok' => false,
        'error' => 'Unhandled server exception in backups_action.php',
        'detail' => $e->getMessage(),
    ]);
    exit;
});

register_shutdown_function(function (): void {
    $err = error_get_last();
    if (!$err) {
        return;
    }
    $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($err['type'] ?? 0, $fatal, true)) {
        return;
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }
    echo json_encode([
        'ok' => false,
        'error' => 'Fatal server error in backups_action.php',
        'detail' => (string)($err['message'] ?? 'unknown'),
    ]);
});

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

// --- CSRF check: header or form field ---
$csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? '');
if (!csrf_check($csrf_token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'CSRF failed']);
    exit;
}

$action = isset($_POST['action']) ? trim((string) $_POST['action']) : '';
$response = \App\Backups\BackupActionService::handle(__DIR__, $action);
http_response_code((int) ($response['status'] ?? 200));
echo json_encode($response['payload'] ?? ['ok' => false, 'error' => 'Unknown backup action error']);
