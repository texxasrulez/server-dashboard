<?php
require_once __DIR__ . '/../includes/init.php';
header('Content-Type: application/json');

function read_services_store() {
  $path = __DIR__ . '/../data/services.json';
  if (!file_exists($path)) return ['items'=>[]];
  $data = json_decode(@file_get_contents($path), true);
  return is_array($data) ? $data : ['items'=>[]];
}

function get_cpu_cores(){
  $n = 0;
  $f = @file('/proc/cpuinfo', FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
  if ($f) foreach ($f as $ln) if (stripos($ln, 'processor') === 0) $n++;
  if ($n <= 0) $n = 1;
  return $n;
}

function get_uptime_seconds() {
  $f = @file('/proc/uptime');
  if ($f && isset($f[0])) { $parts = explode(' ', trim($f[0])); return (int)floor((float)$parts[0]); }
  return null;
}

function get_loadavg() {
  $l = @sys_getloadavg(); if ($l && count($l) === 3) return [$l[0], $l[1], $l[2]];
  return [0,0,0];
}

function get_meminfo() {
  $info = @file('/proc/meminfo', FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
  $kv = [];
  if ($info) {
    foreach ($info as $line) if (preg_match('/^(\w+):\s+(\d+) kB$/', $line, $m)) $kv[$m[1]] = (int)$m[2] * 1024;
  }
  $total = $kv['MemTotal'] ?? 0;
  $avail = $kv['MemAvailable'] ?? 0;
  $used  = $total > 0 ? max(0, $total - $avail) : 0;
  return [
    'total'=>$total,
    'available'=>$avail,
    'used'=>$used,
    'buffers'=>$kv['Buffers'] ?? 0,
    'cached'=>$kv['Cached'] ?? 0
  ];
}

function df_parse() {
  $out = @shell_exec('df -P -B1 2>/dev/null');
  $rows = [];
  if ($out) {
    $lines = explode("\n", trim($out));
    array_shift($lines);
    foreach ($lines as $ln) {
      $ln = preg_replace('/\s+/', ' ', trim($ln));
      if (!$ln) continue;
      $parts = explode(' ', $ln);
      if (count($parts) >= 6) {
        $rows[] = ['fs'=>$parts[0], 'total'=>(int)$parts[1], 'used'=>(int)$parts[2], 'avail'=>(int)$parts[3], 'pct'=>rtrim($parts[4], '%'), 'mount'=>$parts[5]];
      }
    }
  }
  return $rows;
}

function get_disks() {
  $rows = df_parse();
  if ($rows) {
    $out = [];
    foreach ($rows as $r) $out[] = ['mount'=>$r['mount'], 'total'=>(int)$r['total'], 'used'=>(int)$r['used'], 'free'=>(int)$r['avail'], 'pct_used'=>(float)$r['pct']];
    return $out;
  }
  $total = @disk_total_space('/'); $free = @disk_free_space('/');
  if ($total && $free !== false) {
    $used = $total - $free;
    return [['mount'=>'/','total'=>$total,'used'=>$used,'free'=>$free,'pct_used'=>$total>0? round($used/$total*100,1):0]];
  }
  return [];
}

function microtime_ms() { return (int)floor(microtime(true)*1000); }
function check_tcp($host,$port,$timeout=2){ $start=microtime_ms(); $fp=@fsockopen($host,(int)$port,$errno,$errstr,$timeout); $lat=microtime_ms()-$start; if(!$fp) return ['status'=>'down','latency_ms'=>$lat]; fclose($fp); return ['status'=>'up','latency_ms'=>$lat]; }
function check_http($host,$port,$path='/',$timeout=3,$tls=false){ $scheme=$tls?'https':'http'; $url=$scheme.'://'.$host.':'.$port.($path?:'/'); $start=microtime_ms(); $ctx=stream_context_create(['http'=>['method'=>'GET','timeout'=>$timeout,'ignore_errors'=>true,'header'=>"Connection: close\r\nUser-Agent: Dashboard\r\n"]]); $data=@file_get_contents($url,false,$ctx); $lat=microtime_ms()-$start; $code=null; if(isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#',$http_response_header[0],$m)) $code=(int)$m[1]; $ok=($code && $code>=200 && $code<400); return ['status'=>$ok?'up':'down','latency_ms'=>$lat,'http_code'=>$code]; }
function check_ping($host,$timeout=1){ $cmd=sprintf('ping -c 1 -W %d %s 2>/dev/null',$timeout,escapeshellarg($host)); $start=microtime_ms(); @exec($cmd,$o,$code); $lat=microtime_ms()-$start; return ['status'=>$code===0?'up':'down','latency_ms'=>$lat]; }

function annotate_service($svc){
  $host=$svc['host']??'127.0.0.1'; $port=(int)($svc['port']??80); $check=strtolower($svc['check']??'tcp'); $path=$svc['path']??'/'; $enabled=!empty($svc['enabled']);
  $res=['id'=>$svc['id']??null,'name'=>$svc['name']??'','host'=>$host,'port'=>$port,'check'=>$check,'enabled'=>$enabled];
  if(!$enabled){ $res['status']='down'; $res['latency_ms']=null; return $res; }
  if($check==='http') $r=check_http($host,$port,$path);
  else if($check==='ping') $r=check_ping($host);
  else $r=check_tcp($host,$port);
  $res=array_merge($res,$r);
  if(($res['status']??'')==='up' && ($res['latency_ms']??0)>800) $res['status']='warn';
  return $res;
}

$svc_store = read_services_store();
$annotated = array_map('annotate_service', $svc_store['items'] ?? []);

echo json_encode([
  'generated_at'=>date('c'),
  'cpu'=>['cores'=>get_cpu_cores()],
  'uptime_sec'=>get_uptime_seconds(),
  'loadavg'=>get_loadavg(),
  'memory'=>get_meminfo(),
  'disks'=>get_disks(),
  'services'=>$annotated
]);
