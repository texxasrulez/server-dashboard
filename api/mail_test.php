<?php
require_once __DIR__ . '/../includes/init.php';
if (file_exists(__DIR__ . '/../includes/auth.php')) require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';
header('Content-Type: application/json');

$ok = false;
if (!empty($_SESSION['user']) && (($_SESSION['user']['role'] ?? '') === 'admin')) $ok = true;
elseif (function_exists('user_is_admin') && user_is_admin()) $ok = true;
elseif (function_exists('is_admin') && is_admin()) $ok = true;

$given = $_GET['token'] ?? $_POST['token'] ?? '';
$expected = null;
if (defined('CRON_TOKEN')) $expected = CRON_TOKEN;
if (!$expected) $expected = getenv('DASH_CRON_TOKEN');
if (!$expected) { $f = __DIR__ . '/../data/cron_token.txt'; if (file_exists($f)) $expected = trim(file_get_contents($f)); }
if (!$ok && $expected && hash_equals($expected, $given)) $ok = true;

if (!$ok) { http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }

$to = $_POST['to'] ?? $_GET['to'] ?? mailer_env('MAIL_FROM', '');
if (!$to) { http_response_code(422); echo json_encode(['error'=>'missing "to"']); exit; }
$ts = date('c');
$subject = 'Test email from Dashboard @ '.$ts;
$body = "This is a test email sent at $ts via transport: ".Mailer::transport()."\nOrigin: ".($_SERVER['HTTP_HOST'] ?? 'cli');

$res = Mailer::send($to, $subject, $body, []);
echo json_encode(['ok'=>!!($res['ok']??false), 'transport'=>Mailer::transport(), 'result'=>$res], JSON_UNESCAPED_SLASHES);
