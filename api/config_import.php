<?php

// api/config_import.php
// Import JSON from uploaded file field 'file' (multipart/form-data) or raw POST JSON.
// Writes config/local.json, data/security_config.json, data/alerts.json, and data/services.json,
// keeping snapshots in config/backups/.
// Returns JSON {ok:true, applied:{config:bool, security:bool, alerts:bool, services:bool}}.
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../lib/AuditLog.php';
require_once __DIR__ . '/../lib/Redaction.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');

$root = dirname(__DIR__);
$cfgFile = $root . '/config/local.json';
$secFile = $root . '/data/security_config.json';
$alertsFile = $root . '/data/alerts.json';
$servicesFile = $root . '/data/services.json';
$backupDir = $root . '/config/backups';
if (!is_dir($backupDir)) {
    @mkdir($backupDir, 0775, true);
}

function ensure_parent_dir($path)
{
    $dir = dirname($path);
    if (is_dir($dir)) {
        return true;
    }
    return @mkdir($dir, 0775, true) || is_dir($dir);
}

// --- CSRF ---
$csrf = $_POST['_csrf'] ?? ($_GET['_csrf'] ?? '');
if (!csrf_check($csrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false,'error' => 'CSRF failed']);
    exit;
}
// Read JSON body
$raw = null;
if (!empty($_FILES['file']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
    $raw = file_get_contents($_FILES['file']['tmp_name']);
} else {
    $raw = file_get_contents('php://input');
    // If it's JSON with {file:...} skip; we want direct object
}
if ($raw === false || trim($raw) === '') {
    AuditLog::record('config.import', 'config bundle', false, ['error' => 'no data'], 'config import failed', 'config');
    echo json_encode(['ok' => false,'error' => 'no data']);
    exit;
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    AuditLog::record('config.import', 'config bundle', false, ['error' => 'invalid json'], 'config import failed', 'config');
    echo json_encode(['ok' => false,'error' => 'invalid json']);
    exit;
}

$applied = ['config' => false,'security' => false,'alerts' => false,'services' => false];

function snapshot_file($path, $backupDir, $prefix)
{
    if (!is_file($path)) {
        return null;
    }
    $ts = date('Ymd-His');
    $dst = rtrim($backupDir, '/') . '/' . $prefix . '-' . $ts . '.json';
    if (@copy($path, $dst)) {
        @chmod($dst, 0640);
        return $dst;
    }
    return null;
}

// Helper to safe write with backup
function write_with_backup($path, $jsonArr, $backupDir, $prefix)
{
    if (!ensure_parent_dir($path)) {
        return false;
    }
    snapshot_file($path, $backupDir, $prefix);
    $tmp = $path . '.tmp';
    $bytes = @file_put_contents($tmp, json_encode($jsonArr, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    if ($bytes === false) {
        return false;
    }
    @chmod($tmp, 0640);
    $ok = @rename($tmp, $path);
    if (!$ok) {
        @unlink($tmp);
    }
    return $ok;
}

function read_json_file($path)
{
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function merge_keep_redacted($incoming, $current)
{
    if (is_string($incoming) && strtoupper($incoming) === 'REDACTED') {
        return $current;
    }
    if (!is_array($incoming)) {
        return $incoming;
    }
    if (!is_array($current)) {
        $current = [];
    }
    $out = [];
    foreach ($incoming as $k => $v) {
        $existing = array_key_exists($k, $current) ? $current[$k] : null;
        $out[$k] = merge_keep_redacted($v, $existing);
    }
    return $out;
}

// Apply config
if (array_key_exists('config', $data) && is_array($data['config'])) {
    $currentCfg = read_json_file($cfgFile);
    $toWriteCfg = merge_keep_redacted($data['config'], $currentCfg);
    if (write_with_backup($cfgFile, $toWriteCfg, $backupDir, 'config')) {
        $applied['config'] = true;
    }
}

// Apply security
if (array_key_exists('security', $data) && is_array($data['security'])) {
    $currentSec = read_json_file($secFile);
    $toWriteSec = merge_keep_redacted($data['security'], $currentSec);
    if (write_with_backup($secFile, $toWriteSec, $backupDir, 'security')) {
        $applied['security'] = true;
    }
}

// Apply alert rules.
// Preferred key: alert_rules
// Backward-compatible key: alerts
$alertsPayload = null;
if (array_key_exists('alert_rules', $data) && is_array($data['alert_rules'])) {
    $alertsPayload = $data['alert_rules'];
} elseif (array_key_exists('alerts', $data) && is_array($data['alerts'])) {
    $alertsPayload = $data['alerts'];
}

if (is_array($alertsPayload)) {
    if (isset($alertsPayload['items']) && is_array($alertsPayload['items'])) {
        $toWrite = ['items' => $alertsPayload['items']];
    } else {
        $toWrite = ['items' => $alertsPayload];
    }
    if (write_with_backup($alertsFile, $toWrite, $backupDir, 'alerts')) {
        $applied['alerts'] = true;
    }
}

// Apply services.
// Preferred key: services
// Backward-compatible key: service_rules
$servicesPayload = null;
if (array_key_exists('services', $data) && is_array($data['services'])) {
    $servicesPayload = $data['services'];
} elseif (array_key_exists('service_rules', $data) && is_array($data['service_rules'])) {
    $servicesPayload = $data['service_rules'];
}

if (is_array($servicesPayload)) {
    if (isset($servicesPayload['items']) && is_array($servicesPayload['items'])) {
        $toWrite = ['items' => $servicesPayload['items']];
    } else {
        $toWrite = ['items' => $servicesPayload];
    }
    if (write_with_backup($servicesFile, $toWrite, $backupDir, 'services')) {
        $applied['services'] = true;
    }
}

echo json_encode([
  'ok' => ($applied['config'] || $applied['security'] || $applied['alerts'] || $applied['services']),
  'applied' => $applied
]);

AuditLog::record(
    'config.import',
    'config bundle',
    ($applied['config'] || $applied['security'] || $applied['alerts'] || $applied['services']),
    [
        'applied' => $applied,
        'payload_keys' => array_keys($data),
        'payload' => Redaction::sanitizeForPersisted($data),
    ],
    'config import processed',
    'config'
);
