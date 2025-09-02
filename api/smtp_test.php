<?php
// Admin-only SMTP connectivity + From header test
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../lib/Config.php';
require_once __DIR__ . '/../includes/mailer.php';

header('Content-Type: application/json');
try {
  require_admin();
  \App\Config::init(dirname(__DIR__));

  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'POST only']); exit;
  }
  $payload = json_decode(file_get_contents('php://input'), true) ?: [];
  if (!isset($payload['_csrf']) || empty($_SESSION['csrf']) || $payload['_csrf'] !== $_SESSION['csrf']) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'CSRF']); exit;
  }

  $to = trim((string)($payload['to'] ?? ''));
  if ($to === '') {
    // fallbacks: Alerts email, or mail_from
    $to = (string)\App\Config::get('alerts.email', '');
    if ($to === '') {
      $cfg_file = dirname(__DIR__) . '/config/local.json';
  $cfg_json = @file_get_contents($cfg_file);
  $cfg = json_decode($cfg_json, true);
  if (!is_array($cfg)) $cfg = [];
  $mail_cfg = isset($cfg['mail']) && is_array($cfg['mail']) ? $cfg['mail'] : [];
$sec_file = DATA_DIR . '/security_config.json';
      if (is_file($sec_file)) {
        $sec = json_decode(@file_get_contents($sec_file), true);
        if (is_array($sec)) $to = trim((string)($sec['mail_from'] ?? ''));
      }
    }
  }
  if ($to === '') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'No recipient']); exit; }

  // From/Reply-To from Security config
  $cfg_file = dirname(__DIR__) . '/config/local.json';
  $cfg_json = @file_get_contents($cfg_file);
  $cfg = json_decode($cfg_json, true);
  if (!is_array($cfg)) $cfg = [];
  $mail_cfg = isset($cfg['mail']) && is_array($cfg['mail']) ? $cfg['mail'] : [];
$sec_file = DATA_DIR . '/security_config.json';
  $sec = [];
  if (is_file($sec_file)) {
    $sec = json_decode(@file_get_contents($sec_file), true);
    if (!is_array($sec)) $sec = [];
  }
  $from = trim((string)($mail_cfg['mail_from'] ?? ($sec['mail_from'] ?? '')));
  $reply = trim((string)($mail_cfg['mail_replyto'] ?? ($sec['mail_replyto'] ?? '')));
  $opts = [];
  if ($from !== '') $opts['from'] = $from;
  if ($reply !== '') $opts['reply_to'] = $reply;

  $host = $_SERVER['HTTP_HOST'] ?? 'server';
  $subject = "[SMTP Test] from {$host}";
  $body = "This is a direct SMTP test triggered from Config → Integrations.\n\n"
        . "To: {$to}\n"
        . "From: " . ($opts['from'] ?? '(default)') . "\n"
        . "Time: " . date('c') . "\n";

  $res = \Mailer::send($to, $subject, $body, $opts);
  if (empty($res['ok'])) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>($res['error'] ?? 'send failed')]); exit; }

  echo json_encode(['ok'=>true, 'to'=>$to, 'from'=>($opts['from'] ?? null)]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
