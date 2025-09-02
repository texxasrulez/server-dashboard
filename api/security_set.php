<?php
require_once __DIR__ . '/../includes/init.php';
header('Content-Type: application/json');

$SEC_FILE = DATA_DIR . '/security_config.json';
if (!is_dir(DATA_DIR)) { @mkdir(DATA_DIR, 0775, true); }

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) { echo json_encode(['ok'=>false,'error'=>'invalid body']); exit; }

// preserve existing smtp_pass when client sends empty
if (empty($body['smtp_pass']) && file_exists($SEC_FILE)) {
  $cur_raw = @file_get_contents($SEC_FILE);
  $cur = json_decode($cur_raw, true);
  if (is_array($cur) && !empty($cur['smtp_pass'])) {
    $body['smtp_pass'] = $cur['smtp_pass'];
  }
}

// atomic write
$tmp = $SEC_FILE . '.tmp';
if (@file_put_contents($tmp, json_encode($body, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)) === false) {
  echo json_encode(['ok'=>false,'error'=>'write failed']); exit;
}
@chmod($tmp, 0640);
@rename($tmp, $SEC_FILE);


// also mirror CRON_TOKEN into data/cron_token.txt for cron/probe endpoints
try {
  
  // Robust cron token extraction: accept multiple shapes (flat and nested)
  $tok = '';
  // flat keys first (uppercase then lowercase)
  if (!$tok && !empty($body['CRON_TOKEN'])) $tok = (string)$body['CRON_TOKEN'];
  if (!$tok && !empty($body['cron_token'])) $tok = (string)$body['cron_token'];
  // nested common shapes (alerts.cron_token, security.cron_token, cron.token, api.cron_token, history.token)
  if (!$tok && isset($body['alerts']) && is_array($body['alerts']) && !empty($body['alerts']['cron_token'])) $tok = (string)$body['alerts']['cron_token'];
  if (!$tok && isset($body['security']) && is_array($body['security']) && !empty($body['security']['cron_token'])) $tok = (string)$body['security']['cron_token'];
  if (!$tok && isset($body['cron']) && is_array($body['cron']) && !empty($body['cron']['token'])) $tok = (string)$body['cron']['token'];
  if (!$tok && isset($body['api']) && is_array($body['api']) && !empty($body['api']['cron_token'])) $tok = (string)$body['api']['cron_token'];
  if (!$tok && isset($body['history']) && is_array($body['history']) && !empty($body['history']['token'])) $tok = (string)$body['history']['token'];
  if ($tok !== '') {
    $file = DATA_DIR . '/cron_token.txt';
    $tmp  = $file . '.tmp';
    @file_put_contents($tmp, $tok . PHP_EOL);
    @chmod($tmp, 0640);
    @rename($tmp, $file);
  }
} catch (Throwable $e) { /* ignore */ }


echo json_encode(['ok'=>true]);
