<?php

require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../lib/Config.php';
require_admin();
header('Content-Type: application/json');

\App\Config::init(dirname(__DIR__));

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
    echo json_encode(['ok' => false,'error' => 'invalid body']);
    exit;
}

$csrf = (string)($body['_csrf'] ?? $body['csrf'] ?? '');
if (!csrf_check($csrf)) {
    http_response_code(403);
    echo json_encode(['ok' => false,'error' => 'CSRF failed']);
    exit;
}
unset($body['_csrf'], $body['csrf']);

$normalized = [];
foreach ($body as $k => $v) {
    $normalized[strtolower($k)] = $v;
}

function parse_emails($value)
{
    if (is_array($value)) {
        return array_values(array_filter(array_map(function ($v) {
            return trim((string)$v);
        }, $value)));
    }
    $parts = preg_split('/\s*,\s*/', (string)$value, -1, PREG_SPLIT_NO_EMPTY);
    $out = [];
    foreach ($parts as $p) {
        $t = trim($p);
        if ($t !== '') {
            $out[] = $t;
        }
    }
    return $out;
}

$patch = [];
function assign_patch(array &$dest, array $path, $value)
{
    $node = & $dest;
    foreach ($path as $i => $segment) {
        if ($i === count($path) - 1) {
            $node[$segment] = $value;
        } else {
            if (!isset($node[$segment]) || !is_array($node[$segment])) {
                $node[$segment] = [];
            }
            $node = & $node[$segment];
        }
    }
}

$map = [
  'mail_transport' => ['mail','mail_transport'],
  'mail_from'      => ['mail','mail_from'],
  'mail_replyto'   => ['mail','mail_replyto'],
  'sendmail_path'  => ['mail','sendmail_path'],
  'smtp_host'      => ['mail','smtp_host'],
  'smtp_port'      => ['mail','smtp_port'],
  'smtp_secure'    => ['mail','smtp_secure'],
  'smtp_user'      => ['mail','smtp_user'],
  'smtp_pass'      => ['mail','smtp_pass'],
  'smtp_timeout'   => ['mail','smtp_timeout'],
  'alert_emails'   => ['mail','sec_email'],
  'sec_email'      => ['mail','sec_email'],
  'email'          => ['mail','sec_email'],
  'cron_token'     => ['alerts','cron_token'],
  'admin_emails'   => ['security','admin_emails'],
  'allowed_origins' => ['security','allowed_origins'],
  'ip_allowlist'   => ['security','ip_allowlist'],
];

foreach ($map as $key => $path) {
    if (!array_key_exists($key, $normalized)) {
        continue;
    }
    $value = $normalized[$key];
    if ($key === 'smtp_pass' && ($value === '' || $value === null)) {
        continue; // preserve existing
    }
    if (in_array($key, ['alert_emails','sec_email','email','admin_emails','allowed_origins','ip_allowlist'], true)) {
        $value = parse_emails($value);
    }
    if (in_array($key, ['smtp_port','smtp_timeout'], true)) {
        $value = is_numeric($value) ? (int)$value : null;
    }
    assign_patch($patch, $path, $value);
}

if (!$patch) {
    echo json_encode(['ok' => false,'error' => 'no changes']);
    exit;
}

\App\Config::setMany($patch);
echo json_encode(['ok' => true]);
