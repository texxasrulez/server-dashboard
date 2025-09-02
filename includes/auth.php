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

function csrf_token() {
  if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
  return $_SESSION['csrf'];
}
function csrf_check($token) {
  return isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token ?? '');
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

function ensure_default_admin() {
  $data = users_load();
  if (!empty($data['users'])) return;
  $defaultPass = bin2hex(random_bytes(4)); // 8 hex chars
  $hash = password_hash($defaultPass, PASSWORD_DEFAULT);
  $data['users'][] = ['username'=>'admin','password_hash'=>$hash,'role'=>'admin','created'=>date('c')];
  users_save($data);
  $_SESSION['__first_admin_password'] = $defaultPass;
}

function auth_login($username, $password) {
  $u = user_find($username);
  if (!$u) return false;
  if (!isset($u['password_hash'])) return false;
  if (!password_verify((string)$password, $u['password_hash'])) return false;
  // Success
  session_regenerate_id(true);
  $_SESSION['user'] = ['username'=>$u['username'], 'role'=>$u['role'] ?? 'user'];
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
