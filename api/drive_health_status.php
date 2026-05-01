<?php

require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../lib/NvmeHealth.php';

require_admin();
header('Content-Type: application/json; charset=utf-8');

try {
    echo json_encode(['ok' => true] + \App\NvmeHealth::statusPayload(), JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
