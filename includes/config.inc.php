<?php
// includes/config.inc.php — single canonical config bootstrap.
// Unified config (single-source)
// DO NOT include any UI files here.

if (defined('APP_CONFIG_INC_LOADED')) return;
define('APP_CONFIG_INC_LOADED', 1);

/* ---------- helpers ---------- */
if (!function_exists('cfg_env')) {
  function cfg_env(string $key, $default = null) {
    $dash = 'DASH_' . $key;
    $v = getenv($dash); if ($v !== false && $v !== '') return $v;
    $v = getenv($key);  if ($v !== false && $v !== '') return $v;
    return $default;
  }
}
if (!function_exists('cfg_define')) {
  function cfg_define(string $key, $value): void {
    if (!defined($key)) define($key, $value);
  }
}

// Define Build Version
if (!defined('BUILD')) define('BUILD', 'Server Dashboard-v0.0.1'); // or any string you want

/* ---------- Core / Security ---------- */
cfg_define('CRON_TOKEN', (string) cfg_env('CRON_TOKEN', ''));

/* ---------- Mailer ---------- */
cfg_define('MAIL_TRANSPORT', strtolower((string) cfg_env('MAIL_TRANSPORT', 'phpmail')));
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$from_fallback = 'Dashboard Alerts <alerts@' . preg_replace('/^www\./i', '', $host) . '>';
cfg_define('MAIL_FROM',     (string) cfg_env('MAIL_FROM', $from_fallback));
cfg_define('MAIL_REPLYTO',  (string) cfg_env('MAIL_REPLYTO', ''));
cfg_define('SENDMAIL_PATH', (string) cfg_env('SENDMAIL_PATH', '/usr/sbin/sendmail'));
cfg_define('SMTP_HOST',     (string) cfg_env('SMTP_HOST', ''));
cfg_define('SMTP_PORT',     (int)    cfg_env('SMTP_PORT', 587));
cfg_define('SMTP_SECURE',   strtolower((string) cfg_env('SMTP_SECURE', 'tls')));
cfg_define('SMTP_USER',     (string) cfg_env('SMTP_USER', ''));
cfg_define('SMTP_PASS',     (string) cfg_env('SMTP_PASS', ''));
cfg_define('SMTP_TIMEOUT',  (int)    cfg_env('SMTP_TIMEOUT', 12));

/* ---------- Alerts / History ---------- */
cfg_define('ALLOW_HISTORY_EXPORT_WITH_TOKEN', (bool) (int) cfg_env('ALLOW_HISTORY_EXPORT_WITH_TOKEN', 1));
cfg_define('ALERT_EMAILS', (string) cfg_env('ALERT_EMAILS', ''));

/* ---------- Theming / Client ---------- */
$disk = cfg_env('DISK_METRICS_PATH', null);
if ($disk !== null && $disk !== '') cfg_define('DISK_METRICS_PATH', $disk);
$theme = cfg_env('THEME_DEFAULT', null);
if ($theme !== null && $theme !== '') cfg_define('THEME_DEFAULT', $theme);
$client = cfg_env('CLIENT_DEBUG_LOG', null);
if ($client !== null && $client !== '') cfg_define('CLIENT_DEBUG_LOG', (int) $client);

/* ---------- BUILD (cache-busting) ---------- */
if (!defined('BUILD')) {
  $build = cfg_env('BUILD', '');
  if ($build === '') {
    $f = __DIR__ . '/../state/build.txt';
    if (is_file($f)) $build = trim(@file_get_contents($f));
  }
  if ($build === '') $build = date('Ymd.His');
  define('BUILD', $build);
}

/* ---------- Optional local overrides ---------- */
/* ---------- Snapshot helper (no secrets) ---------- */
if (!function_exists('app_config_snapshot')) {
  function app_config_snapshot(): array {
    $keys = [
      'CRON_TOKEN','MAIL_TRANSPORT','MAIL_FROM','MAIL_REPLYTO',
      'SENDMAIL_PATH','SMTP_HOST','SMTP_PORT','SMTP_SECURE','SMTP_USER','SMTP_PASS','SMTP_TIMEOUT',
      'ALLOW_HISTORY_EXPORT_WITH_TOKEN','ALERT_EMAILS','DISK_METRICS_PATH','THEME_DEFAULT','CLIENT_DEBUG_LOG','BUILD',
    ];
    $out = [];
    foreach ($keys as $k) $out[$k] = defined($k) ? constant($k) : null;
    foreach (['CRON_TOKEN','SMTP_USER','SMTP_PASS'] as $mask) if (!empty($out[$mask])) $out[$mask] = 'set';
    return $out;
  }
}

// --- canonical guards appended ---
if (!defined('PROJECT_ROOT')) define('PROJECT_ROOT', realpath(__DIR__ . '/..'));
// Load JSON overrides early (if present)
$__cfg_json = [];
$__cfg_state = (defined('DATA_DIR') ? DATA_DIR : (realpath(__DIR__ . '/..') . '/data'));
$__cfg_file = $__cfg_state . '/config.json';
if (is_file($__cfg_file)) {
  $t = @file_get_contents($__cfg_file);
  $d = @json_decode($t, true);
  if (is_array($d)) $__cfg_json = $d;
}
function __cfg_ovr($k, $default) {
  global $__cfg_json;
  return array_key_exists($k, $__cfg_json) ? $__cfg_json[$k] : $default;
}
/* JSON overrides from data/config.json */
$__auto_base = (function(){
  $doc = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
  $root = realpath(__DIR__ . '/..');
  if ($doc && $root && strpos($root, $doc) === 0) {
    $p = substr($root, strlen($doc));
    return $p === '' ? '/' : $p;
  }
  $script = $_SERVER['SCRIPT_NAME'] ?? '';
  $dir = rtrim(dirname($script), '/');
  return $dir === '' ? '/' : $dir;
})();
if (!defined('BASE_URL')) define('BASE_URL', __cfg_ovr('BASE_URL', $__auto_base));
if (!defined('STATE_DIR')) define('STATE_DIR', PROJECT_ROOT . '/state');
if (!defined('DATA_DIR')) define('DATA_DIR', PROJECT_ROOT . '/data');
if (!defined('USERS_FILE')) define('USERS_FILE', DATA_DIR . '/users.json');
if (!defined('THEME_DEFAULT')) define('THEME_DEFAULT', __cfg_ovr('THEME_DEFAULT', 'nord'));
if (!defined('MAIL_FROM')) { $h = $_SERVER['HTTP_HOST'] ?? 'localhost'; $h = preg_replace('/^www\./i','',$h); define('MAIL_FROM', __cfg_ovr('MAIL_FROM', 'Dashboard Alerts <alerts@'.$h.'>')); }
// if (!defined('CRON_TOKEN')) define('CRON_TOKEN', __cfg_ovr('CRON_TOKEN', 'change-me'));
