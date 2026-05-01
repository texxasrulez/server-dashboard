<?php

// api/config_export.php
// Exports config/local.json + data/security_config.json + data/alerts.json + data/services.json as a single JSON download.
// Note:
// - config.alerts => alert runtime/settings
// - alert_rules   => alert rules list (from data/alerts.json)
// - services      => services list (from data/services.json)
// Optional:
// - ?redact=1 (or POST redact=1) masks sensitive values with "REDACTED" for safe sharing
// Accepts CSRF via GET (_csrf) or POST (form/json).
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../lib/AuditLog.php';
require_once __DIR__ . '/../lib/Redaction.php';
require_admin();

header('X-Robots-Tag: noindex');

$root = dirname(__DIR__);
$cfgFile = $root . '/config/local.json';
$secFile = $root . '/data/security_config.json';
$alertsFile = $root . '/data/alerts.json';
$alertsStateFile = $root . '/state/alerts.json';
$servicesFile = $root . '/data/services.json';
$servicesStateFile = $root . '/state/services.json';

function truthy_flag($v): bool
{
    $s = strtolower(trim((string)$v));
    return in_array($s, ['1','true','yes','on'], true);
}

function redact_accounts_string($value)
{
    if (!is_string($value) || trim($value) === '') {
        return $value;
    }
    $decoded = json_decode($value, true);
    if (!is_array($decoded)) {
        return $value;
    }
    foreach ($decoded as $i => $acct) {
        if (!is_array($acct)) {
            continue;
        }
        if (array_key_exists('password', $acct)) {
            $decoded[$i]['password'] = 'REDACTED';
        }
    }
    return json_encode($decoded, JSON_UNESCAPED_SLASHES);
}

// --- CSRF ---
$csrf = $_GET['_csrf'] ?? $_POST['_csrf'] ?? '';
if (!csrf_check($csrf)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false,'error' => 'CSRF failed']);
    exit;
}

// Compose payload
$cfg = is_file($cfgFile) ? json_decode(@file_get_contents($cfgFile), true) : null;
$sec = is_file($secFile) ? json_decode(@file_get_contents($secFile), true) : null;
$alerts = null;
if (is_file($alertsFile)) {
    $alerts = json_decode(@file_get_contents($alertsFile), true);
} elseif (is_file($alertsStateFile)) {
    $alerts = json_decode(@file_get_contents($alertsStateFile), true);
}
$services = null;
if (is_file($servicesFile)) {
    $services = json_decode(@file_get_contents($servicesFile), true);
} elseif (is_file($servicesStateFile)) {
    $services = json_decode(@file_get_contents($servicesStateFile), true);
}
$out = [
  'meta' => [
    'exported_at' => gmdate('c'),
    'host' => $_SERVER['HTTP_HOST'] ?? 'localhost',
    'path' => basename($root),
    'version' => '1',
  ],
  'config' => $cfg,
  'security' => $sec,
  'alert_rules' => $alerts,
  'services' => $services,
];

$redact = truthy_flag($_GET['redact'] ?? $_POST['redact'] ?? '');
if ($redact) {
    if (is_array($out['config'])) {
        $out['config'] = Redaction::redactTree($out['config']);
        // Email accounts can be JSON-encoded inside a string value.
        if (isset($out['config']['email']['accounts'])) {
            $out['config']['email']['accounts'] = redact_accounts_string($out['config']['email']['accounts']);
        }
    }
    if (is_array($out['security'])) {
        $out['security'] = Redaction::redactTree($out['security']);
    }
    $out['meta']['redacted'] = true;
}

AuditLog::record(
    'config.export',
    'config bundle',
    true,
    [
        'redacted' => $redact,
        'sections' => ['config', 'security', 'alert_rules', 'services'],
    ],
    'config export ok',
    'config'
);

$filename = 'config_backup_' . gmdate('Ymd_His') . ($redact ? '_redacted' : '') . '.json';
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
