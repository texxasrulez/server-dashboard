<?php

require __DIR__.'/_guard.php';
guard_api(['key' => 'cron_heartbeat','require_token' => true,'type' => 'json']);
require_once __DIR__ . '/_state_path.php';
header('Content-Type: application/json; charset=utf-8');

$id = trim((string)($_GET['id'] ?? $_GET['job'] ?? ''));
if ($id === '') {
    http_response_code(400);
    echo json_encode(['ok' => false,'error' => 'Missing id parameter']);
    exit;
}

$heartbeatParam = isset($_GET['heartbeat']) ? trim((string)$_GET['heartbeat']) : '';
$tsParam = isset($_GET['ts']) ? (int)$_GET['ts'] : null;
$ts = $tsParam && $tsParam > 0 ? $tsParam : time();

function slugify_job($s)
{
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9\-]+/', '-', $s);
    $s = preg_replace('/-+/', '-', $s);
    return trim($s, '-') ?: ('job_' . substr(sha1($s.microtime(true)), 0, 8));
}
function resolve_heartbeat_path($root, $hbDir, $id, $custom)
{
    if ($custom !== '') {
        $paths = [$custom];
    } else {
        $paths = [$hbDir . '/' . $id . '.txt'];
    }
    foreach ($paths as $candidate) {
        $candidate = trim($candidate);
        if ($candidate === '') {
            continue;
        }
        if ($candidate[0] !== '/' && !preg_match('/^[A-Za-z]:[\\\\\\/]/', $candidate)) {
            $candidate = $root . '/' . ltrim($candidate, '/');
        }
        $dir = dirname($candidate);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $realDir = realpath($dir);
        if ($realDir === false) {
            continue;
        }
        if (strpos($realDir, $root) !== 0) {
            continue;
        }
        return rtrim($realDir, '/') . '/' . basename($candidate);
    }
    return $hbDir . '/' . $id . '.txt';
}

$root = realpath(dirname(__DIR__));
$hbDir = dirname(dashboard_state_path('heartbeats/.keep'));
@mkdir($hbDir, 0775, true);
$slug = slugify_job($id);
$path = resolve_heartbeat_path($root, $hbDir, $slug, $heartbeatParam);

$payload = $ts . PHP_EOL;
if (@file_put_contents($path, $payload, LOCK_EX) === false) {
    http_response_code(500);
    echo json_encode(['ok' => false,'error' => 'Failed to write heartbeat']);
    exit;
}
@chmod($path, 0640);

echo json_encode([
  'ok' => true,
  'id' => $slug,
  'ts' => $ts,
  'heartbeat' => $path,
]);
