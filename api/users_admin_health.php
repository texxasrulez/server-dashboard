<?php
// api/users_admin_health.php — checks data/users.json for admin with empty password
header('Content-Type: application/json; charset=UTF-8');

$path = dirname(__DIR__) . '/data/users.json';
$result = array('ok'=>true, 'default_admin_unsafe'=>false, 'source'=>'data/users.json');

function looks_empty_password($u) {
  if (!is_array($u)) return true;
  foreach (array('password','pass','hash','password_hash') as $k) {
    if (array_key_exists($k, $u)) {
      $v = $u[$k];
      if ($v === null) return true;
      if (is_string($v) && trim($v) === '') return true;
      if (is_string($v) && strlen($v) > 10) return false; // hash present
      if (!$v) return true;
    }
  }
  // If none of those keys exist, treat as empty
  return true;
}

try {
  if (!is_file($path)) {
    echo json_encode($result);
    exit;
  }
  $json = @file_get_contents($path);
  if ($json === false) { echo json_encode($result); exit; }
  $data = json_decode($json, true);
  if (!$data) { echo json_encode($result); exit; }

  $unsafe = false;
  $arr = array();
  if (isset($data['users']) && is_array($data['users'])) {
    $arr = $data['users'];
  } else if (is_array($data)) {
    $arr = $data;
  }

  foreach ($arr as $k=>$u) {
    if (is_array($u)) {
      $name = '';
      if (isset($u['username'])) $name = (string)$u['username'];
      else if (isset($u['user'])) $name = (string)$u['user'];
      else if (is_string($k)) $name = (string)$k;
      if (strtolower($name) === 'admin') {
        if (looks_empty_password($u)) { $unsafe = true; break; }
      }
    } else if (is_string($k) && strtolower($k) === 'admin') {
      if (!$u || trim((string)$u) === '') { $unsafe = true; break; }
    }
  }

  $result['default_admin_unsafe'] = $unsafe;
  echo json_encode($result);
} catch (Exception $e) {
  http_response_code(500);
  echo json_encode(array('ok'=>false, 'error'=>$e->getMessage()));
}
