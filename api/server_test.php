<?php
/* api/server_test.php - read-only JSON diagnostics; NO server-state changes */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

function ini_val(string $k){ $v = ini_get($k); return $v === false ? null : $v; }
function bool_or_null($v){ return is_null($v) ? null : (bool)$v; }

$root = dirname(__DIR__);

$ini = [
  'upload_max_filesize' => ini_val('upload_max_filesize'),
  'post_max_size'       => ini_val('post_max_size'),
  'memory_limit'        => ini_val('memory_limit'),
  'max_execution_time'  => ini_val('max_execution_time'),
  'max_input_vars'      => ini_val('max_input_vars'),
  'default_charset'     => ini_val('default_charset'),
  'date.timezone'       => ini_val('date.timezone'),
];

$exts = ['curl','gd','imagick','openssl','mbstring','pdo_mysql','intl','json','xml','zip','zlib'];
$extensions = [];
foreach ($exts as $e){ $extensions[$e] = extension_loaded($e); }

$opcache = null;
if (function_exists('opcache_get_status')){
  $st = @opcache_get_status(false);
  if (is_array($st)){
    $opcache = [
      'enabled' => isset($st['opcache_enabled']) ? (bool)$st['opcache_enabled'] : null,
      'jit'     => isset($st['jit']) ? $st['jit'] : null,
      'memory'  => isset($st['memory_usage']) ? $st['memory_usage'] : null,
      'stats'   => isset($st['opcache_statistics']) ? [
        'num_cached_scripts' => $st['opcache_statistics']['num_cached_scripts'] ?? null,
        'hits'               => $st['opcache_statistics']['hits'] ?? null,
        'misses'             => $st['opcache_statistics']['misses'] ?? null,
        'blacklist_misses'   => $st['opcache_statistics']['blacklist_misses'] ?? null,
        'opcache_hit_rate'   => $st['opcache_statistics']['opcache_hit_rate'] ?? null,
      ] : null,
    ];
  }
}

$paths = [
  'project_root' => $root,
  'assets'       => $root . '/assets',
  'cache'        => $root . '/cache',
  'tmp'          => $root . '/tmp',
];
$fs = [];
foreach ($paths as $name => $p){
  $fs[$name] = [
    'exists'   => file_exists($p),
    'is_dir'   => is_dir($p),
    'is_readable' => is_readable($p),
    'is_writable' => is_writable($p),
  ];
}

$disk = null;
if (is_dir($root)){
  $disk = [
    'free'  => @disk_free_space($root),
    'total' => @disk_total_space($root),
  ];
}

// Network
$net = [
  'dns_ok' => function_exists('checkdnsrr') ? @checkdnsrr('example.com', 'A') : null,
  'https_ok' => null,
  'curl_ok'  => null,
  'curl_version' => function_exists('curl_version') ? curl_version() : null,
];
// HTTPS via get_headers with small timeout
$ctx = stream_context_create([
  'http' => ['method' => 'HEAD', 'timeout' => 2, 'ignore_errors' => true],
  'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true]
]);
$hdr = @get_headers('https://www.example.com', 1, $ctx);
$net['https_ok'] = is_array($hdr);

// Curl available
if (function_exists('curl_init')){
  $net['curl_ok'] = true;
}

$env = [
  'php_version' => PHP_VERSION,
  'php_sapi'    => PHP_SAPI,
  'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? null,
  'os'          => PHP_OS_FAMILY,
  'uname'       => function_exists('php_uname') ? php_uname() : null,
];

$out = [
  'php' => [
    'version' => PHP_VERSION,
    'sapi'    => PHP_SAPI,
  ],
  'ini' => $ini,
  'extensions' => $extensions,
  'opcache' => $opcache,
  'filesystem' => [
    'paths' => $fs,
    'disk'  => $disk,
  ],
  'network' => $net,
  'env' => $env,
  'ts' => time(),
];

echo json_encode($out, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
