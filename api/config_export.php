<?php
// api/config_export.php
// Exports config/local.json + data/security_config.json as a single JSON download.
// Accepts CSRF via GET (_csrf) or POST (form/json).

header('X-Robots-Tag: noindex');

$root = dirname(__DIR__);
$cfgFile = $root . '/config/local.json';
$secFile = $root . '/data/security_config.json';
$stateDir = $root . '/state';

// --- CSRF (very lightweight; rely on existing session mechanism if present) ---
session_start();
$csrf = $_GET['_csrf'] ?? $_POST['_csrf'] ?? '';
if (isset($_SESSION['csrf_token']) && $_SESSION['csrf_token']) {
  if (!$csrf || !hash_equals($_SESSION['csrf_token'], $csrf)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'error'=>'CSRF failed']); exit;
  }
}
// If no session token is set, allow (backward compat).

// Compose payload
$cfg = is_file($cfgFile) ? json_decode(@file_get_contents($cfgFile), true) : null;
$sec = is_file($secFile) ? json_decode(@file_get_contents($secFile), true) : null;
$out = [
  'meta' => [
    'exported_at' => gmdate('c'),
    'host' => $_SERVER['HTTP_HOST'] ?? 'localhost',
    'path' => basename($root),
    'version' => '1',
  ],
  'config' => $cfg,
  'security' => $sec,
];

$filename = 'config_backup_' . gmdate('Ymd_His') . '.json';
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo json_encode($out, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
