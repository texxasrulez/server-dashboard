<?php
// api/cron_health.php — report last cron-touch times and expected next due
require __DIR__.'/_guard.php'; guard_api(['key'=>'cron_health','require_token'=>false,'type'=>'json']);
require_once __DIR__ . '/_state_path.php';
header('Content-Type: application/json; charset=utf-8');

function cfg_get($dot, $default=null){
  $file = dirname(__DIR__) . '/config/local.json';
  if (!is_file($file)) return $default;
  $raw = @file_get_contents($file);
  if ($raw === false) return $default;
  $j = json_decode($raw, true);
  if (!is_array($j)) return $default;
  $cur = $j;
  foreach (explode('.', $dot) as $k) {
    if (is_array($cur) && array_key_exists($k, $cur)) { $cur = $cur[$k]; } else { return $default; }
  }
  return $cur;
}

$now = time();
$primary_alert = dashboard_state_path('cron_last_alert.txt');
$primary_hist  = dashboard_state_path('cron_last_history.txt');
$legacy_state = dirname(__DIR__) . '/state';
$legacy_alert = $legacy_state . '/cron_last_alert.txt';
$legacy_hist  = $legacy_state . '/cron_last_history.txt';

$alerts_ts_primary = @intval(@file_get_contents($primary_alert));
$alerts_ts_legacy  = @intval(@file_get_contents($legacy_alert));
$alerts_ts = max($alerts_ts_primary, $alerts_ts_legacy);

$history_ts_primary = @intval(@file_get_contents($primary_hist));
$history_ts_legacy  = @intval(@file_get_contents($legacy_hist));
$history_ts = max($history_ts_primary, $history_ts_legacy);


$alerts_int  = intval(cfg_get('alerts.cron_interval_min', 10));
$history_int = intval(cfg_get('history.append_interval_min', 5));

function eval_status($last, $int_min, $now){
  if ($last <= 0) return ['status'=>'warn','age_sec'=>null,'next_due'=>null];
  $age = $now - $last;
  $int = max(1, $int_min) * 60;
  $next = $last + $int;
  $status = ($age <= 1.2*$int) ? 'ok' : (($age <= 2*$int) ? 'warn' : 'fail');
  return ['status'=>$status, 'age_sec'=>$age, 'next_due'=>$next];
}

$alerts = eval_status($alerts_ts, $alerts_int, $now);
$history = eval_status($history_ts, $history_int, $now);


// Extend with per-job health from config.cron.jobs (JSON string)
$jobs_raw = cfg_get('cron.jobs', '');
$jobs_arr = [];
if (is_string($jobs_raw) && strlen(trim($jobs_raw))) {
  $decoded = json_decode($jobs_raw, true);
  if (is_array($decoded)) { $jobs_arr = $decoded; }
}

$root = realpath(dirname(__DIR__));
$hbdir = dirname(dashboard_state_path('heartbeats/.keep'));
@mkdir($hbdir, 0775, true);
function slugify($s){
  $s = strtolower(trim($s));
  $s = preg_replace('/[^a-z0-9\-]+/','-', $s);
  $s = preg_replace('/\-+/','-', $s);
  return trim($s, '-');
}

function resolve_hb($root, $hbdir, $id, $given){
  if (!is_string($given) || $given==='') {
    return $hbdir . '/' . $id . '.txt';
  }
  // If relative, anchor to root; if absolute, use as-is then verify
  $p = $given;
  if ($p[0] !== '/' && !preg_match('/^[A-Za-z]:[\\\\\\/]/', $p)) {
    $p = $root . '/' . ltrim($p, '/');
  }
  $rp = realpath(dirname($p));
  if ($rp === false) { @mkdir(dirname($p), 0775, true); $rp = realpath(dirname($p)); }
  if ($rp === false) return $hbdir . '/' . $id . '.txt';
  // deny path escape
  if (strpos($rp, $root) !== 0) return $hbdir . '/' . $id . '.txt';
  return rtrim($rp,'/') . '/' . basename($p);
}

$jobs_out = [];
foreach ($jobs_arr as $job) {
  $label = isset($job['label']) ? (string)$job['label'] : '';
  $id    = isset($job['id'])    ? (string)$job['id']    : '';
  if ($id==='') $id = slugify($label);
  if ($id==='') $id = 'job_' . substr(sha1(json_encode($job)),0,8);
  $line  = isset($job['line'])  ? (string)$job['line']  : '';
  $stale = intval(isset($job['stale_min']) ? $job['stale_min'] : 5);
  $hb    = resolve_hb($root, $hbdir, $id, isset($job['heartbeat']) ? (string)$job['heartbeat'] : '');

  $last = null;
  if (is_file($hb)) {
    $raw = @file_get_contents($hb);
    if ($raw !== false && preg_match('/^\s*\d{9,}\s*$/', $raw)) {
      $last = intval($raw);
    } else {
      $last = @filemtime($hb);
    }
  } else {
    $last = 0;
  }
  $ev = eval_status($last, $stale, $now);
  $jobs_out[] = [
    'id'=>$id, 'label'=>$label, 'line'=>$line, 'stale_min'=>$stale,
    'heartbeat'=>$hb, 'last'=>$last, 'status'=>$ev['status'], 'next_due'=>$ev['next_due']
  ];
}


echo json_encode([
  'ok'=>true,
  'now'=>$now,
  'alerts'=>['last'=>$alerts_ts] + $alerts,
  'history'=>['last'=>$history_ts] + $history,
  'expect'=>['alerts_min'=>$alerts_int, 'history_min'=>$history_int],
  'jobs'=>$jobs_out
]);