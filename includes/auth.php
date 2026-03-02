<?php
// includes/auth.php — session auth helpers (JSON-backed user store)
if (session_status() !== PHP_SESSION_ACTIVE) @session_start();

if (!defined('USERS_FILE')) {
  define('USERS_FILE', __DIR__ . '/../data/users.json');
}

if (!function_exists('project_url')) {
  function project_url($path='') {
  $base = defined('BASE_URL') ? BASE_URL : rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
  if ($base === '') { $base = '/'; }
  $p = '/'.ltrim((string)$path, '/');
  return rtrim($base,'/').$p;
}

}

if (!function_exists('auth_cfg')) {
  function auth_cfg($path, $default = null) {
    if (function_exists('cfg_local')) return cfg_local((string)$path, $default);
    return $default;
  }
}

if (!function_exists('auth_client_ip')) {
  function auth_client_ip() {
    if (function_exists('request_client_ip')) return (string)request_client_ip();
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string)$_SERVER['REMOTE_ADDR'] : '';
    return ($ip !== '') ? $ip : '0.0.0.0';
  }
}

if (!function_exists('auth_rate_limit_config')) {
  function auth_rate_limit_config() {
    $cfg = auth_cfg('security.login_rate_limit', []);
    if (!is_array($cfg)) $cfg = [];
    return [
      'enabled'        => array_key_exists('enabled', $cfg) ? (bool)$cfg['enabled'] : true,
      'max_attempts'   => max(1, (int)($cfg['max_attempts'] ?? 5)),
      'window_sec'     => max(30, (int)($cfg['window_sec'] ?? 900)),
      'base_delay_sec' => max(1, (int)($cfg['base_delay_sec'] ?? 30)),
      'max_delay_sec'  => max(5, (int)($cfg['max_delay_sec'] ?? 900)),
    ];
  }
}

if (!function_exists('auth_login_rate_file')) {
  function auth_login_rate_file() {
    $root = dirname(__DIR__);
    $dir = $root . '/state/auth';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir . '/login_rate.json';
  }
}

if (!function_exists('auth_login_rate_load')) {
  function auth_login_rate_load() {
    $file = auth_login_rate_file();
    if (!is_file($file)) return [];
    $raw = @file_get_contents($file);
    if (!is_string($raw) || trim($raw) === '') return [];
    $data = @json_decode($raw, true);
    return is_array($data) ? $data : [];
  }
}

if (!function_exists('auth_login_rate_save')) {
  function auth_login_rate_save($data) {
    $file = auth_login_rate_file();
    $tmp = $file . '.tmp';
    @file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), LOCK_EX);
    @chmod($tmp, 0640);
    @rename($tmp, $file);
  }
}

if (!function_exists('auth_login_rate_key')) {
  function auth_login_rate_key($username, $ip) {
    $u = strtolower(trim((string)$username));
    $i = trim((string)$ip);
    if ($u === '') $u = 'unknown';
    if ($i === '') $i = '0.0.0.0';
    return hash('sha256', $u . '|' . $i);
  }
}

if (!function_exists('auth_set_last_login_error')) {
  function auth_set_last_login_error($msg) {
    $GLOBALS['AUTH_LOGIN_LAST_ERROR'] = (string)$msg;
  }
}

if (!function_exists('auth_last_login_error')) {
  function auth_last_login_error() {
    return isset($GLOBALS['AUTH_LOGIN_LAST_ERROR']) ? (string)$GLOBALS['AUTH_LOGIN_LAST_ERROR'] : '';
  }
}

if (!function_exists('auth_login_rate_prune')) {
  function auth_login_rate_prune($all, $windowSec, $now) {
    $out = [];
    foreach ((array)$all as $k => $row) {
      if (!is_array($row)) continue;
      $last = isset($row['last']) ? (int)$row['last'] : 0;
      $next = isset($row['next_allowed']) ? (int)$row['next_allowed'] : 0;
      if ($last >= ($now - ($windowSec * 2)) || $next >= ($now - ($windowSec * 2))) {
        $out[$k] = $row;
      }
    }
    return $out;
  }
}

if (!function_exists('auth_login_rate_block_seconds')) {
  function auth_login_rate_block_seconds($username, $ip) {
    $cfg = auth_rate_limit_config();
    if (empty($cfg['enabled'])) return 0;
    $all = auth_login_rate_load();
    $key = auth_login_rate_key($username, $ip);
    $row = isset($all[$key]) && is_array($all[$key]) ? $all[$key] : [];
    $now = time();
    $next = isset($row['next_allowed']) ? (int)$row['next_allowed'] : 0;
    if ($next > $now) return ($next - $now);
    return 0;
  }
}

if (!function_exists('auth_login_rate_register_failure')) {
  function auth_login_rate_register_failure($username, $ip) {
    $cfg = auth_rate_limit_config();
    if (empty($cfg['enabled'])) return;
    $all = auth_login_rate_load();
    $now = time();
    $all = auth_login_rate_prune($all, $cfg['window_sec'], $now);
    $key = auth_login_rate_key($username, $ip);
    $row = isset($all[$key]) && is_array($all[$key]) ? $all[$key] : ['count'=>0,'first'=>$now,'last'=>$now,'next_allowed'=>0];
    $first = isset($row['first']) ? (int)$row['first'] : $now;
    if (($now - $first) > $cfg['window_sec']) {
      $row = ['count'=>0,'first'=>$now,'last'=>$now,'next_allowed'=>0];
    }
    $row['count'] = ((int)($row['count'] ?? 0)) + 1;
    $row['last'] = $now;
    if ($row['count'] >= $cfg['max_attempts']) {
      $overs = $row['count'] - $cfg['max_attempts'];
      $delay = $cfg['base_delay_sec'] * (1 << min(6, $overs));
      if ($delay > $cfg['max_delay_sec']) $delay = $cfg['max_delay_sec'];
      $row['next_allowed'] = $now + $delay;
    }
    $all[$key] = $row;
    auth_login_rate_save($all);
  }
}

if (!function_exists('auth_login_rate_clear')) {
  function auth_login_rate_clear($username, $ip) {
    $cfg = auth_rate_limit_config();
    if (empty($cfg['enabled'])) return;
    $all = auth_login_rate_load();
    $key = auth_login_rate_key($username, $ip);
    if (isset($all[$key])) unset($all[$key]);
    $all = auth_login_rate_prune($all, $cfg['window_sec'], time());
    auth_login_rate_save($all);
  }
}

function csrf_token() {
  if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
  return $_SESSION['csrf'];
}
function csrf_check($token) {
  return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token ?? '');
}
function csrf_request_token($fallback = '') {
  $tok = (string)$fallback;
  if ($tok !== '') return $tok;
  $candidates = [
    $_POST['_csrf'] ?? null,
    $_POST['csrf'] ?? null,
    $_GET['_csrf'] ?? null,
    $_GET['csrf'] ?? null,
    $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null,
  ];
  foreach ($candidates as $c) {
    if (is_string($c) && $c !== '') return $c;
  }
  return '';
}
function csrf_check_request($fallback = '') {
  return csrf_check(csrf_request_token($fallback));
}

function users_load() {
  $f = USERS_FILE;
  if (!file_exists($f)) return ['users'=>[]];
  $raw = file_get_contents($f);
  $data = json_decode($raw, true);
  if (!is_array($data) || !isset($data['users'])) $data = ['users'=>[]];
  return $data;
}
function users_save($data) {
  $dir = dirname(USERS_FILE);
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  file_put_contents(USERS_FILE, json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
}

function user_find($username) {
  $data = users_load();
  foreach ($data['users'] as $u) {
    if (isset($u['username']) && strtolower($u['username']) === strtolower((string)$username)) return $u;
  }
  return null;
}

function ensure_default_admin($force = false) {
  $data = users_load();
  if (!empty($data['users'])) return null;
  $allowWeb = (bool)auth_cfg('security.allow_web_bootstrap_admin', false);
  if (!$force && PHP_SAPI !== 'cli' && !$allowWeb) return null;
  $defaultPass = bin2hex(random_bytes(4)); // 8 hex chars
  $hash = password_hash($defaultPass, PASSWORD_DEFAULT);
  $data['users'][] = ['username'=>'admin','password_hash'=>$hash,'role'=>'admin','created'=>date('c')];
  users_save($data);
  return $defaultPass;
}

function auth_login($username, $password) {
  auth_set_last_login_error('');
  $ip = auth_client_ip();
  $wait = auth_login_rate_block_seconds($username, $ip);
  if ($wait > 0) {
    auth_set_last_login_error('Too many login attempts. Try again in ' . $wait . ' seconds.');
    return false;
  }
  $u = user_find($username);
  if (!$u) { auth_login_rate_register_failure($username, $ip); return false; }
  if (!isset($u['password_hash'])) { auth_login_rate_register_failure($username, $ip); return false; }
  if (!password_verify((string)$password, $u['password_hash'])) { auth_login_rate_register_failure($username, $ip); return false; }
  // Success
  session_regenerate_id(true);
  $_SESSION['user'] = ['username'=>$u['username'], 'role'=>$u['role'] ?? 'user'];
  auth_login_rate_clear($username, $ip);
  return true;
}

function auth_logout() {
  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
  }
  session_destroy();
}

function current_user() { return $_SESSION['user'] ?? null; }

function user_profile_of($username){
  $rec = user_find($username);
  return $rec['profile'] ?? null;
}
function gravatar_url_from($email, $size=48){
  $email = strtolower(trim((string)$email));
  if ($email==='') return null;
  $hash = md5($email);
  return "https://www.gravatar.com/avatar/{$hash}?s=" . intval($size) . "&d=identicon";
}
function user_avatar_url($user_or_name=null, $size=48){
  $un = null; $profile = null; $email = null; $avatar = null;
  if (is_array($user_or_name)){
    $un = $user_or_name['username'] ?? null;
    $profile = $user_or_name['profile'] ?? null;
  } elseif (is_string($user_or_name) && $user_or_name!=='') {
    $un = $user_or_name;
  } else {
    $cu = current_user();
    $un = $cu['username'] ?? null;
  }
  if (!$profile && $un) {
    $rec = user_find($un);
    if ($rec) $profile = $rec['profile'] ?? null;
  }
  if (is_array($profile)) {
    $avatar = trim((string)($profile['avatar_url'] ?? ''));
    $email  = trim((string)($profile['email'] ?? ''));
  }
  if ($avatar !== '') return $avatar;
  $g = gravatar_url_from($email, $size);
  if ($g) return $g;
  return project_url('/assets/images/avatar-default.png');
}
function user_display_name($user_or_name=null){
  $un = null; $profile = null;
  if (is_array($user_or_name)) { $un = $user_or_name['username'] ?? null; $profile = $user_or_name['profile'] ?? null; }
  elseif (is_string($user_or_name)) { $un = $user_or_name; }
  else { $cu = current_user(); $un = $cu['username'] ?? null; }
  if (!$profile && $un) {
    $rec = user_find($un);
    if ($rec) $profile = $rec['profile'] ?? null;
  }
  $first = trim((string)($profile['first_name'] ?? ''));
  $last  = trim((string)($profile['last_name'] ?? ''));
  $name = trim("$first $last");
  return $name !== '' ? $name : ($un ?? 'user');
}

function is_logged_in() { return !empty($_SESSION['user']); }
function require_login() {
  if (!is_logged_in()) {
    header('Location: ' . project_url('/auth/login.php') . '?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? project_url('/')));
    exit;
  }
}


function user_is_admin($u = null) {
  if ($u === null) $u = current_user();
  return !empty($u) && (($u['role'] ?? 'user') === 'admin');
}
function require_admin() {
  if (!user_is_admin()) {
    http_response_code(403);
    echo '<!DOCTYPE html><meta charset="utf-8"><h1>403 Forbidden</h1><p>Admin privileges required.</p>';
    exit;
  }
}

function user_add($username, $password, $role='user') {
  $username = trim((string)$username);
  if ($username === '') return [false, 'Username required'];
  if ($role !== 'admin') $role = 'user';
  $data = users_load();
  foreach ($data['users'] as $u) {
    if (strtolower($u['username']) === strtolower($username)) return [false,'User already exists'];
  }
  $data['users'][] = ['username'=>$username,'password_hash'=>password_hash((string)$password, PASSWORD_DEFAULT),'role'=>$role,'created'=>date('c')];
  users_save($data);
  return [true, 'User created'];
}
function user_delete($username) {
  $cu = current_user();
  if (strtolower($cu['username']??'') === strtolower($username)) return [false, 'Cannot delete the currently signed-in user'];
  $data = users_load();
  $before = count($data['users']);
  $data['users'] = array_values(array_filter($data['users'], function($u) use($username) {
    return strtolower($u['username']) !== strtolower($username);
  }));
  if (count($data['users']) === $before) return [false, 'User not found'];
  users_save($data);
  return [true, 'User deleted'];
}
function user_set_role($username, $role) {
  $data = users_load();
  $ok=false;
  foreach ($data['users'] as &$u) {
    if (strtolower($u['username'])===strtolower($username)) {
      $u['role'] = ($role==='admin' ? 'admin' : 'user');
      $ok=true; break;
    }
  }
  unset($u);
  if (!$ok) return [false,'User not found'];
  users_save($data);
  return [true,'Role updated'];
}
