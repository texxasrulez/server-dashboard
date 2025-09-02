<?php
// api/alerts_run.php — token-gated alerts runner
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../lib/Config.php';
require_once __DIR__ . '/../lib/ServerDiag.php';
require_once __DIR__ . '/../includes/mailer.php';

function _noise_cfg(){
  $file = dirname(__DIR__) . '/config/local.json';
  $j = @json_decode(@file_get_contents($file), true);
  $alerts = isset($j['alerts']) ? $j['alerts'] : [];
  return [
    'debounce_hours' => intval($alerts['debounce_hours'] ?? 0),
    'daily_digest'   => !empty($alerts['daily_digest']),
    'digest_hour'    => (string)($alerts['digest_hour'] ?? '08:00'),
    'email'          => (string)($alerts['email'] ?? '')
  ];
}
function _noise_key_from_items($items){
  $arr = [];
  foreach ($items as $r) { $arr[] = [strtolower($r['name'] ?? ''), strtolower($r['status'] ?? '')]; }
  return md5(json_encode($arr));
}
function _noise_debounce_should_suppress($key, $hours){
  if ($hours <= 0) return false;
  $file = dirname(__DIR__) . '/state/alerts_debounce.json';
  $map = @json_decode(@file_get_contents($file), true);
  if (!is_array($map)) $map = [];
  $now = time();
  $last = isset($map[$key]) ? intval($map[$key]) : 0;
  if ($last > 0 && ($now - $last) < ($hours*3600)) return true;
  return false;
}
function _noise_debounce_mark($key){
  $file = dirname(__DIR__) . '/state/alerts_debounce.json';
  $map = @json_decode(@file_get_contents($file), true);
  if (!is_array($map)) $map = [];
  $map[$key] = time();
  @file_put_contents($file, json_encode($map, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
}
function _noise_digest_append($items){
  $file = dirname(__DIR__) . '/state/alerts_digest_queue.jsonl';
  $fh = @fopen($file, 'ab');
  if ($fh) {
    $row = ['ts'=>time(), 'items'=>array_values($items)];
    @fwrite($fh, json_encode($row, JSON_UNESCAPED_SLASHES)."\n");
    @fclose($fh);
  }
}
function _noise_maybe_send_daily_digest(){
  $cfg = _noise_cfg();
  if (empty($cfg['daily_digest'])) return;
  $email = $cfg['email'];
  if ($email === '') return;

  $stateDir = dirname(__DIR__) . '/state';
  @mkdir($stateDir, 0775, true);
  $lastFile = $stateDir . '/alerts_last_digest.txt';
  $last = intval(@file_get_contents($lastFile));
  // compute today's HH:MM in server time
  $hhmm = $cfg['digest_hour'];
  if (!preg_match('/^(\d{2}):(\d{2})$/', $hhmm, $m)) { $m = [0, '08', '00']; }
  $t = time(); $dt = getdate($t);
  $target = mktime(intval($m[1]), intval($m[2]), 0, $dt['mon'], $dt['mday'], $dt['year']);
  if ($t < $target) return; // not time yet
  if ($last >= $target) return; // already sent today

  $qfile = $stateDir.'/alerts_digest_queue.jsonl';
  if (!is_file($qfile)) return;

  $since = $t - 24*3600;
  $agg = [];
  $fh = @fopen($qfile, 'rb');
  if ($fh) {
    while (($line = fgets($fh)) !== false) {
      $row = @json_decode($line, true);
      if (!$row || !is_array($row)) continue;
      $ts = intval($row['ts'] ?? 0);
      if ($ts < $since) continue;
      $items = isset($row['items']) && is_array($row['items']) ? $row['items'] : [];
      foreach ($items as $it) {
        $k = strtolower(($it['name'] ?? 'unknown'). '|' . ($it['status'] ?? ''));
        if (!isset($agg[$k])) $agg[$k] = ['name'=>$it['name'] ?? 'unknown','status'=>$it['status'] ?? '','count'=>0];
        $agg[$k]['count']++;
      }
    }
    fclose($fh);
  }
  if (!count($agg)) { @file_put_contents($lastFile, (string)time()); return; }

  $lines = [];
  $lines[] = 'Daily alert digest for last 24h';
  $lines[] = 'Time: '.date('c');
  $lines[] = '';
  foreach ($agg as $r) {
    $lines[] = sprintf('- %s — %s (%d hits)', $r['name'], strtoupper($r['status']), $r['count']);
  }
  $body = implode("\n", $lines);
  $subj = '[ServerDiag] Daily alert digest';

  $res = \Mailer::send($email, $subj, $body, []);
  if (!empty($res['ok'])) {
    @file_put_contents($lastFile, (string)time());
  } else {
    @file_put_contents($stateDir.'/mail_failures.log', '['.date('c').'] digest '.($res['error']??'fail')."\n", FILE_APPEND);
  }
}

\App\Config::init(dirname(__DIR__));

$cfg = \App\Config::all();
$alerts = $cfg['alerts'] ?? [];
$enabled = !empty($alerts['enabled']);

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$need = (string)($alerts['cron_token'] ?? '');
if (!$enabled) { echo json_encode(['ok'=>false,'error'=>'alerts disabled']); exit; }
if (!$need || !hash_equals($need, (string)$token)) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'bad token']); exit; }

// Quiet hours: "HH:MM-HH:MM" (local time). If within range, skip send.
$quiet = trim((string)($alerts['quiet_hours'] ?? ''));
if ($quiet !== '') {
  $now = new \DateTime('now');
  $parts = explode('-', $quiet);
  if (count($parts) === 2) {
    $start = \DateTime::createFromFormat('H:i', $parts[0]) ?: null;
    $end   = \DateTime::createFromFormat('H:i', $parts[1]) ?: null;
    if ($start && $end) {
      $s = (int)$start->format('Hi');
      $e = (int)$end->format('Hi');
      $n = (int)$now->format('Hi');
      $in = ($s <= $e) ? ($n >= $s && $n <= $e) : ($n >= $s || $n <= $e);
      if ($in) { echo json_encode(['ok'=>true,'suppressed'=>true,'reason'=>'quiet hours']); exit; }
    }
  }
}

// Fake HTTPS context for TLS check host
if (empty($_SERVER['HTTP_HOST'])) {
  // derive from configured site url if available, else leave blank
  $_SERVER['HTTP_HOST'] = $_SERVER['SERVER_NAME'] ?? '';
}

$items = \App\ServerDiag::scan_quick();
$score = \App\ServerDiag::score($items);

// Decide if we should alert
$min = strtolower((string)($alerts['min_severity'] ?? 'warn'));
$rank = function($s){ $s=strtolower((string)$s); return $s==='fail'?2:($s==='warn'?1:0); };
$minRank = ($min==='fail')?2:1;
$bad = array_values(array_filter($items, function($r) use ($rank, $minRank){ return $rank($r['status'] ?? 'warn') >= $minRank; }));

$__NOISE = _noise_cfg();
$__KEY = _noise_key_from_items($bad);
$__DEBOUNCE_H = intval($__NOISE['debounce_hours']);
$__SUPPRESS = _noise_debounce_should_suppress($__KEY, $__DEBOUNCE_H);
if (!$__SUPPRESS && count($bad) > 0) { _noise_digest_append($bad); }
$sent = false; $targets = [];

if (!$__SUPPRESS && count($bad) > 0) {
  // Webhook JSON
  $wh = trim((string)($alerts['webhook_url'] ?? ''));
  if ($wh !== '') {
    $payload = json_encode([
      'project' => basename(dirname(__DIR__)),
      'time' => date('c'),
      'score' => $score,
      'bad_count' => count($bad),
      'min_severity' => $min,
      'items' => $bad
    ]);
    $ctx = stream_context_create([
      'http' => ['method'=>'POST','header'=>"Content-Type: application/json\r\n",'content'=>$payload,'timeout'=>5]
    ]);
    @file_get_contents($wh, false, $ctx);
    $sent = true; $targets[] = 'webhook';
  }
  // Email (best-effort)
$em = trim((string)($alerts['email'] ?? ''));
if ($em !== '') {
  $subj = '[ServerDiag] ' . count($bad) . ' issue(s) at ' . ( $_SERVER['HTTP_HOST'] ?? 'host' );
  $lines = [];
  foreach ($bad as $r) { $lines[] = sprintf('- %s: %s (%s)', $r['name'], $r['status'], $r['details'] ?? ''); }
  $body = "Time: " . date('c') . "\nScore: " . round($score*100,1) . "%\nMin severity: ".$min."\n\n" . implode("\n", $lines) . "\n";
  $opts = [];
  if ($from !== '') $opts['from'] = $from;
  if ($reply !== '') $opts['reply_to'] = $reply;

  $res = \Mailer::send($em, $subj, $body, $opts);
  if (!empty($res['ok'])) { $sent = true; $targets[] = 'email'; }
}
  }
}


if (!$__SUPPRESS && !empty($sent)) { _noise_debounce_mark($__KEY); }
_noise_maybe_send_daily_digest();
echo json_encode(['ok'=>true, 'score'=>$score, 'bad'=>count($bad), 'sent'=>$sent, 'targets'=>$targets, 'items'=>$items]);
