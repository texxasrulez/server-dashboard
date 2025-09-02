<?php
require __DIR__.'/_guard.php'; guard_api(['key'=>'metrics_json','require_token'=>true,'type':'json']);
// api/metrics_json.php — JSON metrics for external monitoring
header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');

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

$cores = 1;
if (function_exists('shell_exec')) {
  $out = @shell_exec('nproc 2>/dev/null');
  if ($out) $cores = (int)trim($out);
}
if ($cores <= 0) $cores = (int)($_SERVER['NUMBER_OF_PROCESSORS'] ?? 1);
if ($cores <= 0) $cores = 1;

$load = function_exists('sys_getloadavg') ? sys_getloadavg() : [0,0,0];
$disk_total = @disk_total_space('/') ?: 0;
$disk_free  = @disk_free_space('/') ?: 0;
$disk_free_pct = ($disk_total>0) ? ($disk_free / $disk_total * 100.0) : 0.0;

$targets = cfg_get('server_tests.service_targets', []);
if (!is_array($targets)) $targets = [];
$timeout_ms = intval(cfg_get('server_tests.service_timeout_ms', 1000));
$timeout = max(0.1, $timeout_ms/1000.0);

$services = [];
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

  $method='tcp'; $ok=false; $ms=0; $code=null; $contains=null;
  if (stripos($spec,'http://')===0 || stripos($spec,'https://')===0){
    $method = (stripos($spec,'https://')===0)?'https':'http';
    $u = @parse_url($spec);
    if ($u && !empty($u['host'])){
      $host2=$u['host'];
      $port = isset($u['port']) ? (int)$u['port'] : (($method==='https')?443:80);
      $path = ($u['path'] ?? '/'); if (!empty($u['query'])) $path.='?'.$u['query'];
      $expectCode = isset($extra['status']) ? (int)$extra['status'] : 200;
      $needle = isset($extra['contains']) ? (string)$extra['contains'] : '';
      $opts=['http'=>['method'=>'GET','timeout'=>$timeout,'ignore_errors'=>true,'header'=>"User-Agent: ServerDiag/1
Accept: */*
"]];
      if ($method==='https') $opts['ssl']=['verify_peer'=>true,'verify_peer_name'=>true,'SNI_enabled'=>true,'allow_self_signed'=>true];
      $ctx = stream_context_create($opts);
      $t0=microtime(true);
      $body=@file_get_contents($method."://".$host2.":".$port.$path, false, $ctx);
      $ms=(int)round((microtime(true)-$t0)*1000);
      $sc=0; if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/',$http_response_header[0],$m)) $sc=(int)$m[1];
      $code=$sc;
      $okCode = ($sc === $expectCode);
      $okContains = ($needle==='') ? true : (is_string($body) && strpos($body,$needle)!==false);
      $ok = ($okCode && $okContains);
      $contains = ($needle==='') ? null : $okContains;
    }
  } else {
    $hp=$spec; $host2=$hp; $port=null;
    if (strpos($hp,':')!==false){ list($host2,$port)=explode(':',$hp,2); }
    $host2=trim($host2); $port=(int)trim((string)$port);
    $err=0; $errstr='';
    $t0=microtime(true);
    $fp=@stream_socket_client('tcp://'.$host2.':'.$port,$err,$errstr,$timeout);
    if ($fp){ $ok=true; @fclose($fp); }
    $ms=(int)round((microtime(true)-$t0)*1000);
  }

  $services[] = [
    'spec'=>$spec,
    'label'=>$label,
    'method'=>$method,
    'ok'=>$ok,
    'ms'=>$ms,
    'code'=>$code,
    'contains'=>$contains
  ];
}

echo json_encode([
  'time'=>date('c'),
  'host'=>($_SERVER['HTTP_HOST'] ?? gethostname() ?: 'localhost'),
  'cores'=>$cores,
  'load'=>['1m'=>floatval($load[0]??0), '5m'=>floatval($load[1]??0), '15m'=>floatval($load[2]??0)],
  'disk'=>['free_bytes'=>$disk_free, 'total_bytes'=>$disk_total, 'free_pct'=>floatval(number_format($disk_free_pct,2,'.',''))],
  'services'=>$services
]);
