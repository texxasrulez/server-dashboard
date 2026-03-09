<?php

$__t0 = microtime(true);

require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/auth.php';
require_admin();
require_once __DIR__ . '/../includes/paths.php';
require_once __DIR__ . '/_state_path.php';
header('Content-Type: application/json');

$dataDir = __DIR__ . '/../data';
$stateDir = __DIR__ . '/../state';
@mkdir($dataDir, 0775, true);
$dataPath  = $dataDir . '/services.json';
$statePath = $stateDir . '/services.json';

if (!file_exists($dataPath) && file_exists($statePath)) {
    @copy($statePath, $dataPath);
}
if (!file_exists($dataPath)) {
    file_put_contents($dataPath, json_encode(['items' => []], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

$data = json_decode(@file_get_contents($dataPath), true);
if (!is_array($data)) {
    $data = ['items' => []];
}
$items = $data['items'] ?? [];
if (!is_array($items)) {
    $items = [];
}

$changed = false;
foreach ($items as &$it) {
    if (!isset($it['id']) || !$it['id']) {
        $it['id'] = 'svc_'.bin2hex(random_bytes(6));
        $changed = true;
    }
    if (!isset($it['type'])) {
        $it['type'] = 'other';
        $changed = true;
    }
    if (!isset($it['check'])) {
        $it['check'] = 'tcp';
        $changed = true;
    }
    if (!isset($it['path'])) {
        $it['path'] = '/';
        $changed = true;
    }
    if (!isset($it['timeout_ms'])) {
        $it['timeout_ms'] = 800;
        $changed = true;
    }
    if (!isset($it['enabled'])) {
        $it['enabled'] = true;
        $changed = true;
    }
}
unset($it);
if ($changed) {
    file_put_contents($dataPath, json_encode(['items' => $items], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

// --- enrich with runtime metrics / alert metadata ---
$statusMap  = load_service_status_map();
$historyMap = load_service_history_map();
$alertMap   = load_service_alert_meta();

foreach ($items as &$svc) {
    $id = $svc['id'] ?? null;
    if (!$id) {
        continue;
    }
    if (isset($statusMap[$id])) {
        $svc['status_meta'] = $statusMap[$id];
    }
    if (isset($historyMap[$id])) {
        $svc['uptime_meta'] = $historyMap[$id];
    }
    if (isset($alertMap[$id])) {
        $svc['alert_meta'] = $alertMap[$id];
    }
}
unset($svc);

echo json_encode(['items' => $items], JSON_UNESCAPED_SLASHES);

// ---------- helpers ----------
function load_service_status_map(): array
{
    $path = dashboard_state_path('services_status.json');
    if (!is_file($path)) {
        return [];
    }
    $payload = json_decode(@file_get_contents($path), true);
    if (!is_array($payload)) {
        return [];
    }
    $results = isset($payload['results']) && is_array($payload['results']) ? $payload['results'] : [];
    $map = [];
    foreach ($results as $row) {
        if (!is_array($row)) {
            continue;
        }
        $sid = $row['id'] ?? ($row['service_id'] ?? null);
        if (!$sid) {
            continue;
        }
        $map[$sid] = [
          'status'     => $row['status'] ?? 'unknown',
          'latency_ms' => isset($row['latency_ms']) ? (int)$row['latency_ms'] : null,
          'http_code'  => $row['http_code'] ?? null,
          'ts'         => isset($row['ts']) ? (int)$row['ts'] : (isset($payload['ts']) ? (int)$payload['ts'] : time()),
        ];
    }
    return $map;
}

function load_service_history_map(int $perServiceLimit = 240, int $tailBytes = 2000000): array
{
    $path = dashboard_state_path('services_status_history.jsonl');
    if (!is_file($path)) {
        return [];
    }
    $size = filesize($path);
    $start = ($size > $tailBytes) ? ($size - $tailBytes) : 0;
    $fh = @fopen($path, 'rb');
    if (!$fh) {
        return [];
    }
    if ($start > 0) {
        fseek($fh, $start);
        fgets($fh); // discard partial line
    }
    $buckets = [];
    while (!feof($fh)) {
        $line = fgets($fh);
        if (!is_string($line)) {
            continue;
        }
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $obj = json_decode($line, true);
        if (!is_array($obj)) {
            continue;
        }
        $sid = $obj['id'] ?? ($obj['service_id'] ?? null);
        if (!$sid) {
            continue;
        }
        if (!isset($buckets[$sid])) {
            $buckets[$sid] = [];
        }
        $buckets[$sid][] = $obj;
        $count = count($buckets[$sid]);
        if ($count > $perServiceLimit) {
            array_shift($buckets[$sid]);
        }
    }
    fclose($fh);

    $out = [];
    foreach ($buckets as $sid => $rows) {
        if (!$rows) {
            continue;
        }
        $total = count($rows);
        $up = 0;
        $firstTs = null;
        $lastTs = null;
        foreach ($rows as $row) {
            $ok = !empty($row['ok']) || ($row['status'] ?? '') === 'up';
            if ($ok) {
                $up++;
            }
            $ts = isset($row['ts']) ? (int)$row['ts'] : null;
            if ($ts !== null) {
                if ($firstTs === null || $ts < $firstTs) {
                    $firstTs = $ts;
                }
                if ($lastTs === null || $ts > $lastTs) {
                    $lastTs = $ts;
                }
            }
        }
        $uptime = $total ? round($up / $total * 100) : null;
        $out[$sid] = [
          'uptime_pct' => $uptime,
          'samples'    => $total,
          'window_sec' => ($firstTs !== null && $lastTs !== null && $lastTs > $firstTs) ? ($lastTs - $firstTs) : 0,
        ];
    }
    return $out;
}

function load_service_alert_meta(): array
{
    $path = __DIR__ . '/../data/alerts.json';
    if (!is_file($path)) {
        return [];
    }
    $payload = json_decode(@file_get_contents($path), true);
    if (!is_array($payload)) {
        return [];
    }
    $items = isset($payload['items']) && is_array($payload['items']) ? $payload['items'] : [];
    $now = time();
    $map = [];
    foreach ($items as $rule) {
        if (!is_array($rule)) {
            continue;
        }
        $sid = $rule['service_id'] ?? null;
        if (!$sid) {
            continue;
        }
        if (!isset($map[$sid])) {
            $map[$sid] = [
              'last_alert'    => null,
              'silenced_until' => null,
              'rule_count'    => 0,
              'active_rules'  => 0,
              'rule_ids'      => [],
            ];
        }
        if (!empty($rule['id'])) {
            $map[$sid]['rule_ids'][] = $rule['id'];
        }
        $map[$sid]['rule_count']++;
        if (!empty($rule['enabled'])) {
            $map[$sid]['active_rules']++;
        }
        $last = isset($rule['last_triggered']) ? (int)$rule['last_triggered'] : 0;
        if ($last > 0) {
            $current = $map[$sid]['last_alert'];
            if (!$current || $last > ($current['ts'] ?? 0)) {
                $map[$sid]['last_alert'] = [
                  'ts'       => $last,
                  'severity' => $rule['severity'] ?? 'warn',
                  'name'     => $rule['name'] ?? '',
                ];
            }
        }
        $silence = isset($rule['silenced_until']) ? (int)$rule['silenced_until'] : 0;
        if ($silence > $now) {
            if (empty($map[$sid]['silenced_until']) || $silence > $map[$sid]['silenced_until']) {
                $map[$sid]['silenced_until'] = $silence;
            }
        }
    }
    return $map;
}
