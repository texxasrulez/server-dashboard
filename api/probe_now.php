<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');

$ok = false;
if (!empty($_SESSION['user']) && (($_SESSION['user']['role'] ?? '') === 'admin')) $ok = true;
$hdrAuth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$hdrTok  = $_SERVER['HTTP_X_CRON_TOKEN'] ?? '';
$given   = $_GET['token'] ?? $_POST['token'] ?? '';
if (!$given && $hdrTok) $given = $hdrTok;
if (!$given && stripos($hdrAuth, 'Bearer ') === 0) $given = trim(substr($hdrAuth, 7));
$expected = null;
if (defined('CRON_TOKEN')) $expected = CRON_TOKEN;
if (!$expected) $expected = getenv('DASH_CRON_TOKEN');
if (!$expected) { $f = __DIR__ . '/../data/cron_token.txt'; if (is_file($f)) $expected = trim(@file_get_contents($f)); }
if ($expected && $given && hash_equals($expected, $given)) $ok = true;
if (!$ok) { http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }

$script = __DIR__ . '/services_probe_all.php';
if (!is_file($script)) {
  $alt = __DIR__ . '/probe_all.php';
  if (is_file($alt)) $script = $alt;
}

$level = ob_get_level();
ob_start();
$ran = false; $err = null;
try {
  if (is_file($script)) { include $script; $ran = true; } else { $err = 'probe script not found'; }
} catch (Throwable $e) { $err = $e->getMessage(); }
$out = ob_get_clean();
while (ob_get_level() > $level) { ob_end_clean(); }

if ($ran && !$err) echo json_encode(['ok'=>true,'ran'=>true], JSON_UNESCAPED_SLASHES);
else echo json_encode(['ok'=>false,'error'=>$err?:'probe failed'], JSON_UNESCAPED_SLASHES);
