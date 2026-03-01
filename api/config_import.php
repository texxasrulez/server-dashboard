<?php
// api/config_import.php
// Import JSON from uploaded file field 'file' (multipart/form-data) or raw POST JSON.
// Writes config/local.json and/or data/security_config.json, keeping snapshots in config/backups/.
// Returns JSON {ok:true, applied:{config:bool, security:bool}}.
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/auth.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');

$root = dirname(__DIR__);
$cfgFile = $root . '/config/local.json';
$secFile = $root . '/data/security_config.json';
$backupDir = $root . '/config/backups';
if (!is_dir($backupDir)) {
  @mkdir($backupDir, 0775, true);
}

// --- CSRF ---
$csrf = $_POST['_csrf'] ?? ($_GET['_csrf'] ?? '');
if (!csrf_check($csrf)) {
  http_response_code(403);
  echo json_encode(['ok'=>false,'error'=>'CSRF failed']); exit;
}
// Read JSON body
$raw = null;
if (!empty($_FILES['file']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
  $raw = file_get_contents($_FILES['file']['tmp_name']);
} else {
  $raw = file_get_contents('php://input');
  // If it's JSON with {file:...} skip; we want direct object
}
if ($raw === false || trim($raw)==='') { echo json_encode(['ok'=>false,'error'=>'no data']); exit; }

$data = json_decode($raw, true);
if (!is_array($data)) { echo json_encode(['ok'=>false,'error'=>'invalid json']); exit; }

$applied = ['config'=>false,'security'=>false];

function snapshot_file($path, $backupDir, $prefix) {
  if (!is_file($path)) return null;
  $ts = date('Ymd-His');
  $dst = rtrim($backupDir, '/') . '/' . $prefix . '-' . $ts . '.json';
  if (@copy($path, $dst)) {
    @chmod($dst, 0640);
    return $dst;
  }
  return null;
}

// Helper to safe write with backup
function write_with_backup($path, $jsonArr, $backupDir, $prefix) {
  snapshot_file($path, $backupDir, $prefix);
  $tmp = $path . '.tmp';
  $bytes = @file_put_contents($tmp, json_encode($jsonArr, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
  if ($bytes === false) {
    return false;
  }
  @chmod($tmp, 0640);
  $ok = @rename($tmp, $path);
  if (!$ok) { @unlink($tmp); }
  return $ok;
}

// Apply config
if (array_key_exists('config', $data) && is_array($data['config'])) {
  if (write_with_backup($cfgFile, $data['config'], $backupDir, 'config')) { $applied['config'] = true; }
}

// Apply security
if (array_key_exists('security', $data) && is_array($data['security'])) {
  if (write_with_backup($secFile, $data['security'], $backupDir, 'security')) { $applied['security'] = true; }
}

echo json_encode(['ok'=>($applied['config']||$applied['security']), 'applied'=>$applied]);
