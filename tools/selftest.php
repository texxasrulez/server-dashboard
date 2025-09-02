<?php
// tools/selftest.php — non-destructive portability check
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/../includes/init.php';

$checks = [];

function add_check(&$arr, $name, $ok, $details=''){
  $arr[] = ['name'=>$name, 'ok'=>$ok, 'details'=>$details];
}

add_check($checks, 'PHP version >= 7.4', version_compare(PHP_VERSION, '7.4.0', '>='), 'Current: ' . PHP_VERSION);

// Sessions
add_check($checks, 'Sessions usable', session_status() === PHP_SESSION_ACTIVE);

// JSON
add_check($checks, 'JSON extension', function_exists('json_encode'));

// File permissions on state/
$state = realpath(__DIR__ . '/../state');
$state_ok = $state && is_dir($state) && is_writable($state);
add_check($checks, 'Writable state/ directory', $state_ok, $state ?: 'not found');

// URLs build correctly
$u = project_url('/api/metrics_summary.php');
add_check($checks, 'project_url()', is_string($u) && $u !== '' , $u);

// cURL/fopen availability (not required, just nice)
$curl_ok = function_exists('curl_init');
$fo_ok = (bool) ini_get('allow_url_fopen');
add_check($checks, 'cURL available (optional)', $curl_ok);
add_check($checks, 'allow_url_fopen (optional)', $fo_ok ? true : false);

// mbstring (optional but recommended)
add_check($checks, 'mbstring (optional)', function_exists('mb_strlen'));

// Simple disk/mem/uptime calls should not fatal on non-Linux
$metrics_url = project_url('/api/metrics_summary.php') . '?trace=1';

?><!doctype html>
<html>

<meta charset="utf-8"/>
<title>Self-test</title>
<link rel="stylesheet" href="<?= h(project_url('/assets/css/core.css')) ?>?v=<?= h(BUILD) ?>" />
</head>
<body class="container" style="max-width:980px;margin:2rem auto">
<h1>Portability Self-test</h1>
<table class="kv">
<?php foreach ($checks as $c): ?>
<tr>
  <th><?= h($c['name']) ?></th>
  <td><?= $c['ok'] ? '✅ OK' : '⚠️ Check' ?><?= $c['details'] ? ' — ' . h($c['details']) : '' ?></td>
</tr>
<?php endforeach; ?>
</table>
<p>For deeper signal, open <?= h($metrics_url) ?> in your browser.</p>
</body></html>
