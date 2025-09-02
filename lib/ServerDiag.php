<?php
namespace App;

require_once __DIR__ . '/../includes/init.php';
require_once __DIR__ . '/Config.php';

class ServerDiag {
  public static function bytes_from_ini($val){
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

  public static function check_tls_cert($host){
    $host = preg_replace('/:\\d+$/','',(string)$host);
    if (!$host) return ['name'=>'TLS cert expiry','status'=>'warn','details'=>'no host'];
    $ctx = stream_context_create(['ssl'=>['capture_peer_cert'=>true,'verify_peer'=>false,'verify_peer_name'=>false]]);
    $errno=0;$errstr='';
    $fp = @stream_socket_client('ssl://'.$host.':443', $errno, $errstr, 3, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) return ['name'=>'TLS cert expiry','status'=>'warn','details'=>'unreachable'];
    $params = stream_context_get_params($fp);
    @fclose($fp);
    if (empty($params['options']['ssl']['peer_certificate'])) return ['name'=>'TLS cert expiry','status'=>'warn','details'=>'no cert'];
    $cert = $params['options']['ssl']['peer_certificate'];
    $info = openssl_x509_parse($cert);
    $to = $info['validTo_time_t'] ?? null;
    if (!$to) return ['name'=>'TLS cert expiry','status'=>'warn','details'=>'invalid cert'];
    $days = floor(($to - time()) / 86400);

    $warnDays = (int)\App\Config::get('server_tests.tls_warn_days', 21);
    $failDays = (int)\App\Config::get('server_tests.tls_fail_days', 7);
    $st = ($days >= $warnDays) ? 'ok' : (($days >= $failDays) ? 'warn' : 'fail');
    return ['name'=>'TLS cert expiry','status'=>$st,'details'=> $days.' days'];
  }

  public static function check_security_updates(){
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

  public static function check_inodes_for($path){
    $path = $path ?: '/';
    $out = @shell_exec("df -i -P ".escapeshellarg($path)." 2>/dev/null");
    if (!$out) return ['name'=>'Inodes free','status'=>'warn','details'=>'unavailable'];
    $lines = preg_split('/\r?\n/', trim($out));
    if (count($lines) < 2) return ['name'=>'Inodes free','status'=>'warn','details'=>'unparsed'];
    $row = preg_split('/\s+/', trim($lines[1]));
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

  public static function scan_quick(){
    $res = [];

    $ver = PHP_VERSION;
    $ok_php = version_compare($ver, '8.0.0', '>=');
    $res[] = ['name'=>'PHP Version', 'status'=> $ok_php?'ok':'warn', 'details'=>$ver];

    $mem = ini_get('memory_limit');
    $memB = self::bytes_from_ini($mem);
    $ok_mem = ($memB===-1) ? true : ($memB >= 128*1024*1024);
    $res[] = ['name'=>'memory_limit', 'status'=> $ok_mem?'ok':'warn', 'details'=>$mem];

    $met = (int)ini_get('max_execution_time');
    $ok_met = ($met === 0 || $met >= 30);
    $res[] = ['name'=>'max_execution_time', 'status'=> $ok_met?'ok':'warn', 'details'=> (string)$met . 's'];

    $opc_enabled = (bool)ini_get('opcache.enable');
    $res[] = ['name'=>'OPcache', 'status'=> $opc_enabled?'ok':'warn', 'details'=> $opc_enabled?'enabled':'disabled'];

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? null) == 443);
    $res[] = ['name'=>'HTTPS', 'status'=> $https?'ok':'warn', 'details'=> $https?'on':'off'];

    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
    if (!empty($host)) { $res[] = self::check_tls_cert($host); }

    $root = dirname(__DIR__);
    $df = @disk_free_space($root);
    $dt = @disk_total_space($root);
    if ($df !== false && $dt) {
      $pct_free = $dt > 0 ? ($df / $dt) : 0;
      $res[] = ['name'=>'Disk Free', 'status'=> ($pct_free >= 0.10)?'ok':(($pct_free >= 0.05)?'warn':'fail'), 'details'=>sprintf('%.1f%% free', $pct_free*100)];
    } else {
      $res[] = ['name'=>'Disk Free', 'status'=>'warn', 'details'=>'unknown'];
    }

    $res[] = self::check_security_updates();

    $load = function_exists('sys_getloadavg') ? @sys_getloadavg() : null;
    if (is_array($load)) {
      $one = (float)$load[0];
      $res[] = ['name'=>'Load Avg (1m)', 'status'=> ($one < 4.0)?'ok':(($one < 8.0)?'warn':'fail'), 'details'=> (string)$one ];
    }

    return $res;
  }

  public static function score($items){
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
}
