<?php
/* ==== Portable helpers (declared early to avoid order issues) ==== */
if (!function_exists('__fn_ok')) {
  function __fn_ok($name){
    static $disabled = null;
    if ($disabled === null) {
      $val = @ini_get('disable_functions');
      $disabled = array_filter(array_map('trim', explode(',', (string)$val)));
      if (!is_array($disabled)) { $disabled = []; }
    }
    return function_exists($name) && !in_array($name, $disabled, true);
  }
}

if (!function_exists('__find_bin')) {
  function __find_bin($name){
    static $dirs = ['/usr/bin','/bin','/usr/local/bin','/sbin','/usr/sbin','/busybox'];
    foreach ($dirs as $d){ $p = $d . '/' . $name; if (@is_executable($p)) return $p; }
    return null;
  }
}

if (!function_exists('__run_cmd')) {
  function __run_cmd($cmd){
    if (__fn_ok('popen')){
      $h = @popen($cmd . ' 2>/dev/null', 'r');
      if ($h){ $out = stream_get_contents($h); @pclose($h); if (is_string($out) && $out !== '') return $out; }
    }
    if (__fn_ok('shell_exec')){
      $out = @shell_exec($cmd . ' 2>/dev/null'); if (is_string($out) && $out !== '') return $out;
    }
    if (__fn_ok('proc_open')){
      $desc = [1=>['pipe','w'], 2=>['pipe','w']];
      $p = @proc_open($cmd, $desc, $pipes);
      if (is_resource($p)){ $out = stream_get_contents($pipes[1] ?? null); foreach ($pipes as $pp){ if (is_resource($pp)) @fclose($pp); } @proc_close($p); if ($out) return $out; }
    }
    return null;
  }
}
/* ==== End helpers ==== */

// metrics_summary.php — returns JSON summary of uptime, load, memory, disk, processes
require_once __DIR__ . '/../includes/init.php';

header('Content-Type: application/json');

$__t0 = microtime(true);

// ------------------------------------------------------------
// Helpers
// ------------------------------------------------------------
if (!function_exists('read_first_line')) {
  function read_first_line($path){
    if (!@is_readable($path)) return null;
    $h = @fopen($path, 'r');
    if (!$h) return null;
    $line = fgets($h, 4096);
    fclose($h);
    return $line !== false ? trim($line) : null;
  }
}
if (!function_exists('bytes_h')) {
  function bytes_h($b){
    $b = (float)$b;
    $u = ['B','KB','MB','GB','TB'];
    $i = 0;
    while ($b >= 1024 && $i < count($u)-1){ $b/=1024; $i++; }
    return sprintf(($i>=2?'%.1f %s':'%.0f %s'), $b, $u[$i]);
  }
}
function pick_disk_path(){
  $tried = [];
  $chosen = null;
  // override by query
  if (!empty($_GET['disk'])) {
    $d = (string)$_GET['disk'];
    if (strpos($d, '..') === false && $d !== '' && @is_dir($d)) { $chosen = $d; $tried[] = $d; }
  }
  // override by constant
  if (!$chosen && defined('DISK_METRICS_PATH') && DISK_METRICS_PATH) {
    $tried[] = DISK_METRICS_PATH;
    if (@is_dir(DISK_METRICS_PATH)) $chosen = DISK_METRICS_PATH;
  }
  // fallbacks
  $candidates = ['/'];
  if (!empty($_SERVER['DOCUMENT_ROOT'])) $candidates[] = $_SERVER['DOCUMENT_ROOT'];
  $pr = @realpath(__DIR__ . '/..'); if ($pr) $candidates[] = $pr;
  $cwd = @getcwd(); if ($cwd) $candidates[] = $cwd;

  foreach ($candidates as $p){
    $tried[] = $p;
    if ($chosen) break;
    if (@is_dir($p)) $chosen = $p;
  }
  return [$chosen, $tried];
}

// ------------------------------------------------------------
// Uptime
// ------------------------------------------------------------
$uptime_seconds = null; $uptime_human = null;
if (($line = read_first_line('/proc/uptime'))){
  $parts = explode(' ', $line);
  $uptime_seconds = (int)floor((float)$parts[0]);
}
if ($uptime_seconds !== null){
  $d = intdiv($uptime_seconds, 86400);
  $h = intdiv($uptime_seconds % 86400, 3600);
  $m = intdiv($uptime_seconds % 3600, 60);
  $uptime_human = ($d>0? "{$d}d ":"") . "{$h}h {$m}m";
// Fallback: if /proc/uptime is unavailable, derive from `uptime -s` (boot time)
if ($uptime_seconds === null) {
  if (function_exists('strtotime')){
    $uptime_s = __run_cmd((__find_bin('uptime') ?: 'uptime') . ' -s');
    if (is_string($uptime_s) && trim($uptime_s) !== ''){
      $boot_ts = @strtotime(trim($uptime_s));
      if ($boot_ts) {
        $uptime_seconds = time() - $boot_ts;
        $d = intdiv($uptime_seconds, 86400);
        $h = intdiv($uptime_seconds % 86400, 3600);
        $m = intdiv($uptime_seconds % 3600, 60);
        $uptime_human = ($d>0? "{$d}d ":"") . "{$h}h {$m}m";
      }
    }
  }
}

}

// ------------------------------------------------------------
// Loadavg / CPU cores
// ------------------------------------------------------------
$loadavg = null;
if (($line = read_first_line('/proc/loadavg'))){

  $parts = preg_split('/\s+/', trim($line));
  if (count($parts) >= 3){
    $loadavg = [ (float)$parts[0], (float)$parts[1], (float)$parts[2] ];
  }
} elseif (function_exists('sys_getloadavg')) {
  $la = @sys_getloadavg();
  if (is_array($la) && count($la) >= 3) $loadavg = [ (float)$la[0], (float)$la[1], (float)$la[2] ];
}

$cpu_cores = null;
if (@is_readable('/proc/cpuinfo')){
  $ci = @file('/proc/cpuinfo', FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) ?: [];
  $cpu_cores = 0;
  foreach ($ci as $ln){ if (preg_match('/^processor\s*:\s*\d+$/', trim($ln))) $cpu_cores++; }
  if ($cpu_cores <= 0) $cpu_cores = null;
}

// ------------------------------------------------------------
// Memory
// ------------------------------------------------------------
$memInfo = [];
if (@is_readable('/proc/meminfo')){
  foreach (@file('/proc/meminfo') as $ln){
    if (preg_match('/^([A-Za-z_]+):\s*(\d+)\s*kB/', $ln, $m)){
      $memInfo[$m[1]] = (int)$m[2] * 1024; // bytes
    }
  }
}
$total = $memInfo['MemTotal'] ?? null;
$free  = null;
$used  = null;
$buffers = $memInfo['Buffers'] ?? 0;
$cached  = $memInfo['Cached'] ?? 0;
if ($total !== null){
  if (isset($memInfo['MemAvailable'])){
    $free = (int)$memInfo['MemAvailable'];
    $used = $total - $free;
  } else {
    $free = ($memInfo['MemFree'] ?? 0) + ($buffers ?? 0) + ($cached ?? 0);
    $used = $total - $free;
  }
}


// Fallback memory via `free -b` if /proc/meminfo is restricted
if ($total === null) {
  $free_bin = __find_bin('free');
  if ($free_bin) {
    $raw = __run_cmd($free_bin . ' -b');
    if (is_string($raw) && trim($raw) !== '') {
      foreach (preg_split('/\r?\n/', trim($raw)) as $ln) {
        // Example: "Mem:  16317452288  9876543210  123456789  0  4321000000  5678000000"
        if (preg_match('/^\s*Mem:\s+(\d+)\s+(\d+)\s+(\d+)/', $ln, $m)) {
          $total = (int)$m[1];
          $used  = (int)$m[2];
          $free  = (int)$m[3];
          // Buffers/cached unknown from this line; leave zeros
          break;
        }
      }
    }
  }
}

// ------------------------------------------------------------
// Disk
// ------------------------------------------------------------
list($diskPath, $diskTried) = pick_disk_path();
$disk_total = $diskPath ? @disk_total_space($diskPath) : null;
$disk_free  = $diskPath ? @disk_free_space($diskPath)  : null;
$disk_used  = ($disk_total && $disk_free) ? ($disk_total - $disk_free) : null;
$disk_pct   = ($disk_total && $disk_used!==null) ? round(100 * $disk_used / $disk_total, 1) : null;


// Disk fallback: if no path or disk_* failed, try `df -kP /`
if ((!$diskPath || $disk_total === null || $disk_free === null) && __find_bin('df')) {
  $raw = __run_cmd(__find_bin('df') . ' -kP /');
  if (is_string($raw) && trim($raw) !== '') {
    $lines = preg_split('/\r?\n/', trim($raw));
    if (count($lines) >= 2) {
      // header then data line: Filesystem 1024-blocks Used Available Use% Mounted on
      $parts = preg_split('/\s+/', trim($lines[1]));
      if (count($parts) >= 6) {
        $disk_total = (int)$parts[1] * 1024;
        $disk_used  = (int)$parts[2] * 1024;
        $disk_free  = (int)$parts[3] * 1024;
        $disk_pct   = ($disk_total ? round(100 * $disk_used / $disk_total, 1) : null);
        $diskPath   = $parts[5];
      }
    }
  }
}

// ------------------------------------------------------------
// Processes (portable, layered fallbacks)
// ------------------------------------------------------------
$processes = [];

// try a few ps variants
$ps_bin   = __find_bin('ps');
$head_bin = __find_bin('head');
if ($ps_bin && $head_bin){
  $tries = [
    $ps_bin . ' -eo pid,comm,pcpu,pmem --sort=-pcpu | ' . $head_bin . ' -n 6',
    $ps_bin . ' aux --sort=-%cpu | ' . $head_bin . ' -n 6',
  ];
  foreach ($tries as $cmd){
    $raw = __run_cmd($cmd);
    if ($raw){
      $lines = preg_split('/\r?\n/', trim($raw));
      foreach ($lines as $ln){
        // match either "PID COMM %CPU %MEM" or "USER PID %CPU %MEM … COMMAND"
        if (preg_match('/^\s*(\d+)\s+(\S+)\s+([\d\.]+)\s+([\d\.]+)/', $ln, $m)){
          $processes[] = ['pid'=>(int)$m[1], 'name'=>$m[2], 'cpu'=>(float)$m[3], 'mem'=>(float)$m[4]];
        } elseif (preg_match('/^\S+\s+(\d+)\s+([\d\.]+)\s+([\d\.]+)\s+.*\s+(\S+)\s*$/', $ln, $m)){
          $processes[] = ['pid'=>(int)$m[1], 'name'=>$m[4], 'cpu'=>(float)$m[2], 'mem'=>(float)$m[3]];
        }
      }
      if (!empty($processes)) { /* got data */ break; }
    }
  }
}

// BusyBox minimal ps (no head/awk assumptions)
if ($ps_bin && empty($processes)){
  $raw = __run_cmd($ps_bin . ' w');
  if ($raw){
    $lines = preg_split('/\r?\n/', trim($raw));
    // skip header; take first 5 real lines, synthesize 0.0 cpu/mem
    $cnt = 0;
    foreach ($lines as $i=>$ln){
      if ($i === 0) continue;
      if (preg_match('/\b(\d+)\b.*?\s([^\s]+)$/', $ln, $m)){
        $processes[] = ['pid'=>(int)$m[1], 'name'=>$m[2], 'cpu'=>0.0, 'mem'=>0.0];
        if (++$cnt >= 5) break;
      }
    }
  }
}

// No-exec fallback: top by memory via /proc
if (empty($processes) && @is_readable('/proc')){
  $meminfo = @file('/proc/meminfo', FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) ?: [];
  $memTotalKB = 0;
  foreach ($meminfo as $ln){ if (preg_match('/^MemTotal:\s+(\d+)\s+kB$/', $ln, $m)) { $memTotalKB = (int)$m[1]; break; } }
  $candidates = [];
  foreach (@glob('/proc/[0-9]*/status') as $status){
    $pid = (int)basename(dirname($status));
    $name = @trim(@file_get_contents(dirname($status).'/comm')) ?: null;
    $vmrss = 0;
    foreach (@file($status, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) ?: [] as $ln){
      if (preg_match('/^VmRSS:\s+(\d+)\s+kB$/', $ln, $m)){ $vmrss = (int)$m[1]; break; }
    }
    if ($name !== null){
      $memPct = ($memTotalKB > 0) ? ($vmrss / $memTotalKB * 100.0) : 0.0;
      $candidates[] = ['pid'=>$pid, 'name'=>$name, 'cpu'=>0.0, 'mem'=>$memPct];
    }
  }
  usort($candidates, function($a,$b){ return $b['mem'] <=> $a['mem']; });
  $processes = array_slice($candidates, 0, 5);
}

// Fallbacks before response assembly
if ($loadavg === null && function_exists('sys_getloadavg')) {
  $tmp = @sys_getloadavg();
  if (is_array($tmp) && count($tmp) >= 3) {
    $loadavg = [ (float)$tmp[0], (float)$tmp[1], (float)$tmp[2] ];
  }
}
// Response
// ------------------------------------------------------------

// ---------------------- Network helpers ----------------------
if (!function_exists('__read_net_dev')) {
  function __read_net_dev(){
    $p = '/proc/net/dev';
    if (@is_readable($p)){
      $lines = @file($p, FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
      $out = [];
      if (is_array($lines)){
        foreach ($lines as $ln){
          if (strpos($ln, ':') === false) continue;
          list($iface, $rest) = array_map('trim', explode(':', $ln, 2));
          $cols = preg_split('/\s+/', trim($rest));
          if (count($cols) >= 16){
            $rx = (int)$cols[0];
            $tx = (int)$cols[8];
            $out[$iface] = ['rx_bytes'=>$rx, 'tx_bytes'=>$tx];
          }
        }
      }
      return $out;
    }
    return null;
  }
}
if (!function_exists('__net_counters')) {
  function __net_counters(){
    $dev = __read_net_dev();
    if (is_array($dev)){
      $rx = 0; $tx = 0;
      foreach ($dev as $iface=>$v){
        $name = strtolower($iface);
        if ($name === 'lo') continue;
        if (strpos($name,'docker')===0 || strpos($name,'veth')===0 || strpos($name,'br-')===0) continue;
        $rx += (int)($v['rx_bytes'] ?? 0);
        $tx += (int)($v['tx_bytes'] ?? 0);
      }
      return ['rx_bytes'=>$rx, 'tx_bytes'=>$tx];
    }
    return ['rx_bytes'=>null, 'tx_bytes'=>null];
  }
}
$resp = [
  'uptime_seconds' => $uptime_seconds,
  'uptime_human'   => $uptime_human,
  'loadavg'        => $loadavg,
  'cpu'            => [ 'cores'=>$cpu_cores, 'load1'=>$loadavg ? $loadavg[0] : null, 'load5'=>$loadavg ? $loadavg[1] : null, 'load15'=>$loadavg ? $loadavg[2] : null ],
  'memory' => [
    'total_bytes' => $total,
    'used_bytes'  => $used,
    'free_bytes'  => $free,
    'used_percent'=> ($total && $used!==null) ? round(100 * $used / $total) : null,
    'total_human' => $total ? bytes_h($total) : null,
    'used_human'  => $used  ? bytes_h($used)  : null,
    'free_human'  => $free  ? bytes_h($free)  : null,
    'buffers_bytes'=> $buffers,
    'cached_bytes' => $cached,
    'buffers_human' => bytes_h($buffers),
    'cached_human' => bytes_h($cached),
  ],
  'disk' => [
    'mount'        => $diskPath,
    'total_bytes'  => $disk_total,
    'used_bytes'   => $disk_used,
    'free_bytes'   => $disk_free,
    'used_percent' => $disk_pct,
    'total_human'  => $disk_total ? bytes_h($disk_total) : null,
    'used_human'   => $disk_used  ? bytes_h($disk_used)  : null,
    'free_human'   => $disk_free  ? bytes_h($disk_free)  : null,
    'temp_c'       => null,
  ],
  'processes' => $processes,
];
// Compatibility aliases and additions
$resp['mem'] = [
  'total_bytes' => $resp['memory']['total_bytes'] ?? null,
  'used_bytes'  => $resp['memory']['used_bytes']  ?? null,
  'free_bytes'  => $resp['memory']['free_bytes']  ?? null,
  'percent'     => $resp['memory']['used_percent'] ?? null
];
$resp['net'] = __net_counters();


// trace always included (helps field debugging)
$resp['trace'] = ['elapsed_ms'=> round((microtime(true)-$__t0)*1000,1)];

// debug payload on request
if (isset($_GET['debug'])){
  $resp['debug'] = [
    'disk' => ['chosen'=>$diskPath, 'tried'=>$diskTried, 'open_basedir'=>ini_get('open_basedir')],
    'mem'  => ['source'=> @is_readable('/proc/meminfo') ? '/proc/meminfo' : ( __find_bin('free') ? 'free -b' : 'n/a')],
    'uptime'=> ['source'=> @is_readable('/proc/uptime') ? '/proc/uptime' : ( __find_bin('uptime') ? 'uptime -s' : 'n/a')],
    'disk'  => ['fallback_df'=> __find_bin('df') ? 'df -kP /' : 'n/a']
  ];
}

echo json_encode($resp);