<?php require_once __DIR__.'/_state_path.php'; ?>
<?php
require_once __DIR__ . '/../includes/init.php';
header('Content-Type: application/json');

function microtime_ms() { return (int)floor(microtime(true)*1000); }
function check_tcp($host,$port,$timeout=2){ $start=microtime_ms(); $fp=@fsockopen($host,(int)$port,$errno,$errstr,$timeout); $lat=microtime_ms()-$start; if(!$fp) return ['status'=>'down','latency_ms'=>$lat,'error'=>"$errno $errstr"]; fclose($fp); return ['status'=>'up','latency_ms'=>$lat]; }
function check_http($host,$port,$path='/',$timeout=3,$tls=false){
  $scheme=$tls?'https':'http'; $url=$scheme.'://'.$host.':'.$port.($path?:'/');
  $start=microtime_ms();
  $ctx=stream_context_create(['http'=>['method'=>'GET','timeout'=>$timeout,'ignore_errors'=>true,'header'=>"Connection: close\r\nUser-Agent: Dashboard\r\n"]]);
  $data=@file_get_contents($url,false,$ctx);
  $lat=microtime_ms()-$start;
  $code=null;
  if(isset($http_response_header) && is_array($http_response_header) && isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#',$http_response_header[0],$m)) $code=(int)$m[1];
  $status = ($code && $code<500) ? 'up' : 'down';
  return ['status'=>$status,'latency_ms'=>$lat,'http_code'=>$code];
}
function check_ping($host,$timeout=1){ $cmd=sprintf('ping -c 1 -W %d %s 2>/dev/null',$timeout,escapeshellarg($host)); $start=microtime_ms(); @exec($cmd,$o,$code); $lat=microtime_ms()-$start; return ['status'=>$code===0?'up':'down','latency_ms'=>$lat]; }

$dataPath = __DIR__ . '/../data/services.json';
$statePath = dashboard_state_path('services_status.json');
@mkdir(dirname($statePath), 0775, true);

$store = json_decode(@file_get_contents($dataPath), true) ?: ['items'=>[]];
$items = $store['items'] ?? [];

$res = [];
foreach ($items as $it) {
  if (empty($it['enabled'])) continue;
  $check = $it['check'] ?? 'tcp';
  $timeout = max(1, (int)ceil(($it['timeout_ms'] ?? 800)/1000));
  if ($check==='http') $r = check_http($it['host']??'', (int)($it['port']??0), $it['path']??'/', $timeout);
  else if ($check==='ping') $r = check_ping($it['host']??'', $timeout);
  else $r = check_tcp($it['host']??'', (int)($it['port']??0), $timeout);
  if (($r['status']??'')==='up' && ($r['latency_ms']??0) > ($it['timeout_ms']??800)) $r['status']='warn';
  $r['id'] = $it['id'];
  $r['ts'] = time();
  $res[] = $r;
}

file_put_contents($statePath, json_encode(['results'=>$res,'ts'=>time()], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), LOCK_EX);

// append per-result history line for trend charts
$histFile = dashboard_state_path('services_status_history.jsonl');
$fh = @fopen($histFile, 'ab');
if ($fh) {
  foreach ($res as $row) {
  if (!isset($row['ts'])) { $row['ts'] = time(); }
  $line = json_encode($row, JSON_UNESCAPED_SLASHES) . "
";
  @fwrite($fh, $line);
}
@fclose($fh);
}
echo json_encode(['results'=>$res,'ts'=>time()]);
