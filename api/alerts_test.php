<?php
// api/alerts_test.php — admin-only test mail endpoint (PHP 5 compatible)
header('Content-Type: application/json; charset=UTF-8');

// Absolute includes
require_once dirname(__DIR__) . '/includes/init.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/lib/Config.php';
require_once dirname(__DIR__) . '/includes/mailer.php';

// Convert warnings/notices into exceptions for cleaner JSON errors
set_error_handler(function($sev, $msg, $file, $line){
  throw new Exception($msg, 0);
});

try {
  if (!function_exists('require_admin')) { throw new Exception('admin guard missing'); }
  require_admin();

  if (!isset($_SERVER['REQUEST_METHOD']) || strtoupper($_SERVER['REQUEST_METHOD']) !== 'POST') {
    http_response_code(405);
    echo json_encode(array('ok'=>false,'error'=>'POST only'));
    exit;
  }

  // Parse JSON body
  $raw = file_get_contents('php://input');
  $input = $raw ? json_decode($raw, true) : array();
  if (!is_array($input)) $input = array();

  // Resolve recipient 'to'
  $to = '';
  if (isset($input['to'])) $to = trim((string)$input['to']);
  if ($to === '') {
    $cfgFile = dirname(__DIR__) . '/config/local.json';
    $cfgJson = @file_get_contents($cfgFile);
    $cfg = $cfgJson ? json_decode($cfgJson, true) : array();
    if (is_array($cfg)) {
      if (isset($cfg['alerts']) && is_array($cfg['alerts']) && !empty($cfg['alerts']['email'])) {
        $to = trim((string)$cfg['alerts']['email']);
      }
      if ($to === '' && isset($cfg['security']) && is_array($cfg['security']) && !empty($cfg['security']['admin_emails']) && is_array($cfg['security']['admin_emails'])) {
        $first = reset($cfg['security']['admin_emails']);
        if ($first) $to = trim((string)$first);
      }
      if ($to === '' && isset($cfg['mail']) && is_array($cfg['mail']) && !empty($cfg['mail']['mail_from'])) {
        $to = trim((string)$cfg['mail']['mail_from']);
      }
    }
  }
  if ($to === '') { throw new InvalidArgumentException("Missing 'to' recipient for test send"); }

  // Optional overrides
  $opts = array();
  if (isset($input['from'])) $opts['from'] = (string)$input['from'];
  if (isset($input['reply_to'])) $opts['reply_to'] = (string)$input['reply_to'];

  $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'server';
  $subject = "[ServerDiag] Test alert from " . $host;
  $body = "This is a test alert generated from Config → Alerts.\n\nHost: " . $host . "\nTime: " . date('c') . "\n";

  $res = Mailer::send($to, $subject, $body, $opts);
  if (empty($res['ok'])) {
    $err = (isset($res['error']) ? $res['error'] : 'send failed');
    http_response_code(500);
    echo json_encode(array('ok'=>false,'error'=>$err));
    exit;
  }

  echo json_encode(array('ok'=>true,'to'=>$to,'from'=>(isset($opts['from']) ? $opts['from'] : null)));
  exit;

} catch (Exception $e) {
  http_response_code(500);
  echo json_encode(array('ok'=>false,'error'=>$e->getMessage()));
  exit;
}
