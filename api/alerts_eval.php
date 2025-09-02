<?php
require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/_state_path.php';
require_once __DIR__ . '/../includes/auth.php';
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
  foreach ($items as $r) { $arr.append([strtolower($r['name'] ?? ''), strtolower($r['status'] ?? '')]); }
  return md5(json_encode($arr));
}
function _noise_debounce_should_suppress($key, $hours){
  if ($hours <= 0) return false;
  $file = dashboard_state_path('alerts_debounce.json');
  $map = @json_decode(@file_get_contents($file), true);
  if (!is_array($map)) $map = [];
  $now = time();
  $last = isset($map[$key]) ? intval($map[$key]) : 0;
  if ($last > 0 && ($now - $last) < ($hours*3600)) return true;
  return false;
}
function _noise_debounce_mark($key){
  $file = dashboard_state_path('alerts_debounce.json');
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
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
function __mark_cron($which){
  $map = ['alerts'=>'cron_last_alert.txt', 'history'=>'cron_last_history.txt'];
  if (!isset($map[$which])) return;
  $primary = dashboard_state_path($map[$which]);
  $legacy_state = dirname(__DIR__) . '/state'; @mkdir($legacy_state, 0775, true);
  $legacy = $legacy_state . '/' . $map[$which];
  $ts = time();
  @file_put_contents($primary, (string)$ts);
  @file_put_contents($legacy,  (string)$ts);
}


$ok = false;
if (!empty($_SESSION['user']) && (($_SESSION['user']['role'] ?? '') === 'admin')) { $ok = true; }
$given = $_GET['token'] ?? $_POST['token'] ?? '';
$expected = null;
if (defined('CRON_TOKEN')) $expected = CRON_TOKEN;
if (!$expected) $expected = getenv('DASH_CRON_TOKEN');
if (!$expected) { $f = __DIR__ . '/../data/cron_token.txt'; if (file_exists($f)) $expected = trim(file_get_contents($f)); }
if (!$expected) { $f2 = __DIR__ . '/../data/cron_token.txt'; if (is_file($f2)) $expected = trim(@file_get_contents($f2)); }
if ($expected && hash_equals($expected, $given)) { $ok = true; }
if (!$ok) { http_response_code(403); echo json_encode(['error' => 'forbidden']); exit; }

$probe = ((isset($_GET['probe']) && $_GET['probe'] == '1') || (isset($_POST['probe']) && $_POST['probe'] == '1'));
$dry   = isset($_GET['dry'])   && $_GET['dry'] == '1';

if ($probe) {
  $probe_script = __DIR__ . '/services_probe_all.php';
  if (is_file($probe_script)) {
    try { ob_start(); include $probe_script; $probe_out = @ob_get_clean(); } catch (Throwable $e) { @ob_end_clean(); $probe_out=''; }
  }
}

$dataDir  = __DIR__ . '/../data';
$stateDir = dirname(__DIR__) . '/state'; // legacy var (keep), but use dashboard_state_path for files
@mkdir($dataDir, 0775, true);
@mkdir($stateDir, 0775, true);

$alertsPath = $dataDir . '/alerts.json';
if (!file_exists($alertsPath)) { file_put_contents($alertsPath, json_encode(['items'=>[]], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)); }
$alerts = json_decode(@file_get_contents($alertsPath), true);
$alerts = is_array($alerts) ? $alerts : ['items'=>[]];
$rules  = isset($alerts['items']) && is_array($alerts['items']) ? $alerts['items'] : [];

$statusPath = $stateDir . '/services_status.json';
$statusPayload = json_decode(@file_get_contents($statusPath), true) ?: [];
$svcLatest = isset($statusPayload['items']) && is_array($statusPayload['items']) ? $statusPayload['items'] : [];
$svcMap = [];
foreach ($svcLatest as $it) { if (!empty($it['id'])) $svcMap[$it['id']] = $it; }

function cmp($op, $a, $b) {
  switch ($op) {
    case '>':  return $a >  $b;
    case '>=': return $a >= $b;
    case '==': return $a == $b;
    case '<=': return $a <= $b;
    case '<':  return $a <  $b;
    case '!=': return $a != $b;
    default:   return false;
  }
}

$now = time();
$fired = [];

foreach ($rules as &$r) {
  if (empty($r['enabled'])) continue;
  $sid = $r['service_id'] ?? '';
  $metric = $r['metric'] ?? 'status';
  $op = $r['op'] ?? '>';
  $threshold = $r['threshold'] ?? 1;
  $consecutive = max(1, intval($r['consecutive'] ?? 3));
  $cooldown = max(1, intval($r['cooldown_min'] ?? 5)) * 60;

  $svc = $svcMap[$sid] ?? null;
  if (!$svc) continue;

  $v = null;
  if ($metric === 'status')       $v = !empty($svc['ok']) ? 1 : 0;
  elseif ($metric === 'latency_ms')     $v = isset($svc['latency_ms']) ? floatval($svc['latency_ms']) : null;
  elseif ($metric === 'http_code')      $v = isset($svc['http_code']) ? intval($svc['http_code']) : null;
  elseif ($metric === 'packet_loss_pct')$v = isset($svc['packet_loss_pct']) ? floatval($svc['packet_loss_pct']) : null;
  if ($v === null) continue;

  $pass = cmp($op, $v, $threshold);
  if (!isset($r['consecutive_count'])) $r['consecutive_count'] = 0;
  $r['consecutive_count'] = $pass ? ($r['consecutive_count'] + 1) : 0;

  $last = isset($r['last_triggered']) ? intval($r['last_triggered']) : 0;
  $cool_ok = ($now - $last) >= $cooldown;

  if ($r['consecutive_count'] >= $consecutive && $cool_ok && $pass) {
    $event = [
      'ts' => $now,
      'alert_id'   => $r['id'] ?? '',
      'alert_name' => $r['name'] ?? '',
      'service_id' => $sid,
      'service_name' => $r['service_name'] ?? '',
      'metric' => $metric,
      'op' => $op,
      'threshold' => $threshold,
      'value' => $v,
      'severity' => $r['severity'] ?? 'warn',
    ];
    $fired[] = $event;

    $r['last_triggered'] = $now;
    $r['times_triggered'] = intval($r['times_triggered'] ?? 0) + 1;
    $r['consecutive_count'] = 0;

    if (!$dry) {
      $email = $r['notify']['email'] ?? '';
      /*NOISE_WRAP_START*/
      $__NOISE = _noise_cfg();
      $__DEBOUNCE_H = intval($__NOISE['debounce_hours']);
      $__KEY = md5('eval:' . ($r['id'] ?? '') . '|' . ($r['name'] ?? '') . '|' . $sid . '|' . $metric . '|' . $op . '|' . $threshold);
      $__SUPPRESS = _noise_debounce_should_suppress($__KEY, $__DEBOUNCE_H);

      if ($email) {
        $sub = '[Alert] ' . (($r['name'] ?? '') ?: 'Rule fired');
        $lines = [];
        $lines[] = 'Alert: ' . ($r['name'] ?? '');
        $lines[] = 'Service: ' . (($r['service_name'] ?? '') ?: $sid);
        $lines[] = 'Metric: ' . $metric . ' ' . $op . ' ' . $threshold;
        $lines[] = 'Value: ' . $v;
        $lines[] = 'Severity: ' . ($r['severity'] ?? 'warn');
        $lines[] = 'Time: ' . date('c', $now);
        $body = implode("\n", $lines) . "\n\nJSON:\n" . json_encode($event, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
        if (!$__SUPPRESS) { $res = Mailer::send($email, $sub, $body, []); } else { $res=['ok'=>false,'error'=>'suppressed']; }
        if (!$res['ok']) {
          @file_put_contents($stateDir.'/mail_failures.log', '['.date('c').'] '.$email.' '.$sub.' :: '.($res['error']??'fail')."\n", FILE_APPEND);
        }
      }
      if (!$__SUPPRESS) { _noise_debounce_mark($__KEY); }
      _noise_digest_append([$event]);
      $webhook = $r['notify']['webhook_url'] ?? '';
      if ($webhook) {
        $ctx = stream_context_create(['http' => ['method'=>'POST','header'=>"Content-Type: application/json\r\n", 'content'=>json_encode($event, JSON_UNESCAPED_SLASHES), 'timeout'=>5]]);
        if (!$__SUPPRESS) { @file_get_contents($webhook, false, $ctx); }
      }
    }
  }
}
unset($r);

if (!$dry) {
  $tmp = $alertsPath . '.tmp';
  file_put_contents($tmp, json_encode(['items'=>$rules], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), LOCK_EX);
  @rename($tmp, $alertsPath);
}

if (!$dry && count($fired)) {
  $eventsFile = dashboard_state_path('alerts_events.jsonl');
  $fh = @fopen($eventsFile, 'ab');
  if ($fh) {
    foreach ($fired as $ev) { fwrite($fh, json_encode($ev, JSON_UNESCAPED_SLASHES) . "\n"); }
    fclose($fh);
  }
}


_noise_maybe_send_daily_digest();
__mark_cron('alerts');
if (isset($_GET['probe']) && $_GET['probe'] == '1') { __mark_cron('history'); }
echo json_encode(['ok'=>true, 'now'=>$now, 'fired_count'=>count($fired), 'fired'=>$fired], JSON_UNESCAPED_SLASHES);

// Add function to log alerts to alerts_events.jsonl
function log_alert_to_file($alert_data) {
  $file = dashboard_state_path('alerts_events.jsonl');
  $fh = @fopen($file, 'ab');
  if ($fh) {
    $row = ['ts' => time(), 'alert' => $alert_data]; // Alert data with timestamp
    @fwrite($fh, json_encode($row, JSON_UNESCAPED_SLASHES) . "\n");
    @fclose($fh);
  }
}

// Example usage where an alert event occurs (down status or critical latency)
function trigger_alert($service_id, $status, $latency_ms) {
  if ($status == 'down' || $latency_ms > 1000) { // Conditions for a critical alert
    $alert_data = [
      'service_id' => $service_id,
      'status' => $status,
      'latency_ms' => $latency_ms,
      'message' => 'Service is down or latency is too high.'
    ];
    log_alert_to_file($alert_data);  // Log the alert to file
  }
}
