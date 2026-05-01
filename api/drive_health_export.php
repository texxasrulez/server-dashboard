<?php

require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../lib/NvmeHealth.php';

require_admin();

try {
    $filters = \App\NvmeHealth::filterOptions([
        'range' => $_GET['range'] ?? '24h',
    ]);
    $payload = \App\NvmeHealth::historyPayload($filters);
    $format = strtolower(trim((string) ($_GET['format'] ?? 'json')));
    $base = 'drive_health_' . preg_replace('/[^a-z0-9]+/i', '_', $filters['range']) . '_' . gmdate('Ymd_His');

    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $base . '.csv"');
        $rows = \App\NvmeHealth::csvRows($payload['history']);
        $fh = fopen('php://output', 'w');
        fputcsv($fh, array_keys($rows[0] ?? [
            'recorded_at' => '',
            'device' => '',
            'label' => '',
            'serial' => '',
            'available' => '',
            'model' => '',
            'critical_warning' => '',
            'percentage_used' => '',
            'power_on_hours' => '',
            'data_units_written' => '',
            'data_units_written_bytes' => '',
            'temperature_c' => '',
            'media_and_data_integrity_errors' => '',
            'error_information_log_entries' => '',
            'error' => '',
        ]));
        foreach ($rows as $row) {
            fputcsv($fh, $row);
        }
        fclose($fh);
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $base . '.json"');
    echo json_encode(['ok' => true] + $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
