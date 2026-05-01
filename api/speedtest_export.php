<?php

require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../lib/Speedtest.php';

require_admin();

try {
    $filters = \App\Speedtest::filterOptions([
        'range' => $_GET['range'] ?? '24h',
        'server' => $_GET['server'] ?? '',
        'include_failed' => (int)($_GET['include_failed'] ?? 0) === 1,
    ]);
    $payload = \App\Speedtest::buildPayload($filters);
    $filename = 'speedtest_' . preg_replace('/[^a-z0-9]+/i', '_', $filters['range']) . '_' . gmdate('Ymd_His') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $fh = fopen('php://output', 'w');
    fputcsv($fh, array_keys(\App\Speedtest::csvRows($payload['rows'])[0] ?? [
        'timestamp' => '',
        'status' => '',
        'backend' => '',
        'server_id' => '',
        'server_name' => '',
        'server_location' => '',
        'ping_ms' => '',
        'jitter_ms' => '',
        'download_mbps' => '',
        'upload_mbps' => '',
        'packet_loss' => '',
        'duration_ms' => '',
        'error_message' => '',
        'raw_tool_version' => '',
    ]));
    foreach (\App\Speedtest::csvRows($payload['rows']) as $row) {
        fputcsv($fh, $row);
    }
    fclose($fh);
} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
