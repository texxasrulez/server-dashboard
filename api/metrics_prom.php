<?php
require __DIR__.'/_guard.php'; guard_api(['key'=>'metrics_prom','require_token'=>true,'type':'text']);
// api/metrics_prom.php — Prometheus text exposition metrics for Server Dashboard
header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex');

$root = dirname(__DIR__);

// simple config reader (config/local.json)
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

function metric($name, $help=null, $type=null){
  if ($help) echo "# HELP {$name} {$help}\n";
  if ($type) echo "# TYPE {$name} {$type}\n";
}

$host = $_SERVER['HTTP_HOST'] ?? gethostname() ?: 'localhost';

$cores = 1;
if (function_exists('shell_exec')) {
  $out = @shell_exec('nproc 2>/dev/null');
  if ($out) $cores = (int)trim($out);
}
if ($cores <= 0) $cores = (int)($_SERVER['NUMBER_OF_PROCESSORS'] ?? 1);
if ($cores <= 0) $cores = 1;

$load = function_exists('sys_getloadavg') ? sys_getloadavg() : [0,0,0];
list($l1,$l5,$l15) = [floatval($load[0]??0), floatval($load[1]??0), floatval($load[2]??0)];
$disk_total = @disk_total_space('/') ?: 0;
$disk_free  = @disk_free_space('/') ?: 0;
$disk_free_pct = ($disk_total>0) ? ($disk_free / $disk_total * 100.0) : 0.0;

// services
$targets = cfg_get('server_tests.service_targets', []);
if (!is_array($targets)) $targets = [];
$timeout_ms = intval(cfg_get('server_tests.service_timeout_ms', 1000));
$timeout = max(0.1, $timeout_ms/1000.0);

metric('server_diag_up', '1 if metrics endpoint executed', 'gauge');
echo "server_diag_up 1\n";

metric('server_diag_load1', '1-minute load average', 'gauge');
echo "server_diag_load1{$labels} ", number_format($l1,2,'.',''), "\n";
metric('server_diag_load5', '5-minute load average', 'gauge');
echo "server_diag_load5 ", number_format($l5,2,'.',''), "\n";
metric('server_diag_load15', '15-minute load average', 'gauge');
echo "server_diag_load15 ", number_format($l15,2,'.',''), "\n";

metric('server_diag_cores', 'CPU core count', 'gauge');
echo "server_diag_cores {$cores}\n";

metric('server_diag_disk_free_bytes', 'Free disk bytes of root', 'gauge');
echo "server_diag_disk_free_bytes {$disk_free}\n";
metric('server_diag_disk_total_bytes', 'Total disk bytes of root', 'gauge');
echo "server_diag_disk_total_bytes {$disk_total}\n";
metric('server_diag_disk_free_percent', 'Free disk percent of root', 'gauge');
echo "server_diag_disk_free_percent ", number_format($disk_free_pct,2,'.',''), "\n";

metric('server_diag_service_up', 'Service is up (1) according to check', 'gauge');
metric('server_diag_service_ms', 'Service check time in ms', 'gauge');
metric('server_diag_service_http_code', 'HTTP status code for http(s) checks', 'gauge');
metric('server_diag_service_http_match', 'HTTP body contains expected substring (1=yes)', 'gauge');

foreach ($targets as $s){
  $s = trim((string)$s);
  if ($s === '') continue;
  $label=''; $spec=$s; $extra=[];
  $parts = array_map('trim', explode('|', $s));
  if (count($parts)>0) $spec = $parts[0];
  if (count($parts)>1) $label = $parts[1];
  if (count($parts)>2) {
    for ($i=2; $i<count($parts); $i++){
      if (strpos($parts[$i],'=')!==false){ list($k,$v)=array_map('trim', explode('=', $parts[$i],2)); $extra[strtolower($k)]=$v; }
    }
  }

  $method = 'tcp'; $code=0; $match=1; $ms=0; $up=0;
  if (stripos($spec,'http://')===0 || stripos($spec,'https://')===0){
    $method = (stripos($spec,'https://')===0)?'https':'http';
    $u = @parse_url($spec);
    if ($u && !empty($u['host'])){
      $host2 = $u['host'];
      $port = isset($u['port']) ? (int)$u['port'] : (($method==='https')?443:80);
      $path = ($u['path'] ?? '/'); if (!empty($u['query'])) $path.='?'.$u['query'];
      $expectCode = isset($extra['status']) ? (int)$extra['status'] : 200;
      $contains = isset($extra['contains']) ? (string)$extra['contains'] : '';
      $opts=['http'=>['method'=>'GET','timeout'=>$timeout,'ignore_errors'=>true,'header'=>"User-Agent: ServerDiag/1
Accept: */*
"]];
      if ($method==='https') $opts['ssl']=['verify_peer'=>true,'verify_peer_name'=>true,'SNI_enabled'=>true,'allow_self_signed'=>true];
      $ctx = stream_context_create($opts);
      $t0=microtime(true);
      $body=@file_get_contents($method."://".$host2.":".$port.$path, false, $ctx);
      $ms=(int)round((microtime(true)-$t0)*1000);
      if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/',$http_response_header[0],$m)) $code=(int)$m[1];
      $okCode = ($code === $expectCode);
      $okContains = ($contains==='') ? true : (is_string($body) && strpos($body,$contains)!==false);
      $up = ($okCode && $okContains) ? 1 : 0;
      $match = $okContains ? 1 : 0;
    }
  } else {
    // tcp
    $hp=$spec; $host2=$hp; $port=null;
    if (strpos($hp,':')!==false){ list($host2,$port)=explode(':',$hp,2); }
    $host2=trim($host2); $port=(int)trim((string)$port);
    $err=0; $errstr='';
    $t0=microtime(true);
    $fp=@stream_socket_client('tcp://'.$host2.':'.$port,$err,$errstr,$timeout);
    if ($fp){ $up=1; @fclose($fp); }
    $ms=(int)round((microtime(true)-$t0)*1000);
  }

  $lab = '{target="'.str_replace('"','"',$spec).'",label="'.str_replace('"','"',$label).'",method="'.$method.'"}';
  echo "server_diag_service_up{$lab} {$up}\n";
  echo "server_diag_service_ms{$lab} {$ms}\n";
  if ($method!=='tcp'){
    $lab2 = '{target="'.str_replace('"','"',$spec).'",label="'.str_replace('"','"',$label).'",method="'.$method.'"}';
    echo "server_diag_service_http_code{$lab2} {$code}\n";
    echo "server_diag_service_http_match{$lab2} {$match}\n";
  }
}

