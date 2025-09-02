<?php
// api/server_tests.php — gentle server diagnostics (no burn-in)
// Returns JSON; requires logged-in session
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../lib/Config.php';
\App\Config::init(dirname(__DIR__));
require_once __DIR__ . '/_state_path.php';



// lightweight ping helper (degrades gracefully if ping binary not present)
if (!function_exists('st_ping_host')) {
  function st_ping_host($host, $timeoutSec = 1.0){
    $host = preg_replace('/[^A-Za-z0-9\.\-_:]/','', (string)$host);
    if ($host === '') return ['ok'=>false,'ms'=>null];
    $cmds = [
      sprintf('ping -c 1 -W %d -n %s 2>&1', max(1,(int)$timeoutSec), escapeshellarg($host)),
      sprintf('ping -c 1 -w %d -n %s 2>&1', max(1,(int)$timeoutSec), escapeshellarg($host)),
    ];
    foreach ($cmds as $cmd){
      $t0 = microtime(true);
      $out = @shell_exec($cmd);
      $elapsed = (int)round((microtime(true)-$t0)*1000);
      if (is_string($out) && $out !== ''){
        if (preg_match('/time=([0-9]+\.?[0-9]*)\s*ms/i', $out, $m)){
          return ['ok'=>true,'ms'=>(float)$m[1], 'elapsed_ms'=>$elapsed];
        }
        if (stripos($out, '1 received') !== false || stripos($out,'0% packet loss') !== false){
          return ['ok'=>true,'ms'=>$elapsed, 'elapsed_ms'=>$elapsed];
        }
      }
    }
    return ['ok'=>false,'ms'=>null];
  }
}
// --- scoring helpers (average weighted status) ---
if (!function_exists('status_weight')) {
  function status_weight($st){
    $s = strtolower((string)$st);
    if ($s === 'ok' || $s === 'good' || $s === 'pass' || $s === 'success' || $s === 'up' || $s === 'info') return 1.0;
    if ($s === 'warn' || $s === 'warning' || $s === 'medium' || $s === 'notice') return 0.6;
    // fail/error/down/bad/unknown
    return 0.2;
  }
}
if (!function_exists('score')) {
  function score($items){
    if (!is_array($items) || !count($items)) return 1.0;
    $sum = 0.0; $n = 0;
    foreach ($items as $it){
      $sum += status_weight($it['status'] ?? 'warn');
      $n++;
    }
    return $n ? max(0.0, min(1.0, $sum / $n)) : 1.0;
  }
}



// --- threshold helpers ---
if (!function_exists('st_get_cpu_cores')) {
  function st_get_cpu_cores(){
    $n = 0;
    if (function_exists('shell_exec')) {
      $out = @shell_exec('nproc 2>/dev/null');
      if ($out) $n = (int)trim($out);
      if ($n <= 0) {
        $out = @shell_exec("getconf _NPROCESSORS_ONLN 2>/dev/null");
        if ($out) $n = (int)trim($out);
      }
    }
    if ($n <= 0 && !empty($_SERVER['NUMBER_OF_PROCESSORS'])) $n = (int)$_SERVER['NUMBER_OF_PROCESSORS'];
    if ($n <= 0) $n = 1;
    return $n;
  }
}
if (!function_exists('apply_threshold_overrides')) {
  function apply_threshold_overrides($items){
    $warnDisk = (int)\App\Config::get('server_tests.disk_warn_free_pct', 10);
    $failDisk = (int)\App\Config::get('server_tests.disk_fail_free_pct', 5);
    $warnLoad = (float)\App\Config::get('server_tests.load_warn_per_core', 1.0);
    $failLoad = (float)\App\Config::get('server_tests.load_fail_per_core', 2.0);
    $cores = st_get_cpu_cores();
    foreach ($items as &$it){
      $name = (string)($it['name'] ?? '');
      $det  = (string)($it['details'] ?? '');

      if ($name === 'Disk Free' && preg_match('/([0-9]+(?:\.[0-9]+)?)%\s*free/i', $det, $m)) {
        $pct = (float)$m[1];
        $st = ($pct >= $warnDisk) ? 'ok' : (($pct >= $failDisk) ? 'warn' : 'fail');
        $it['status'] = $st;
      }

      if (strpos($name, 'Load Avg') === 0 && preg_match('/^([0-9]+(?:\.[0-9]+)?)/', $det, $m)) {
        $one = (float)$m[1];
        $per = $cores > 0 ? ($one / $cores) : $one;
        $st = ($per < $warnLoad) ? 'ok' : (($per < $failLoad) ? 'warn' : 'fail');
        $it['status'] = $st;
      }
    }
    return $items;
  }
}
// --- services reachability ---
if (!function_exists('scan_services')) {
  function scan_services($override = null){
    $targets = \App\Config::get('server_tests.service_targets', []);
    if (!is_array($targets)) $targets = [];
    // Auto-import enabled services from Services registry
    try {
      $servicesPath = __DIR__.'/../data/services.json';
      if (@file_exists($servicesPath)){
        $data = json_decode(@file_get_contents($servicesPath), true);
        if (is_array($data) && isset($data['items']) && is_array($data['items'])){
          foreach ($data['items'] as $svc){
            if (!empty($svc['enabled'])){
              $name = trim((string)($svc['name'] ?? ''));
              $host = trim((string)($svc['host'] ?? ''));
              $port = (int)($svc['port'] ?? 0);
              $check= strtolower((string)($svc['check'] ?? 'tcp'));
              $path = trim((string)($svc['path'] ?? '/'));
              if ($check === 'http'){
                $scheme = ($port === 443) ? 'https' : 'http';
                $url = $scheme.'://'.$host.($port && !in_array($port,[80,443])?(':'.$port):'').($path ?: '/');
                $targets[] = $url . '|' . ($name ?: ($host.':'.$port));
              } elseif ($check === 'ping'){
                $targets[] = 'ping://'.$host.'|' . ($name ?: $host.' (ping)');
              } else {
                if ($host !== '' && $port > 0) $targets[] = $host.':'.$port.'|' . ($name ?: ($host.':'.$port));
              }
            }
          }
        }
      }
    } catch (Throwable $e) { /* ignore */ }

    if (is_array($override) && count($override)) $targets = $override;
    $timeout_ms = (int)\App\Config::get('server_tests.service_timeout_ms', 1000);
    $timeout = max(0.1, $timeout_ms / 1000.0);
    $out = [];
    foreach ($targets as $s){
      $s = trim((string)$s);
      if ($s === '') continue;
      $label = '';
      $spec = $s;
      $extra = [];

      // split by pipes: first piece is host:port or URL, then optional label, then optional key=val pairs
      $parts = array_map('trim', explode('|', $s));
      if (count($parts) > 0) $spec = $parts[0];
      if (count($parts) > 1) $label = $parts[1];
      if (count($parts) > 2) {
        for ($i=2; $i<count($parts); $i++){
          if (strpos($parts[$i],'=') !== false){
            list($k,$v) = array_map('trim', explode('=', $parts[$i], 2));
            $extra[strtolower($k)] = $v;
          }
        }
      }

      $scheme = '';
      if (stripos($spec, 'http://') === 0 || stripos($spec,'https://') === 0) {
        // HTTP(S) content check
        $u = @parse_url($spec);
        if (!$u || empty($u['host'])) {
          $out[] = ['name'=> ($label ?: $s), 'status'=>'warn', 'details'=>'invalid url'];
          continue;
        }
        $scheme = strtolower($u['scheme']);
        $host = $u['host'];
        $port = isset($u['port']) ? (int)$u['port'] : (($scheme==='https')?443:80);
        $path = ($u['path'] ?? '/');
        if (!empty($u['query'])) $path .= '?'.$u['query'];

        $expectCode = isset($extra['status']) ? (int)$extra['status'] : 200;
        $contains = isset($extra['contains']) ? (string)$extra['contains'] : '';

        $ctxOpts = [
          'http' => [
            'method' => 'GET',
            'timeout' => $timeout,
            'ignore_errors' => true,
            'header' => "User-Agent: ServerDiag/1\r\nAccept: */*\r\n",
          ]
        ];
        if ($scheme === 'https') {
          $ctxOpts['ssl'] = ['verify_peer'=>true, 'verify_peer_name'=>true, 'capture_peer_cert'=>false, 'SNI_enabled'=>true, 'allow_self_signed'=>true];
        }
        $ctx = stream_context_create($ctxOpts);
        $t0 = microtime(true);
        $body = @file_get_contents($scheme."://".$host.":".$port.$path, false, $ctx);
        $dt = (int)round((microtime(true)-$t0)*1000);
        $code = 0;
        if (isset($http_response_header) && is_array($http_response_header) && isset($http_response_header[0])) {
          if (preg_match('/\s(\d{3})\s/', $http_response_header[0], $m2)) { $code = (int)$m2[1]; }
        }
        $okCode = ($code === $expectCode);
        $okContains = true;
        if ($contains !== '') { $okContains = (is_string($body) && strpos($body, $contains) !== false); }

        $ok = ($okCode && $okContains);
        $name = $label ?: ($spec);
        $details = 'http '.($code ?: '0').' in '.$dt.'ms';
        if ($contains !== '') { $details .= ' contains="'.(strlen($contains)>32?substr($contains,0,29).'...':$contains).'"'; }
        $out[] = ['name'=>$name, 'status'=> $ok?'ok':'fail', 'details'=> $details];
      } else {
        // TCP connect check
        $hp = $spec;
        $host = $hp; $port = null;
        if (strpos($hp, ':') !== false){
          list($host, $port) = explode(':', $hp, 2);
        }
        $host = trim($host); $port = (int)trim((string)$port);
        if ($host === '' || $port <= 0) {
          $out[] = ['name'=> ($label ?: $s), 'status'=>'warn', 'details'=>'invalid target'];
          continue;
        }
        $name = $label ?: ($host.':'.$port);
        $errno=0; $errstr='';
        $ok = false;
        $start = microtime(true);
        $fp = @stream_socket_client('tcp://'.$host.':'.$port, $errno, $errstr, $timeout);
        if ($fp) { $ok = true; @fclose($fp); }
        $dt = (int)round((microtime(true)-$start)*1000);
        $out[] = ['name'=>$name, 'status'=> $ok?'ok':'fail', 'details'=> ($ok?'connected':'error')." in ${dt}ms"];
      }
    }
    return $out;
  }
}


$__raw = file_get_contents('php://input');
$__body = json_decode($__raw ?: '[]', true) ?: [];
$__action = $__body['action'] ?? 'quick';
/*__BODY_PARSED__*/

if (!is_logged_in()) {
  http_response_code(401);
  
/*__HISTORY_WRITE__*/
try {
  $histEnabled = (bool)\App\Config::get('history.enabled', true);
  if ($histEnabled) {
    $histDir = dirname(dashboard_state_path('history/.keep')) . '/history';
@mkdir($histDir, 0775, true);
$month = date('Y-m');
$file = $histDir . '/' . $month . '.jsonl';
    $row = [
      't' => date('c'),
      'action' => $action,
      'score' => $score,
      'items' => $items
    ];
    @file_put_contents($file, json_encode($row).PHP_EOL, FILE_APPEND);
    // best-effort retention pruning (by month directories)
    $retain = (int)\App\Config::get('history.retain_days', 90);
    if ($retain > 0) {
      $old = (new \DateTime())->modify('-'.$retain.' days');
      $keepPrefix = $old->format('Y-m');
      // we won't delete current or last months; just leave pruning simple
    }
  }
} catch (\Throwable $e) {
  // ignore
}


echo json_encode(['ok'=>false, 'error'=>'auth required']);
  exit;
}


/*__RATELIMIT__*/
$rate_limit_ms = (int)\App\Config::get('server_tests.rate_limit_ms', 250);
if ($rate_limit_ms > 0){
  if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
  $now_ms = (int) floor(microtime(true) * 1000);
  $last_ms = isset($_SESSION['server_tests_last_ms']) ? (int)$_SESSION['server_tests_last_ms'] : 0;
  if ($now_ms - $last_ms < $rate_limit_ms){
    http_response_code(429);
    
/*__HISTORY_WRITE__*/
try {
  $histEnabled = (bool)\App\Config::get('history.enabled', true);
  if ($histEnabled) {
    $histDir = dirname(dashboard_state_path('history/.keep')) . '/history';
@mkdir($histDir, 0775, true);
$month = date('Y-m');
$file = $histDir . '/' . $month . '.jsonl';
    $row = [
      't' => date('c'),
      'action' => $action,
      'score' => $score,
      'items' => $items
    ];
    @file_put_contents($file, json_encode($row).PHP_EOL, FILE_APPEND);
    // best-effort retention pruning (by month directories)
    $retain = (int)\App\Config::get('history.retain_days', 90);
    if ($retain > 0) {
      $old = (new \DateTime())->modify('-'.$retain.' days');
      $keepPrefix = $old->format('Y-m');
      // we won't delete current or last months; just leave pruning simple
    }
  }
} catch (\Throwable $e) {
  // ignore
}


echo json_encode(['ok'=>false,'error'=>'rate limit']);
    exit;
  }
  $_SESSION['server_tests_last_ms'] = $now_ms;
}
// audit log
$audit = (bool)\App\Config::get('server_tests.audit_log', true);
if ($audit){
  $state = realpath(__DIR__.'/../state') ?: (__DIR__.'/../state');
  @mkdir($state, 0775, true);
  $ip = $_SERVER['REMOTE_ADDR'] ?? '';
  $user = $_SESSION['user']['name'] ?? ($_SESSION['user']['email'] ?? 'unknown');
  $line = json_encode(['t'=>date('c'),'ip'=>$ip,'user'=>$user,'action'=>$__action]);
  @file_put_contents($state.'/diag_audit.log', $line.PHP_EOL, FILE_APPEND);
}

use App\Config;

// ---------- helpers ----------
function bytes_from_ini($val){
  if ($val === false || $val === null || $val === '') return null;
  $v = trim($val);
  $last = strtolower(substr($v, -1));
  $num = (int)$v;
  switch ($last) {
    case 'g': return $num * 1024 * 1024 * 1024;
    case 'm': return $num * 1024 * 1024;
    case 'k': return $num * 1024;
    default: return (int)$v;
  }
}

function overall_score($items){
  if (!is_array($items) || !count($items)) return 0;
  $sum = 0; $n=0;
  foreach ($items as $it){
    $n++;
    $st = $it['status'] ?? 'warn';
    if ($st === 'ok')   $sum += 1.0;
    elseif ($st === 'warn') $sum += 0.5;
    else $sum += 0.0;
  }
  return $n ? $sum / $n : 0;
}

// ---------- new checks ----------
function check_tls_cert($host){
  $host = preg_replace('/:\\d+$/','',(string)$host);
  if (!$host) return ['name'=>'TLS cert','status'=>'warn','details'=>'no host'];
  $ctx = stream_context_create(['ssl'=>['capture_peer_cert'=>true,'verify_peer'=>false,'verify_peer_name'=>false]]);
  $errno=0;$errstr='';
  $fp = @stream_socket_client('ssl://'.$host.':443', $errno, $errstr, 3, STREAM_CLIENT_CONNECT, $ctx);
  if (!$fp) return ['name'=>'TLS cert','status'=>'warn','details'=>'unreachable'];
  $params = stream_context_get_params($fp);
  @fclose($fp);
  if (empty($params['options']['ssl']['peer_certificate'])) return ['name'=>'TLS cert','status'=>'warn','details'=>'no cert'];
  $cert = $params['options']['ssl']['peer_certificate'];
  $info = openssl_x509_parse($cert);
  $to = $info['validTo_time_t'] ?? null;
  if (!$to) return ['name'=>'TLS cert','status'=>'warn','details'=>'invalid cert'];
  $days = floor(($to - time()) / 86400);

  $warnDays = (int)\App\Config::get('server_tests.tls_warn_days', 21);
  $failDays = (int)\App\Config::get('server_tests.tls_fail_days', 7);
  $st = ($days >= $warnDays) ? 'ok' : (($days >= $failDays) ? 'warn' : 'fail');

  return ['name'=>'TLS cert expiry', 'status'=>$st, 'details'=> $days.' days'];
}

function check_inodes_for($path){
  $path = $path ?: '/';
  $out = @shell_exec("df -i -P ".escapeshellarg($path)." 2>/dev/null");
  if (!$out) return ['name'=>'Inodes free','status'=>'warn','details'=>'unavailable'];
  $lines = preg_split('/\r?\n/', trim($out));
  if (count($lines) < 2) return ['name'=>'Inodes free','status'=>'warn','details'=>'unparsed'];
  $row = preg_split('/\s+/', trim($lines[1]));
  // Filesystem Inodes IUsed IFree IUse% Mounted
  $inodes = intval($row[1] ?? 0);
  $ifree  = intval($row[3] ?? 0);
  $iusep  = trim((string)($row[4] ?? '0%'));
  $free_pct = $inodes>0 ? ($ifree/$inodes) : 0;

  $okPct = (int)\App\Config::get('server_tests.inode_ok_free_pct', 15);
  $warnPct = (int)\App\Config::get('server_tests.inode_warn_free_pct', 7);
  $pct = $free_pct*100.0;
  $st = ($pct >= $okPct) ? 'ok' : (($pct >= $warnPct) ? 'warn' : 'fail');

  return ['name'=>'Inodes free','status'=>$st,'details'=> $ifree.' / '.$inodes.' ('.$iusep.' used)'];
}

function check_security_updates(){
  $osrel = @file_get_contents('/etc/os-release') ?: '';
  $count = null; $details = '';
  if (preg_match('/(debian|ubuntu)/i', $osrel)) {
    $out = @shell_exec('LC_ALL=C apt-get -s upgrade 2>/dev/null');
    if ($out !== null) {
      $cnt = preg_match_all('/^Inst\s+.*security/mi', (string)$out, $m);
      $count = $cnt;
      $details = $cnt.' security updates';
    }
  } elseif (preg_match('/(rhel|centos|fedora|rocky|almalinux)/i', $osrel)) {
    $out = @shell_exec('LANG=C dnf updateinfo list security -q 2>/dev/null');
    if ($out) {
      $lines = array_filter(preg_split('/\r?\n/', trim($out)), function($s){
        return $s!=='' && stripos($s,'Last metadata')===false;
      });
      $count = max(0, count($lines));
      $details = $count.' security updates';
    }
  }
  if ($count === null) return ['name'=>'Security updates','status'=>'warn','details'=>'unknown'];
  $warn = (int)\App\Config::get('server_tests.updates_warn_count', 1);
  $fail = (int)\App\Config::get('server_tests.updates_fail_count', 10);
  $st = ($count <= 0) ? 'ok' : (($count < $fail && $count >= $warn) ? 'warn' : ($count >= $fail ? 'fail' : 'ok'));
  return ['name'=>'Security updates','status'=>$st,'details'=> $details];
}

// --- security extras (log-based / project-limited, no exec) ---
function se_fail2ban_status(){
  $path = '/var/log/fail2ban.log';
  if (@is_file($path)){
    $mt = @filemtime($path);
    $age = $mt ? (time()-$mt) : null;
    $fresh = ($age !== null && $age < 7*86400);
    return ['name'=>'fail2ban', 'status'=> $fresh?'ok':'warn', 'details'=> $fresh ? 'log recent' : 'log stale'];
  }
  return ['name'=>'fail2ban', 'status'=>'unknown', 'details'=>'no log'];
}
function se_firewall_present(){
// OS-agnostic firewall presence/logging check without panel-specific assumptions.

  // 0) Legacy/file-based logs (fast path)
  $candidates = ['/var/log/ufw.log','/var/log/iptables.log','/var/log/nftables.log','/var/log/firewalld'];
  foreach ($candidates as $p){
    if (@is_file($p) || @is_dir($p)){
      $mt = @filemtime($p);
      $fresh = $mt ? (time()-$mt) < 7*86400 : false;
      return ['name'=>'firewall status', 'status'=> $fresh?'ok':'warn', 'details'=> basename($p).($fresh?' recent':' present')];
    }
  }

  // Helper: does a command exist?
  $cmd_exists = function($cmd){
    $out = @shell_exec("command -v $cmd 2>/dev/null");
    return is_string($out) && strlen(trim($out))>0;
  };

  // 1) nftables: consider rules present if ruleset lists tables/chains/hooks;
  //    treat permission errors as presence (running without privileges)
  if ($cmd_exists('nft')) {
    $out = @shell_exec('nft list ruleset 2>&1');
    if (is_string($out)) {
      if (preg_match('/\\b(table|chain|hook)\\b/i', $out)) {
        return ['name'=>'firewall status', 'status'=>'ok', 'details'=>'nftables rules present'];
      }
      if (preg_match('/permission denied|operation not permitted|not authorized/i', $out)) {
        return ['name'=>'firewall status', 'status'=>'ok', 'details'=>'nftables present (insufficient privileges)'];
      }
    }
  }

  // 2) iptables (legacy or nft shim): rules/chains imply active policy;
  //    permission errors imply presence
  if ($cmd_exists('iptables')) {
    $s = @shell_exec('iptables -S 2>&1');
    if (is_string($s) && preg_match('/^\\-A\\s+/m', $s)) {
      return ['name'=>'firewall status', 'status'=>'ok', 'details'=>'iptables rules present'];
    }
    $l = @shell_exec('iptables -L -n 2>&1');
    if (is_string($l) && preg_match('/^Chain\\s+\\w+\\s+\\(policy/i', $l)) {
      return ['name'=>'firewall status', 'status'=>'ok', 'details'=>'iptables chains present'];
    }
    if (is_string($s.$l) && preg_match('/permission denied|operation not permitted|not authorized/i', $s.$l)) {
      return ['name'=>'firewall status', 'status'=>'ok', 'details'=>'iptables present (insufficient privileges)'];
    }
  }

  // 3) ufw/firewalld services active => OK
  $ufw = @trim(@shell_exec('systemctl is-active ufw 2>/dev/null'));
  $fwd = @trim(@shell_exec('systemctl is-active firewalld 2>/dev/null'));
  if ($ufw==='active' || $fwd==='active') {
    return ['name'=>'firewall status', 'status'=>'ok', 'details'=> ($ufw==='active'?'ufw':'firewalld').' active'];
  }

  // 4) Config present on disk (may be inactive at runtime) => WARN (not fail)
  if (@is_file('/etc/nftables.conf') || @is_file('/etc/iptables/rules.v4') || @is_file('/etc/iptables/rules.v6')) {
    return ['name'=>'firewall status', 'status'=>'warn', 'details'=>'config present (runtime unknown)'];
  }

  // 5) No evidence available without root/extra tools => UNKNOWN (don’t fail)
  return ['name'=>'firewall status', 'status'=>'unknown', 'details'=>'no standard firewall tools/logs detected'];
}




function se_ssh_rootlogin(){
  $cfg = '/etc/ssh/sshd_config';
  $txt = @file_get_contents($cfg);
  if ($txt === false) return ['name'=>'ssh root login', 'status'=>'unknown', 'details'=>'/etc not readable'];
  $m = null;
  if (preg_match('/^\s*PermitRootLogin\s+(\S+)/mi', $txt, $m)){
    $v = strtolower($m[1]);
    if ($v === 'no' || $v === 'prohibit-password') return ['name'=>'ssh root login', 'status'=>'ok', 'details'=>$v];
    return ['name'=>'ssh root login', 'status'=>'warn', 'details'=>$v];
  }
  return ['name'=>'ssh root login', 'status'=>'unknown', 'details'=>'no directive'];
}
function se_world_writable(){
  $found = [];
  $limit = 200;
  $roots = [ dirname(__DIR__), sys_get_temp_dir() ];
  foreach ($roots as $root){
    if (!@is_dir($root)) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    $cnt = 0;
    foreach ($it as $p){
      if ($cnt >= $limit) break;
      /** @var SplFileInfo $p */
      if ($p->isDir()){
        $perm = @fileperms($p->getPathname());
        if ($perm !== false && ($perm & 0o002)){ // world-writable
          $found[] = $p->getPathname();
          $cnt++;
        }
      }
    }
  }
  if (!$found) return ['name'=>'world-writable dirs', 'status'=>'ok', 'details'=>'none under project/tmp'];
  return ['name'=>'world-writable dirs', 'status'=>'warn', 'details'=> (count($found)).' hit(s), e.g. '. implode(', ', array_slice($found,0,3))];
}
function se_ntp_sync(){
// Multi-strategy detection that doesn't rely on specific log files.
  // 1) systemd-timesyncd: timedatectl says synchronized
  $td = @trim(@shell_exec('timedatectl show -p NTPSynchronized --value 2>/dev/null'));
  if ($td === 'yes') return ['name'=>'NTP sync', 'status'=>'ok', 'details'=>'timedatectl: synchronized'];
  $tds = @shell_exec('timedatectl status 2>/dev/null');
  if ($tds && preg_match('/System clock synchronized:\s*yes/i', $tds)) {
    return ['name'=>'NTP sync', 'status'=>'ok', 'details'=>'timedatectl: synchronized'];
  }

  // 2) ntpd: selected peer in ntpq output (line with leading '*')
  $ntpq = @shell_exec('ntpq -p 2>/dev/null');
  if ($ntpq && preg_match('/^[\s\*]?\*|^\s*\*/m', $ntpq)) {
    return ['name'=>'NTP sync', 'status'=>'ok', 'details'=>'ntpd: selected peer present'];
  }

  // 3) chrony: tracking shows sane status and not unsynchronized
  $chrony = @shell_exec('chronyc tracking 2>/dev/null');
  if ($chrony && preg_match('/Stratum|Reference ID/i', $chrony) && !preg_match('/unsynchron|not\s+synchron/i', $chrony)) {
    return ['name'=>'NTP sync', 'status'=>'ok', 'details'=>'chrony: tracking OK'];
  }

  // 4) Fallback: heuristic from recent logs for timesyncd/chronyd
  $paths = ['/var/log/syslog','/var/log/messages','/var/log/system.log'];
  foreach ($paths as $p){
    if (!@is_file($p)) continue;
    $sz = @filesize($p); if ($sz===false) continue;
    $fh = @fopen($p, 'rb'); if(!$fh) continue;
    if ($sz > 65536) @fseek($fh, $sz-65536);
    $buf = @stream_get_contents($fh); @fclose($fh);
    if ($buf && preg_match('/(timesyncd|chronyd).*synchron/i', $buf)){
      return ['name'=>'NTP sync', 'status'=>'ok', 'details'=>'recent sync messages'];
    }
  }

  return ['name'=>'NTP sync', 'status'=>'warn', 'details'=>'no evidence found (check service status)'];
}



// ---------- scans ----------
function scan_quick(){
  $res = [];

  // PHP version
  $ver = PHP_VERSION;
  $ok_php = version_compare($ver, '8.0.0', '>=');
  $res[] = ['name'=>'PHP Version', 'status'=> $ok_php?'ok':'warn', 'details'=>$ver];

  // memory_limit
  $mem = ini_get('memory_limit');
  $memB = bytes_from_ini($mem);
  $ok_mem = ($memB===-1) ? true : ($memB >= 128*1024*1024);
  $res[] = ['name'=>'memory_limit', 'status'=> $ok_mem?'ok':'warn', 'details'=>$mem];

  // max_execution_time
  $met = (int)ini_get('max_execution_time');
  $ok_met = ($met === 0 || $met >= 30);
  $res[] = ['name'=>'max_execution_time', 'status'=> $ok_met?'ok':'warn', 'details'=> (string)$met . 's'];

  // OPcache
  $opc_enabled = (bool)ini_get('opcache.enable');
  $res[] = ['name'=>'OPcache', 'status'=> $opc_enabled?'ok':'warn', 'details'=> $opc_enabled?'enabled':'disabled'];

  // HTTPS
  $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? null) == 443);
  $res[] = ['name'=>'HTTPS', 'status'=> $https?'ok':'warn', 'details'=> $https?'on':'off'];

  // TLS cert expiry (if host known)
  $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
  if (!empty($host)) { $res[] = check_tls_cert($host); }

  // Disk free on project root
  $root = dirname(__DIR__);
  $df = @disk_free_space($root);
  $dt = @disk_total_space($root);
  if ($df !== false && $dt) {
    $pct_free = $dt > 0 ? ($df / $dt) : 0;
    $res[] = ['name'=>'Disk Free', 'status'=> ($pct_free >= 0.10)?'ok':(($pct_free >= 0.05)?'warn':'fail'), 'details'=>sprintf('%.1f%% free', $pct_free*100)];
  } else {
    $res[] = ['name'=>'Disk Free', 'status'=>'warn', 'details'=>'unknown'];
  }

  // Security updates
  $res[] = check_security_updates();

  // Load average (if available)
  $load = function_exists('sys_getloadavg') ? @sys_getloadavg() : null;
  if (is_array($load)) {
    $one = (float)$load[0];
    $res[] = ['name'=>'Load Avg (1m)', 'status'=> ($one < 4.0)?'ok':(($one < 8.0)?'warn':'fail'), 'details'=> (string)$one ];
  }

  
  $res = apply_threshold_overrides($res);
  return $res;
}


function scan_security(){
  $res = [];

  $display_errors = (bool)ini_get('display_errors');
  $res[] = ['name'=>'display_errors', 'status'=> $display_errors?'warn':'ok', 'details'=> $display_errors?'on':'off'];

  $expose_php = (bool)ini_get('expose_php');
  $res[] = ['name'=>'expose_php', 'status'=> $expose_php?'warn':'ok', 'details'=> $expose_php?'on':'off'];

  $sess_http_only = (bool)ini_get('session.cookie_httponly');
  $res[] = ['name'=>'session.cookie_httponly', 'status'=> $sess_http_only?'ok':'warn', 'details'=> $sess_http_only?'on':'off'];

  $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? null) == 443);
  $sess_secure = (bool)ini_get('session.cookie_secure');
  $res[] = ['name'=>'session.cookie_secure', 'status'=> ($https ? ($sess_secure?'ok':'warn') : 'ok'), 'details'=> $sess_secure?'on':'off'];

  $disable_functions = trim((string)ini_get('disable_functions'));
  $res[] = ['name'=>'disable_functions', 'status'=> $disable_functions ? 'ok':'warn', 'details'=> $disable_functions ?: 'none'];

  $open_basedir = trim((string)ini_get('open_basedir'));
  $res[] = ['name'=>'open_basedir', 'status'=> $open_basedir ? 'ok':'warn', 'details'=> $open_basedir ?: 'none'];

  // Security extras
  $res[] = se_ntp_sync();
  $res[] = se_fail2ban_status();
  $res[] = se_firewall_present();
  $res[] = se_world_writable();
  // TLS + security updates
  $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
  if (!empty($host)) { $res[] = check_tls_cert($host); }
  $res[] = check_security_updates();

  return $res;
}

function scan_filesystem(){
  $res = [];

  $state = realpath(__DIR__ . '/../state');
  $state_w = $state ? is_writable($state) : false;
  $res[] = ['name'=>'state directory', 'status'=> $state_w?'ok':'warn', 'details'=> $state ?: '(missing)'];

  $tmp = sys_get_temp_dir();
  $tmp_w = $tmp ? is_writable($tmp) : false;
  $res[] = ['name'=>'temp directory', 'status'=> $tmp_w?'ok':'fail', 'details'=> $tmp ?: '(unknown)'];

  // Inodes on project root
  $res[] = check_inodes_for(dirname(__DIR__));

  $root = dirname(__DIR__);
  $res[] = ['name'=>'project root readable', 'status'=> is_readable($root)?'ok':'fail', 'details'=> $root];

  return $res;
}

function scan_performance(){
  $res = [];
  $iters = 250; // gentle
  $t0 = microtime(true);
  for ($i=0; $i<$iters; $i++){
    $x = hash('sha256', random_bytes(16));
  }
  $t1 = microtime(true);
  $dt = $t1 - $t0;
  $res[] = ['name'=>'CPU micro-bench (hash loop)', 'status'=> ($dt < 0.15)?'ok':(($dt < 0.50)?'warn':'fail'), 'details'=> sprintf('%.3f s / %d iters', $dt, $iters)];

  $buf = array_fill(0, 200, ['a'=>str_repeat('x',64), 'b'=>mt_rand()]);
  $t0 = microtime(true);
  $enc = json_encode($buf);
  $dec = json_decode($enc, true);
  $t1 = microtime(true);
  $res[] = ['name'=>'JSON roundtrip', 'status'=> (($t1-$t0) < 0.10)?'ok':'warn', 'details'=> sprintf('%.3f s', $t1-$t0)];

  return $res;
}

// ---------- dispatch ----------
$body = $__body; $action = $__action;

switch ($action) {
  case 'security':   $items = scan_security();   break;
  case 'filesystem': $items = scan_filesystem(); break;
  case 'services':
    $override = [];
    if (isset($req['targets']) && is_array($req['targets'])) $override = $req['targets'];
    $items = scan_services($override);
    $score = score($items);
    break;
  case 'performance':$items = scan_performance();break;
  case 'quick':
  default:           $items = scan_quick();      break;
}

$score = overall_score($items);


/*__HISTORY_WRITE__*/
try {
  $histEnabled = (bool)\App\Config::get('history.enabled', true);
  if ($histEnabled) {
    $histDir = dirname(dashboard_state_path('history/.keep')) . '/history';
@mkdir($histDir, 0775, true);
$month = date('Y-m');
$file = $histDir . '/' . $month . '.jsonl';
    $row = [
      't' => date('c'),
      'action' => $action,
      'score' => $score,
      'items' => $items
    ];
    @file_put_contents($file, json_encode($row).PHP_EOL, FILE_APPEND);
    // best-effort retention pruning (by month directories)
    $retain = (int)\App\Config::get('history.retain_days', 90);
    if ($retain > 0) {
      $old = (new \DateTime())->modify('-'.$retain.' days');
      $keepPrefix = $old->format('Y-m');
      // we won't delete current or last months; just leave pruning simple
    }
  }
} catch (\Throwable $e) {
  // ignore
}


echo json_encode([
  'ok' => true,
  'time' => date('c'),
  'score' => $score,
  'results' => $items
]);
