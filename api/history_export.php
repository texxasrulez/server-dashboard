<?php

require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/_state_path.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../lib/IncidentManager.php';
header('Content-Type: application/json');

// Admin session OR token (same rules as alerts_eval)
$ok = false;
if (!empty($_SESSION['user']) && (($_SESSION['user']['role'] ?? '') === 'admin')) {
    $ok = true;
}
$given = cron_request_token();
if (!$ok && cron_token_is_valid($given)) {
    $ok = true;
}
if (!$ok) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

$type = $_GET['type'] ?? 'probes';
$start = isset($_GET['start']) ? (int)$_GET['start'] : 0;
$end   = isset($_GET['end']) ? (int)$_GET['end'] : 0;
$limitMax = 200000;
$limit = isset($_GET['limit']) ? max(1, min($limitMax, (int)$_GET['limit'])) : 10000;
$service_id = $_GET['service_id'] ?? null;
$format = $_GET['format'] ?? 'json'; // 'json' or 'csv'
$perService = ($type !== 'alerts') && (!empty($_GET['per_service']) || !empty($_GET['per_service_limit']));
$perServiceLimit = isset($_GET['per_service_limit']) ? (int)$_GET['per_service_limit'] : $limit;
$perServiceLimit = max(10, min(500000, $perServiceLimit));

$file = null;
$fields = [];
if ($type === 'alerts') {
    $file = dashboard_state_path('alerts_events.jsonl');
    $fields = ['ts','alert_id','service_id','service_name','metric','op','threshold','value','severity','name'];
} else {
    $file = dashboard_state_path('services_status_history.jsonl');
    $fields = ['ts','id','name','host','port','check','status','latency_ms','http_code'];
}

// Optional service name map
$servicesFile = __DIR__ . '/../data/services.json';
$svcMap = [];
if (is_file($servicesFile)) {
    $svc = json_decode(@file_get_contents($servicesFile), true);
    if (isset($svc['items']) && is_array($svc['items'])) {
        foreach ($svc['items'] as $s) {
            if (!empty($s['id'])) {
                $svcMap[$s['id']] = $s;
            }
        }
    }
}

$out = [];
$grouped = [];
$alertIncidentMap = $type === 'alerts' ? \IncidentManager::alertIncidentMap() : [];
if (is_file($file)) {
    $fh = @fopen($file, 'rb');
    if ($fh) {
        $count = 0;
        while (!feof($fh)) {
            $line = fgets($fh);
            if ($line === false) {
                break;
            }
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $obj = json_decode($line, true);
            if (!is_array($obj)) {
                continue;
            }
            // normalize ts
            $obj['ts'] = isset($obj['ts']) ? (int)$obj['ts'] : 0;
            if ($start && $obj['ts'] < $start) {
                continue;
            }
            if ($end && $obj['ts'] > $end) {
                continue;
            }
            if ($service_id) {
                $sid = $obj['service_id'] ?? ($obj['id'] ?? null);
                if ($sid !== $service_id) {
                    continue;
                }
            }
            if ($type !== 'alerts') {
                $sid = $obj['id'] ?? ($obj['service_id'] ?? null);
                if ($sid && isset($svcMap[$sid])) {
                    $obj['name'] = $svcMap[$sid]['name'] ?? ($svcMap[$sid]['host'] ?? $obj['name'] ?? '');
                }
                if ($perService) {
                    if (!$sid) {
                        $sid = 'unknown';
                    }
                    if (!isset($grouped[$sid])) {
                        $grouped[$sid] = [];
                    }
                    $grouped[$sid][] = $obj;
                    if ($perServiceLimit && count($grouped[$sid]) > $perServiceLimit) {
                        array_shift($grouped[$sid]);
                    }
                    continue;
                }
            }
            if ($type === 'alerts') {
                $key = implode('|', [
                    (string)($obj['service_id'] ?? ''),
                    (string)($obj['alert_name'] ?? ''),
                    (string)($obj['ts'] ?? 0),
                ]);
                if (isset($alertIncidentMap[$key])) {
                    $obj = array_merge($obj, $alertIncidentMap[$key]);
                }
            }
            $out[] = $obj;
            if (!$perService && count($out) > $limit) {
                array_shift($out);
            }
        }
        fclose($fh);
    }
}

if ($perService && $grouped) {
    $out = [];
    foreach ($grouped as $sid => $items) {
        foreach ($items as $item) {
            $out[] = $item;
        }
    }
}

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="history_export.csv"');
    $f = fopen('php://output', 'w');
    fputcsv($f, $fields);
    foreach ($out as $row) {
        $line = [];
        foreach ($fields as $k) {
            $line[] = is_scalar($row[$k] ?? null) ? $row[$k] : json_encode($row[$k] ?? null);
        }
        fputcsv($f, $line);
    }
    fclose($f);
    exit;
}

echo json_encode(['items' => $out], JSON_UNESCAPED_SLASHES);
